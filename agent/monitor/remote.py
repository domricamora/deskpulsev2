"""Remote desktop control — agent side.

Runs a daemon thread that, when idle, polls the server every few seconds for a
pending control session. When one is granted, it captures the primary screen as
JPEG and streams frames to the server; the server's response carries queued input
commands which are applied locally via ``inject``. Frames and polls are sent
directly (they bypass the offline queue — a control stream must be live or dropped,
never replayed later).

The GUI reads ``state()`` (thread-safe) to show the visible "remote control
active" indicator; this thread only sets flags — it never touches Qt widgets.
"""
import threading
import time

from . import inject
from .screenshot import capture_primary_jpeg


class RemoteController:
    IDLE_POLL_S = 3      # how often to ask for a pending session when idle
    MAX_FRAME_ERRORS = 5  # consecutive frame failures before dropping back to idle

    def __init__(self, client, config):
        self.client = client
        self.config = config
        self._stop = threading.Event()
        self._thread = None
        self._lock = threading.Lock()
        self._active = False
        self._session_id = None

    # ── lifecycle ──
    def start(self):
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()
        with self._lock:
            sid = self._session_id
            self._active = False
            self._session_id = None
        if sid is not None:
            try:
                self.client.post_bytes(f"/webhooks/remote/{sid}/end", b"")
            except Exception:
                pass

    def state(self) -> dict:
        with self._lock:
            return {"active": self._active, "session_id": self._session_id}

    def _set(self, active, sid):
        with self._lock:
            self._active = active
            self._session_id = sid

    # ── loop ──
    def _run(self):
        errors = 0
        while not self._stop.is_set():
            try:
                resp = self.client.get_json("/webhooks/remote/poll")
                errors = 0
            except Exception:
                errors += 1
                self._stop.wait(min(self.IDLE_POLL_S * (errors + 1), 15))
                continue
            sess = (resp or {}).get("session")
            if not sess:
                self._stop.wait(self.IDLE_POLL_S)
                continue
            self._session(sess)
        self._set(False, None)

    def _session(self, sess):
        sid = sess.get("id")
        fps = max(1, int(sess.get("fps") or 3))
        max_width = int(sess.get("max_width") or 1280)
        quality = int(sess.get("quality") or 55)
        interval = 1.0 / fps
        screen_w, screen_h = inject.screen_size()
        self._set(True, sid)
        errors = 0
        try:
            while not self._stop.is_set():
                t0 = time.time()
                try:
                    img = capture_primary_jpeg(max_width=max_width, quality=quality)
                    resp = self.client.post_bytes(
                        f"/webhooks/remote/{sid}/frame", img,
                        query=f"w={screen_w}&h={screen_h}")
                    data = resp.json()
                    errors = 0
                    if data.get("status") == "ended":
                        break
                    for cmd in (data.get("commands") or []):
                        try:
                            inject.apply(cmd, screen_w, screen_h)
                        except Exception:
                            pass
                except Exception:
                    errors += 1
                    if errors >= self.MAX_FRAME_ERRORS:
                        break
                dt = time.time() - t0
                if dt < interval:
                    self._stop.wait(interval - dt)
        finally:
            self._set(False, None)
