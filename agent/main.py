"""DeskPulse desktop agent entry point.

Creates the Qt application, enforces a single running instance, shows the login
window (first run) or the main window, and provides a system-tray icon so the app
keeps tracking when the window is closed. Quitting cleanly stops any active
session so the server receives final active/inactive totals.

Run from the repo root:  python -m agent.main
"""
import os
import sys

# Allow running as a plain script (python agent/main.py) in addition to
# `python -m agent.main`. When launched as a script there's no package context,
# so put the repo root on sys.path and declare our package for relative imports.
if __package__ in (None, ""):
    sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    __package__ = "agent"

from PySide6.QtCore import QSharedMemory, Qt, QTimer
from PySide6.QtGui import QAction, QColor, QIcon, QPainter, QPixmap
from PySide6.QtWidgets import (QApplication, QMenu, QMessageBox, QSystemTrayIcon)

from . import theme
from .config import Config
from .monitor.remote import RemoteController
from .ui.login_window import LoginWindow
from .ui.main_window import MainWindow
from .webhook_client import WebhookClient


def make_icon(color: str = "#3b82f6") -> QIcon:
    """Draw a simple circular tray/app icon (brand blue, teal center dot)."""
    pm = QPixmap(64, 64)
    pm.fill(Qt.transparent)
    p = QPainter(pm)
    p.setRenderHint(QPainter.Antialiasing)
    p.setBrush(QColor(color))
    p.setPen(Qt.NoPen)
    p.drawEllipse(8, 8, 48, 48)
    p.setBrush(QColor("#2dd4bf"))
    p.drawEllipse(26, 26, 12, 12)
    p.end()
    return QIcon(pm)


