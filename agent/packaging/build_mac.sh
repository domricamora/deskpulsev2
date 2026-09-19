#!/bin/bash
# DeskPulse macOS build — produces dist/DeskPulse.app and dist/DeskPulse-v1.dmg
# Run ON macOS (PyInstaller cannot build a Mac app from Windows):
#   chmod +x agent/packaging/build_mac.sh && ./agent/packaging/build_mac.sh
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO"
echo "==> Repo: $REPO"

# 1. venv + deps
if [ ! -d .venv ]; then
  echo "==> Creating virtualenv"
  python3 -m venv .venv
fi
PY="$REPO/.venv/bin/python"
"$PY" -m pip install --upgrade pip
"$PY" -m pip install -r requirements.txt

# 2. Icon: generate icon.png via Pillow, convert to .icns with iconutil.
echo "==> Generating icon"
"$PY" agent/packaging/make_icon.py        # writes icon.ico (and we derive png)
if command -v sips >/dev/null && command -v iconutil >/dev/null; then
  TMP="$(mktemp -d)/DeskPulse.iconset"; mkdir -p "$TMP"
  # Render a 1024px PNG from Pillow for crisp icons.
  "$PY" - <<'PY'
from PIL import Image, ImageDraw
img = Image.new("RGBA",(1024,1024),(0,0,0,0)); d=ImageDraw.Draw(img)
d.rounded_rectangle([64,64,960,960],radius=200,fill=(79,124,255,255))
d.ellipse([384,384,640,640],fill=(255,255,255,255))
d.ellipse([256,256,768,768],outline=(255,255,255,120),width=32)
img.save("agent/packaging/icon_1024.png")
PY
  for s in 16 32 64 128 256 512; do
    sips -z $s $s agent/packaging/icon_1024.png --out "$TMP/icon_${s}x${s}.png" >/dev/null
    sips -z $((s*2)) $((s*2)) agent/packaging/icon_1024.png --out "$TMP/icon_${s}x${s}@2x.png" >/dev/null
  done
  iconutil -c icns "$TMP" -o agent/packaging/icon.icns || true
fi

# 3. Build the .app
echo "==> Running PyInstaller"
"$PY" -m PyInstaller agent/packaging/deskpulse_mac.spec --noconfirm --clean

# 4. (Optional) sign — requires an Apple Developer ID certificate:
#   codesign --deep --force --options runtime \
#     --sign "Developer ID Application: YOUR NAME (TEAMID)" dist/DeskPulse.app

# 5. Package into a .dmg
echo "==> Building .dmg"
DMG="dist/DeskPulse-v1.dmg"
rm -f "$DMG"
if command -v create-dmg >/dev/null; then
  create-dmg --volname "DeskPulse" --app-drop-link 480 200 \
    --icon "DeskPulse.app" 160 200 "$DMG" "dist/DeskPulse.app"
else
  # Fallback: plain hdiutil image (drag-to-Applications still works).
  STAGE="$(mktemp -d)"; cp -R dist/DeskPulse.app "$STAGE/"; ln -s /Applications "$STAGE/Applications"
  hdiutil create -volname "DeskPulse" -srcfolder "$STAGE" -ov -format UDZO "$DMG"
fi
echo "==> Done: $DMG"
echo "    (Unsigned apps: right-click → Open the first time, or notarize for a clean launch.)"
