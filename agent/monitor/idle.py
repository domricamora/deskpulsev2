"""Keyboard/mouse activity counting and inactivity detection.

Listeners run in their own threads and bump counters on real human input — a
keystroke, click, scroll, or mouse movement. Injected/synthetic input (SendInput)
is detected on Windows via the OS INJECTED flag (``winject``) and discarded in the
listener callbacks, so emulated input never counts.

The tracker calls ``snapshot()`` once per interval to read the counts (and the
per-interval ``genuine`` flag used for the activity %), and ``seconds_since_input()``
to decide active vs inactive: time is **active** until the worker has been quiet
for the full idle threshold (default 15 min), then **inactive/idle**.
"""
import threading
import time

from pynput import keyboard, mouse

from . import winject


class ActivityMonitor:
    def __init__(self, idle_threshold_s: int = 900):
        self.idle_threshold_s = idle_threshold_s
        self._lock = threading.Lock()
        self._kb = 0
        self._clicks = 0
        self._scrolls = 0
        self._moves = 0
        self._last_genuine = time.time()
        self._kb_listener = None
        self._ms_listener = None

    # ── lifecycle ──
    def start(self) -> None:
        self._last_genuine = time.time()
        winject.start()   # best-effort synthetic-input detector (Windows)
        self._kb_listener = keyboard.Listener(on_press=self._on_key)
        self._ms_listener = mouse.Listener(
            on_move=self._on_move, on_click=self._on_click, on_scroll=self._on_scroll)
        self._kb_listener.start()
        self._ms_listener.start()

    def stop(self) -> None:
        for listener in (self._kb_listener, self._ms_listener):
            if listener:
                listener.stop()
        self._kb_listener = self._ms_listener = None

    # ── callbacks (injected events are dropped) ──
    def _on_key(self, key):
        if winject.injected_active():
            return
        with self._lock:
            self._kb += 1
            self._last_genuine = time.time()

    def _on_click(self, x, y, button, pressed):
        if not pressed or winject.injected_active():
            return
        with self._lock:
            self._clicks += 1
            self._last_genuine = time.time()

    def _on_scroll(self, x, y, dx, dy):
        if winject.injected_active():
            return
        with self._lock:
            self._scrolls += 1
            self._last_genuine = time.time()

    def _on_move(self, x, y):
        if winject.injected_active():
            return
        with self._lock:
            self._moves += 1
            self._last_genuine = time.time()

    # ── reads ──
    def seconds_since_input(self) -> float:
        with self._lock:
            return time.time() - self._last_genuine

    def is_idle(self) -> bool:
        return self.seconds_since_input() >= self.idle_threshold_s

    def snapshot(self) -> dict:
        """Counts since the last snapshot (reset), plus whether the interval had
        genuine human activity. ``genuine=False`` means the interval is inactive."""
        with self._lock:
            kb, clicks, scrolls, moves = self._kb, self._clicks, self._scrolls, self._moves
            self._kb = self._clicks = self._scrolls = self._moves = 0

        # Any real keyboard input or mouse activity (moves included) makes the
        # interval active. Synthetic/injected input was already dropped in the
        # listener callbacks via winject, so this counts only genuine human use.
        genuine = (kb > 0 or clicks > 0 or scrolls > 0 or moves > 0)
        if genuine:
            with self._lock:
                self._last_genuine = time.time()

        return {"keyboard": kb, "mouse": clicks + scrolls + moves, "genuine": genuine}