class DeskPulseApp:
    def __init__(self, app: QApplication):
        self.app = app
        self.config = Config()
        self.icon = make_icon()
        app.setWindowIcon(self.icon)
        app.setApplicationName("DeskPulse")

        self.main_window = None
        self.login_window = None

        # Remote desktop control (super-admin initiated). A background controller
        # polls the server; a GUI-thread timer mirrors its state onto the tray so
        # the worker always sees when control is active.
        self.remote = None
        self._remote_active = False
        self._remote_timer = None

        self.tray = QSystemTrayIcon(self.icon)
        self.tray.setToolTip("DeskPulse")
        self._build_tray_menu()
        self.tray.show()
        self.tray.activated.connect(self._on_tray_activated)

        self._start()

    def _start(self):
        """Decide login vs main on launch: verify the saved device/account still
        exists and is valid. Force re-login only on an explicit 401 (revoked device
        or deleted account); tolerate offline / server errors."""
        if not self.config.is_registered:
            self.show_login()
            return
        try:
            import requests
            client = WebhookClient(self.config["server_url"],
                                   self.config["device_id"], self.config["secret"])
            me = client.get_me()
            self.config["user_name"] = (me.get("user") or {}).get("name") or self.config.get("user_name")
            self.config.save()
            self.show_main()
        except requests.HTTPError as exc:
            if exc.response is not None and exc.response.status_code in (401, 403):
                self.config.logout()          # device revoked / account removed
                QMessageBox.information(None, "DeskPulse",
                                        "You've been signed out. Please sign in again.")
                self.show_login()
            else:
                self.show_main()              # transient server error — proceed
        except Exception:
            self.show_main()                  # offline — allow working; data queues

    # ── windows ──
    def show_login(self):
        self.login_window = LoginWindow(self.config)
        self.login_window.logged_in.connect(self._on_logged_in)
        self.login_window.show()
        self.login_window.raise_()
        self.login_window.activateWindow()

    def _on_logged_in(self):
        if self.login_window:
            self.login_window.close()
        self.show_main()

    def show_main(self):
        if self.main_window is None:
            self._ensure_remote()
            self.main_window = MainWindow(self.config, self.logout, remote=self.remote)
        self.main_window.showNormal()   # restore if minimized/hidden to tray
        self.main_window.raise_()
        self.main_window.activateWindow()

    # ── remote control ──
    def _ensure_remote(self):
        """Start the remote-control poller (once) and a GUI-thread state mirror."""
        if self.remote is not None:
            return
        try:
            client = WebhookClient(self.config["server_url"],
                                   self.config["device_id"], self.config["secret"])
            self.remote = RemoteController(client, self.config)
            self.remote.start()
            self._remote_timer = QTimer()
            self._remote_timer.timeout.connect(self._poll_remote)
            self._remote_timer.start(1000)
        except Exception:
            self.remote = None

    def _poll_remote(self):
        """Runs in the GUI thread: reflect the controller's active flag on the tray."""
        if not self.remote:
            return
        try:
            active = bool(self.remote.state().get("active"))
        except Exception:
            active = False
        if active == self._remote_active:
            return
        self._remote_active = active
        if active:
            self.tray.setIcon(make_icon("#e5484d"))
            self.tray.setToolTip("DeskPulse — REMOTE CONTROL ACTIVE")
            try:
                self.tray.showMessage(
                    "DeskPulse",
                    "Remote control active — a DeskPulse administrator is controlling this computer.",
                    self.icon)
            except Exception:
                pass
        else:
            self.tray.setIcon(self.icon)
            self.tray.setToolTip("DeskPulse")

    def _stop_remote(self):
        if self._remote_timer:
            self._remote_timer.stop()
            self._remote_timer = None
        if self.remote:
            self.remote.stop()
            self.remote = None
        self._remote_active = False
        self.tray.setIcon(self.icon)
        self.tray.setToolTip("DeskPulse")

    def logout(self):
        """Sign out: stop tracking, clear saved credentials, return to login."""
        self._stop_remote()
        if self.main_window:
            self.main_window.shutdown()
            self.main_window.close()
            self.main_window = None
        self.config.logout()
        self.show_login()

    # ── tray ──
    def _build_tray_menu(self):
        menu = QMenu()
        show = QAction("Open DeskPulse", menu)
        show.triggered.connect(self.show_main)
        signout = QAction("Sign out", menu)
        signout.triggered.connect(self.logout)
        quit_action = QAction("Quit", menu)
        quit_action.triggered.connect(self.quit)
        menu.addAction(show)
        menu.addSeparator()
        menu.addAction(signout)
        menu.addAction(quit_action)
        self.tray.setContextMenu(menu)

    def _on_tray_activated(self, reason):
        if reason == QSystemTrayIcon.Trigger:
            self.show_main()

    def quit(self):
        if self.main_window and self.main_window.is_tracking():
            res = QMessageBox.question(
                self.main_window, "Stop tracking?",
                "A tracking session is active. Stop it and quit?")
            if res != QMessageBox.Yes:
                return
        self._stop_remote()
        if self.main_window:
            self.main_window.shutdown()
        self.tray.hide()
        self.app.quit()


def main():
    # On Windows, declare an explicit AppUserModelID so the app gets its own
    # taskbar button and icon instead of being grouped under python.exe.
    if sys.platform == "win32":
        try:
            import ctypes
            ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID("DeskPulse.Agent.1")
        except Exception:
            pass

    app = QApplication(sys.argv)
    app.setQuitOnLastWindowClosed(False)  # keep running in the tray
    app.setStyleSheet(theme.DARK_QSS)     # dark blue/teal theme, matches the web brand

    # Single-instance guard.
    shared = QSharedMemory("DeskPulse-singleton")
    if not shared.create(1):
        QMessageBox.information(None, "DeskPulse", "DeskPulse is already running.")
        return 0

    if not QSystemTrayIcon.isSystemTrayAvailable():
        # Still run, but warn — tray is how the app stays accessible.
        pass

    _ = DeskPulseApp(app)  # keep reference alive via closure in event loop
    app._deskpulse = _     # prevent GC
    return app.exec()


if __name__ == "__main__":
    sys.exit(main())
