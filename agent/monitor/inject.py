"""Apply remote-control input commands to the local machine via pynput.

Commands arrive from the server as small dicts with normalized (0..1) mouse
coordinates. ``apply()`` maps them to pixels using the primary screen size and
dispatches mouse/keyboard actions. Used only during an admin remote-control
session; the worker sees a visible on-screen indicator the whole time.
"""
from pynput.keyboard import Controller as KeyboardController, Key
from pynput.mouse import Button, Controller as MouseController

_mouse = MouseController()
_keyboard = KeyboardController()

_BUTTONS = {"left": Button.left, "right": Button.right, "middle": Button.middle}

# Named keys (lowercased) → pynput Key. Single printable characters fall through
# to keyboard.press(<char>) directly.
_KEYMAP = {
    "enter": Key.enter, "return": Key.enter, "tab": Key.tab,
    "esc": Key.esc, "escape": Key.esc, "backspace": Key.backspace,
    "space": Key.space, "delete": Key.delete, "del": Key.delete,
    "up": Key.up, "down": Key.down, "left": Key.left, "right": Key.right,
    "arrowup": Key.up, "arrowdown": Key.down, "arrowleft": Key.left, "arrowright": Key.right,
    "home": Key.home, "end": Key.end, "pageup": Key.page_up, "pagedown": Key.page_down,
    "insert": Key.insert, "capslock": Key.caps_lock,
    "ctrl": Key.ctrl, "control": Key.ctrl, "alt": Key.alt, "shift": Key.shift,
    "cmd": Key.cmd, "meta": Key.cmd, "win": Key.cmd, "os": Key.cmd,
    "f1": Key.f1, "f2": Key.f2, "f3": Key.f3, "f4": Key.f4, "f5": Key.f5, "f6": Key.f6,
    "f7": Key.f7, "f8": Key.f8, "f9": Key.f9, "f10": Key.f10, "f11": Key.f11, "f12": Key.f12,
}


def screen_size():
    """Primary screen resolution in pixels. Prefers mss; falls back to the Win32
    API, then a sane default so mapping never divides by zero."""
    try:
        import mss
        with mss.mss() as sct:
            mons = sct.monitors
            m = mons[1] if len(mons) > 1 else mons[0]
            return int(m["width"]), int(m["height"])
    except Exception:
        pass
    try:
        import ctypes
        user32 = ctypes.windll.user32
        return int(user32.GetSystemMetrics(0)), int(user32.GetSystemMetrics(1))
    except Exception:
        return 1920, 1080


def _to_key(name):
    """Resolve a JS ``event.key`` name to a pynput Key / literal char."""
    if not name:
        return None
    low = str(name).lower()
    if low in _KEYMAP:
        return _KEYMAP[low]
    if len(name) == 1:
        return name  # printable literal
    return None


def apply(cmd, screen_w, screen_h):
    """Dispatch one input command. Unknown/malformed commands are ignored."""
    if not isinstance(cmd, dict):
        return
    t = cmd.get("t")

    if t in ("move", "down", "up", "click"):
        x, y = cmd.get("x"), cmd.get("y")
        if x is not None and y is not None:
            _mouse.position = (round(float(x) * screen_w), round(float(y) * screen_h))
        btn = _BUTTONS.get(cmd.get("button", "left"), Button.left)
        if t == "down":
            _mouse.press(btn)
        elif t == "up":
            _mouse.release(btn)
        elif t == "click":
            _mouse.click(btn, 1)

    elif t == "scroll":
        try:
            _mouse.scroll(0, int(cmd.get("dy", 0)))
        except (TypeError, ValueError):
            pass

    elif t == "key":
        action = cmd.get("action")
        if action == "type":
            text = cmd.get("text", "")
            if text:
                _keyboard.type(str(text))
        elif action == "press":
            key = _to_key(cmd.get("key"))
            if key is not None:
                _keyboard.press(key)
                _keyboard.release(key)
