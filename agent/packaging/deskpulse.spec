# -*- mode: python ; coding: utf-8 -*-
"""PyInstaller spec for the DeskPulse agent.

Builds a **onedir** bundle (a folder with DeskPulse.exe + dependencies). Onedir is
deliberately preferred over onefile: it unpacks nothing to %TEMP% at launch, starts
faster, and triggers far fewer antivirus / SmartScreen false positives than a
self-extracting onefile binary. The Inno Setup script packages this folder into a
proper installer.

Build from the repo root:
    pyinstaller agent/packaging/deskpulse.spec --noconfirm
"""
import os

block_cipher = None

HERE = os.path.abspath(os.path.join(SPECPATH))          # agent/packaging
ICON = os.path.join(HERE, "icon.ico")
MANIFEST = os.path.join(HERE, "deskpulse.manifest")
VERSION = os.path.join(HERE, "version_info.txt")

# pynput, win32 and mss load some backends dynamically — pin them so PyInstaller
# doesn't miss them.
hiddenimports = [
    "pynput.keyboard._win32", "pynput.mouse._win32",
    "win32gui", "win32process", "win32api", "win32con",
    "mss", "PIL.Image", "PIL.ImageFilter",
]

REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
ASSETS = os.path.join(REPO, "agent", "assets")

a = Analysis(
    [os.path.join(REPO, "run_deskpulse.py")],   # launcher → collects the agent package
    pathex=[REPO],
    binaries=[],
    datas=[(ASSETS, "agent/assets")],   # bundled logo/favicon SVGs (see config.resource_path)
    hiddenimports=hiddenimports,
    hookspath=[],
    runtime_hooks=[],
    excludes=["tkinter", "matplotlib", "numpy", "pytest"],
    cipher=block_cipher,
    noarchive=False,
)
pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name="DeskPulse",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,                       # UPX-packed exes are commonly flagged by AV
    console=False,                   # GUI app, no console window
    icon=ICON if os.path.exists(ICON) else None,
    version=VERSION if os.path.exists(VERSION) else None,
    manifest=MANIFEST if os.path.exists(MANIFEST) else None,
)

coll = COLLECT(
    exe,
    a.binaries,
    a.zipfiles,
    a.datas,
    strip=False,
    upx=False,
    name="DeskPulse",
)
