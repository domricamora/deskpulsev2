"""Generate agent/packaging/icon.ico — a simple DeskPulse mark.

Run as part of the build (build.ps1 calls it). Keeps the repo free of binary
assets while still giving the exe/installer a real multi-resolution icon.
"""
import os

from PIL import Image, ImageDraw


def make(path: str) -> None:
    size = 256
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    # Outer rounded square (brand blue) + inner white pulse dot.
    d.rounded_rectangle([16, 16, size - 16, size - 16], radius=48, fill=(79, 124, 255, 255))
    d.ellipse([96, 96, 160, 160], fill=(255, 255, 255, 255))
    # A little "pulse" ring.
    d.ellipse([64, 64, 192, 192], outline=(255, 255, 255, 120), width=8)
    img.save(path, format="ICO", sizes=[(16, 16), (32, 32), (48, 48), (64, 64),
                                        (128, 128), (256, 256)])


if __name__ == "__main__":
    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "icon.ico")
    make(out)
    print("Wrote", out)
