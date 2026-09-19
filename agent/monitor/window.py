"""Active window/app detection.

Prefers pywin32 on Windows (gives both the window title and the owning process
name); falls back to pygetwindow's active-window title elsewhere.
"""
import os

_HAVE_WIN32 = False
if os.name == "nt":
    try:
        import psutil
        import win32gui
        import win32process
        _HAVE_WIN32 = True
    except Exception:
        _HAVE_WIN32 = False

if not _HAVE_WIN32:
    try:
        import pygetwindow as gw
    except Exception:
        gw = None


def active_window() -> dict:
    """Return {"app": process_or_app_name, "title": window_title}."""
    if _HAVE_WIN32:
        try:
            hwnd = win32gui.GetForegroundWindow()
            title = win32gui.GetWindowText(hwnd) or ""
            _, pid = win32process.GetWindowThreadProcessId(hwnd)
            app = ""
            try:
                app = psutil.Process(pid).name()
            except Exception:
                app = ""
            return {"app": app, "title": title}
        except Exception:
            return {"app": "", "title": ""}

    if gw is not None:
        try:
            win = gw.getActiveWindow()
            title = getattr(win, "title", "") or "" if win else ""
            # Best-effort app name from the title's trailing segment.
            app = title.split(" - ")[-1] if title else ""
            return {"app": app, "title": title}
        except Exception:
            return {"app": "", "title": ""}

    return {"app": "", "title": ""}
