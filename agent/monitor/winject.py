"""Best-effort detection of synthetic (injected) input on Windows.

Software mouse-jigglers and input emulators generate events with SendInput, which
Windows tags with the INJECTED flag on the low-level input hooks. We install
WH_MOUSE_LL / WH_KEYBOARD_LL hooks in a dedicated thread and remember the time of
the most recent injected event; the ActivityMonitor consults ``injected_active()``
to discard emulated input so it never counts as activity.

No-op (and safe) on non-Windows platforms or if hooks can't be installed.
"""
import sys
import threading
import time

_active = False
_last_injected = 0.0
_lock = threading.Lock()
# Keep hook callbacks referenced for the process lifetime so they aren't GC'd
# (which would crash the hook with an access violation).
_callbacks = []


def injected_active(window_s: float = 0.12) -> bool:
    """True if a synthetic/injected event was seen within the last window_s seconds."""
    with _lock:
        return _active and (time.time() - _last_injected) <= window_s


def start() -> bool:
    """Install the low-level hooks (Windows only). Idempotent; returns active state."""
    global _active
    if _active or sys.platform != "win32":
        return _active
    try:
        _install()
        _active = True
    except Exception:
        _active = False
    return _active


def _mark():
    global _last_injected
    with _lock:
        _last_injected = time.time()


def _install() -> None:
    import ctypes
    from ctypes import wintypes

    user32 = ctypes.WinDLL("user32", use_last_error=True)
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)

    WH_KEYBOARD_LL = 13
    WH_MOUSE_LL = 14
    LLKHF_INJECTED = 0x10
    LLMHF_INJECTED = 0x01

    ULONG_PTR = ctypes.c_ulonglong if ctypes.sizeof(ctypes.c_void_p) == 8 else ctypes.c_ulong
    LRESULT = ctypes.c_longlong if ctypes.sizeof(ctypes.c_void_p) == 8 else ctypes.c_long
    HOOKPROC = ctypes.CFUNCTYPE(LRESULT, ctypes.c_int, wintypes.WPARAM, wintypes.LPARAM)

    class KBDLLHOOKSTRUCT(ctypes.Structure):
        _fields_ = [("vkCode", wintypes.DWORD), ("scanCode", wintypes.DWORD),
                    ("flags", wintypes.DWORD), ("time", wintypes.DWORD),
                    ("dwExtraInfo", ULONG_PTR)]

    class MSLLHOOKSTRUCT(ctypes.Structure):
        _fields_ = [("pt", wintypes.POINT), ("mouseData", wintypes.DWORD),
                    ("flags", wintypes.DWORD), ("time", wintypes.DWORD),
                    ("dwExtraInfo", ULONG_PTR)]

    user32.SetWindowsHookExW.restype = wintypes.HHOOK
    user32.SetWindowsHookExW.argtypes = [ctypes.c_int, HOOKPROC, wintypes.HINSTANCE, wintypes.DWORD]
    user32.CallNextHookEx.restype = LRESULT
    user32.CallNextHookEx.argtypes = [wintypes.HHOOK, ctypes.c_int, wintypes.WPARAM, wintypes.LPARAM]
    user32.GetMessageW.argtypes = [ctypes.POINTER(wintypes.MSG), wintypes.HWND, wintypes.UINT, wintypes.UINT]

    def kb_proc(nCode, wParam, lParam):
        try:
            if nCode >= 0:
                info = ctypes.cast(lParam, ctypes.POINTER(KBDLLHOOKSTRUCT)).contents
                if info.flags & LLKHF_INJECTED:
                    _mark()
        except Exception:
            pass
        return user32.CallNextHookEx(None, nCode, wParam, lParam)

    def ms_proc(nCode, wParam, lParam):
        try:
            if nCode >= 0:
                info = ctypes.cast(lParam, ctypes.POINTER(MSLLHOOKSTRUCT)).contents
                if info.flags & LLMHF_INJECTED:
                    _mark()
        except Exception:
            pass
        return user32.CallNextHookEx(None, nCode, wParam, lParam)

    kb_cb = HOOKPROC(kb_proc)
    ms_cb = HOOKPROC(ms_proc)
    _callbacks.extend([kb_cb, ms_cb])

    ready = threading.Event()

    def _pump():
        hmod = kernel32.GetModuleHandleW(None)
        kh = user32.SetWindowsHookExW(WH_KEYBOARD_LL, kb_cb, hmod, 0)
        mh = user32.SetWindowsHookExW(WH_MOUSE_LL, ms_cb, hmod, 0)
        ready.set()
        if not kh and not mh:
            return
        msg = wintypes.MSG()
        while user32.GetMessageW(ctypes.byref(msg), None, 0, 0) > 0:
            user32.TranslateMessageW(ctypes.byref(msg))
            user32.DispatchMessageW(ctypes.byref(msg))

    threading.Thread(target=_pump, daemon=True).start()
    ready.wait(timeout=2)
