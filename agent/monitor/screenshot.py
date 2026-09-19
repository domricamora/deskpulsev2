"""Screenshot capture via mss, encoded to WebP with Pillow (optional blur).

Supports multi-monitor setups: each physical monitor is captured separately so
every screen the worker uses is recorded at full quality (rather than one tiny
stitched image). WebP gives much smaller files than PNG/JPEG at equivalent
quality, so it is the default wire/storage format to keep server disk usage down.
"""
import io

import mss
from PIL import Image, ImageFilter


def _physical_monitors(sct) -> list:
    """The individual physical monitors. mss exposes monitors[0] as the union
    bounding box of all screens and monitors[1:] as each physical display; fall
    back to the union if only one entry is present."""
    mons = sct.monitors
    return mons[1:] if len(mons) > 1 else mons[:1]


def _grab(sct, monitor: dict, max_width: int, blur: bool) -> Image.Image:
    raw = sct.grab(monitor)
    img = Image.frombytes("RGB", raw.size, raw.bgra, "raw", "BGRX")
    if img.width > max_width:
        ratio = max_width / img.width
        img = img.resize((max_width, int(img.height * ratio)))
    if blur:
        img = img.filter(ImageFilter.GaussianBlur(8))
    return img


def _to_webp(img: Image.Image, quality: int) -> bytes:
    buf = io.BytesIO()
    img.save(buf, format="WEBP", quality=quality, method=4)
    return buf.getvalue()


def capture_all_webp(blur: bool = False, max_width: int = 1600,
                     quality: int = 60) -> list:
    """Capture every physical monitor. Returns ``[(index, webp_bytes), ...]``
    with 1-based monitor indices (so a single-screen machine yields one item)."""
    shots = []
    with mss.mss() as sct:
        for index, monitor in enumerate(_physical_monitors(sct), start=1):
            try:
                shots.append((index, _to_webp(_grab(sct, monitor, max_width, blur), quality)))
            except Exception:
                continue  # skip a monitor that fails; capture the rest
    return shots


def capture_webp(blur: bool = False, max_width: int = 1600, quality: int = 60) -> bytes:
    """Primary monitor only, as compact WebP bytes (kept for compatibility)."""
    with mss.mss() as sct:
        return _to_webp(_grab(sct, _physical_monitors(sct)[0], max_width, blur), quality)


def capture_primary_jpeg(max_width: int = 1280, quality: int = 55) -> bytes:
    """Primary monitor as JPEG bytes — used for remote-control frame streaming.
    JPEG (not WebP) keeps per-frame encoding cheap for a live stream."""
    with mss.mss() as sct:
        img = _grab(sct, _physical_monitors(sct)[0], max_width, False)
    buf = io.BytesIO()
    img.save(buf, format="JPEG", quality=quality)
    return buf.getvalue()


def capture_png(blur: bool = False, max_width: int = 1600) -> bytes:
    """PNG fallback (kept for compatibility)."""
    with mss.mss() as sct:
        img = _grab(sct, _physical_monitors(sct)[0], max_width, blur)
    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    return buf.getvalue()
