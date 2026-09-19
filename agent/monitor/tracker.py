"""Tracker — orchestrates a work session in a background thread.

Each tick it samples activity, the active window, and (periodically) screenshots
and running tasks; it splits time into active vs inactive using the idle
threshold and records discrete idle periods. Batches are flushed to the server
every ``sync_interval_s`` and on stop. All network I/O tolerates being offline
(the WebhookClient queues failed sends).
"""
import threading
import time
from datetime import datetime, timezone

from .idle import ActivityMonitor
from .processes import running_apps
from .screenshot import capture_all_webp
from .window import active_window


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Tracker:
    SAMPLE_INTERVAL_S = 10

    def __init__(self, client, config, client_id=None, task_id=None):
        self.client = client
        self.config = config
        self.client_id = client_id
        self.task_id = task_id

        self.session_id = None
        self.running = False
        self.active_s = 0
        self.inactive_s = 0
        self.current = {"app": "", "title": "", "activity": 0}

        self._thread = None
        self._stop = threading.Event()
        # Effective settings start from local config; the admin-controlled server
        # policy overrides them when a session starts (see _apply_policy).
        self.settings = {
            "screenshot_interval_min": int(config["screenshot_interval_min"]),
            "idle_threshold_min": int(config["idle_threshold_min"]),
            "sync_interval_s": int(config["sync_interval_s"]),
            "screenshot_blur": bool(config["screenshot_blur"]),
            "track_screenshots": bool(config["track_screenshots"]),
            "track_windows": bool(config["track_windows"]),
            "track_processes": bool(config["track_processes"]),
        }
        self._activity = ActivityMonitor(
            idle_threshold_s=self.settings["idle_threshold_min"] * 60)

        # Pending batches awaiting sync.
        self._samples = []
        self._windows = []
        self._idle_periods = []
        self._idle_start = None  # iso str when an idle span began

    # ── lifecycle ──
    def _apply_policy(self) -> None:
        """Fetch the admin-controlled org policy and override local settings."""
        try:
            policy = self.client.get_policy()
            for key in self.settings:
                if key in policy and policy[key] is not None:
                    self.settings[key] = policy[key]
            self._activity.idle_threshold_s = int(self.settings["idle_threshold_min"]) * 60
        except Exception:
            pass  # offline / older server — keep local settings

    def start(self) -> None:
        """Open the session (requires connectivity) then begin sampling."""
        self._apply_policy()
        self.session_id = self.client.start_session(
            self.client_id, now_iso(), task_id=self.task_id)
        self._activity.start()
        self.running = True
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def set_task(self, task_id) -> None:
        """Change the task this session is attributed to (used mid-session)."""
        self.task_id = task_id
        if self.running and self.session_id:
            try:
                self.client.set_task(self.session_id, task_id)
            except Exception:
                pass  # offline — the queued activity keeps the last server task_id

    def stop(self) -> None:
        if not self.running:
            return
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=5)
        self._activity.stop()
        self.running = False
        # Close any open idle span.
        self._close_idle()
        self._flush()
        if self.session_id:
            self.client.stop_session(self.session_id, now_iso(),
                                     self.active_s, self.inactive_s)

    def status(self) -> dict:
        return {
            "running": self.running,
            "session_id": self.session_id,
            "active_s": self.active_s,
            "inactive_s": self.inactive_s,
            "current": dict(self.current),
        }

    # ── main loop ──
    def _run(self) -> None:
        last_sync = time.time()
        last_shot = 0.0
        last_proc = 0.0
        shot_interval = int(self.settings["screenshot_interval_min"]) * 60

        while not self._stop.is_set():
            self._stop.wait(self.SAMPLE_INTERVAL_S)
            if self._stop.is_set():
                break
            interval = self.SAMPLE_INTERVAL_S
            counts = self._activity.snapshot()
            genuine = counts.get("genuine", False)

            # Active vs inactive is decided by the admin's idle THRESHOLD (default
            # 15 min), not by whether this single 10s tick happened to have input.
            # A worker reading, watching a video or in a meeting is still "active"
            # until they've gone quiet for the whole threshold; only sustained
            # inactivity past it counts as inactive/idle. This matches the product
            # spec ("inactive = idle after 15 min") and also tolerates the
            # synthetic-input filter dropping the odd genuine event.
            idle = self._activity.seconds_since_input() >= self._activity.idle_threshold_s
            if not idle:
                self.active_s += interval
                self._close_idle()
            else:
                self.inactive_s += interval
                if self._idle_start is None:
                    self._idle_start = now_iso()

            pct = min(100, round((counts["keyboard"] + counts["mouse"])
                                 / (interval * 2) * 100)) if genuine else 0
            self.current["activity"] = pct
            self._samples.append({"ts": now_iso(), "keyboard": counts["keyboard"],
                                  "mouse": counts["mouse"], "pct": pct})

            # Active window.
            if self.settings["track_windows"]:
                win = active_window()
                self.current["app"] = win["app"]
                self.current["title"] = win["title"]
                if win["app"] or win["title"]:
                    self._windows.append({"ts": now_iso(), "app": win["app"],
                                          "title": win["title"],
                                          "focus_seconds": interval})

            # Screenshot (periodic). Captured on the interval whenever the worker
            # is present (not idle) — NOT gated on input in this exact tick, so a
            # quiet-but-active screen (reading, a call) is still captured.
            now = time.time()
            if (self.settings["track_screenshots"] and not idle
                    and now - last_shot >= shot_interval):
                self._capture_screenshot()
                last_shot = now

            # Processes (about once a minute).
            if self.settings["track_processes"] and now - last_proc >= 60:
                self._snapshot_processes()
                last_proc = now

            # Periodic sync (cadence set by the admin/IT monitoring policy).
            if now - last_sync >= int(self.settings["sync_interval_s"]):
                self._flush()
                last_sync = now

    # ── helpers ──
    def _close_idle(self) -> None:
        if self._idle_start is not None:
            self._idle_periods.append({"start": self._idle_start, "end": now_iso()})
            self._idle_start = None

    def _capture_screenshot(self) -> None:
        try:
            blur = bool(self.settings["screenshot_blur"])
            ts = now_iso()
            shots = capture_all_webp(blur=blur)   # one per physical monitor
            multi = len(shots) > 1
            for index, img in shots:
                # Tag the monitor index only when there's more than one screen.
                self.client.post_screenshot(self.session_id, img, "webp", ts, blur,
                                            monitor=index if multi else None)
        except Exception:
            pass

    def _snapshot_processes(self) -> None:
        try:
            ts = now_iso()
            self._pending_procs = [{"ts": ts, "app": p["app"], "pid": p["pid"]}
                                   for p in running_apps()]
        except Exception:
            self._pending_procs = []

    _pending_procs = []

    def _flush(self) -> None:
        if not self.session_id:
            return
        if self._samples:
            self.client.post_activity(self.session_id, self._samples,
                                      self.active_s, self.inactive_s)
            self._samples = []
        if self._windows or self._pending_procs:
            self.client.post_windows(self.session_id, self._windows, self._pending_procs)
            self._windows = []
            self._pending_procs = []
        if self._idle_periods:
            self.client.post_idle(self.session_id, self._idle_periods)
            self._idle_periods = []
        self.client.flush_queue()
