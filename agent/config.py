"""Local agent configuration, persisted to the user's app-data directory.

Stores the server URL, the registered device credentials (id + HMAC secret),
and monitoring preferences. Never committed — lives in %APPDATA%/DeskPulse.
"""
import json
import os
import sys
from pathlib import Path

# The agent always talks to the production server. This URL is locked and cannot
# be changed from the UI or an old config.json on disk.
SERVER_URL = "https://deskpulse.click"

# Repo root in dev, or the PyInstaller bundle root when frozen — bundled assets
# (agent/assets/*) are collected under the same relative path in both cases.
_BASE_DIR = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent.parent))


def resource_path(*parts: str) -> str:
    """Absolute path to a bundled resource, e.g. resource_path("agent", "assets", "logo.svg")."""
    return str(_BASE_DIR.joinpath(*parts))


def config_dir() -> Path:
    if os.name == "nt":
        base = os.environ.get("APPDATA", str(Path.home()))
    else:
        base = os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))
    d = Path(base) / "DeskPulse"
    d.mkdir(parents=True, exist_ok=True)
    return d


CONFIG_PATH = config_dir() / "config.json"
QUEUE_PATH = config_dir() / "queue.json"

DEFAULTS = {
    "server_url": SERVER_URL,
    "device_id": None,
    "secret": None,
    "user_name": None,
    # Monitoring preferences
    "screenshot_interval_min": 10,
    "screenshot_blur": False,
    "idle_threshold_min": 15,
    "track_screenshots": True,
    "track_windows": True,
    "track_processes": True,
    "sync_interval_s": 60,
    # Auto-start/stop tracking during the worker's scheduled hours (from the server).
    "auto_start_on_schedule": True,
    "work_start": None,      # "HH:MM:SS" (cached from the server for offline use)
    "work_end": None,        # "HH:MM:SS"
    "work_days": [],         # ISO weekdays, 1=Mon..7=Sun
}


class Config:
    def __init__(self):
        self.data = dict(DEFAULTS)
        self.load()

    def load(self) -> None:
        if CONFIG_PATH.exists():
            try:
                self.data.update(json.loads(CONFIG_PATH.read_text("utf-8")))
            except (ValueError, OSError):
                pass
        # The server URL is locked to production, regardless of what a stale
        # config.json on disk might carry over.
        self.data["server_url"] = SERVER_URL

    def save(self) -> None:
        CONFIG_PATH.write_text(json.dumps(self.data, indent=2), "utf-8")

    def __getitem__(self, key):
        if key == "server_url":
            return SERVER_URL
        return self.data.get(key, DEFAULTS.get(key))

    def __setitem__(self, key, value):
        self.data[key] = value

    def get(self, key, default=None):
        if key == "server_url":
            return SERVER_URL
        return self.data.get(key, default)

    @property
    def is_registered(self) -> bool:
        return bool(self.data.get("device_id") and self.data.get("secret"))

    def logout(self) -> None:
        """Clear the saved device credentials (sign out)."""
        for key in ("device_id", "secret", "user_name"):
            self.data[key] = None
        self.save()
