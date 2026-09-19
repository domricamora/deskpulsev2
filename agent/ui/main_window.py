"""Main agent window: pick a client/company, start/stop tracking, see live status."""
import webbrowser
from datetime import datetime

import requests
from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QPainter, QPixmap
from PySide6.QtSvg import QSvgRenderer
from PySide6.QtWidgets import (QCheckBox, QComboBox, QHBoxLayout, QInputDialog, QLabel,
                               QMessageBox, QPushButton, QVBoxLayout, QWidget)

from ..config import resource_path
from ..monitor.tracker import Tracker
from ..webhook_client import WebhookClient


def render_svg(path: str, height: int) -> QPixmap:
    """Rasterize a bundled SVG (e.g. the DeskPulse logo) to a QPixmap at a
    given pixel height, preserving aspect ratio. Returns a null pixmap if the
    file is missing rather than raising, so branding is best-effort."""
    renderer = QSvgRenderer(path)
    if not renderer.isValid():
        return QPixmap()
    size = renderer.defaultSize()
    if size.height() <= 0:
        return QPixmap()
    scale = height / size.height()
    width = max(1, round(size.width() * scale))
    pm = QPixmap(width, height)
    pm.fill(Qt.transparent)
    painter = QPainter(pm)
    renderer.render(painter)
    painter.end()
    return pm


def fmt_hms(seconds: int) -> str:
    seconds = int(seconds)
    h, rem = divmod(seconds, 3600)
    m = rem // 60
    return f"{h}h {m:02d}m" if h else f"{m}m"


class MainWindow(QWidget):
    def __init__(self, config, on_logout=None, remote=None):
        super().__init__()
        self.config = config
        self.on_logout = on_logout
        self.remote = remote
        self.tracker = None
        self.client = WebhookClient(config["server_url"], config["device_id"], config["secret"])
        # Schedule-driven auto tracking. _auto_started marks a session the schedule
        # started (so only those auto-stop); _was_in_window makes the start/stop
        # edge-triggered — we start once when the window opens, not repeatedly.
        self._auto_started = False
        self._was_in_window = False
        self._schedule = {
            "work_start": config.get("work_start"),
            "work_end": config.get("work_end"),
            "work_days": config.get("work_days") or [],
        }

        self.setWindowTitle("DeskPulse")
        self.setMinimumWidth(420)

        # Prominent banner shown whenever an admin is remote-controlling this
        # machine (hidden otherwise). Toggled from the GUI thread in _refresh().
        self.remote_banner = QLabel(
            "● Remote control active — a DeskPulse administrator is controlling this computer")
        self.remote_banner.setWordWrap(True)
        self.remote_banner.setStyleSheet(
            "background:#e5484d;color:#ffffff;padding:8px 10px;border-radius:6px;font-weight:600;")
        self.remote_banner.hide()

        # Always-on-top indicator so the worker sees the warning even when the
        # main window is closed to the tray.
        self.remote_indicator = QWidget(
            None, Qt.FramelessWindowHint | Qt.WindowStaysOnTopHint | Qt.Tool)
        self.remote_indicator.setAttribute(Qt.WA_ShowWithoutActivating, True)
        _ind = QVBoxLayout(self.remote_indicator)
        _ind.setContentsMargins(0, 0, 0, 0)
        _ind_lbl = QLabel("● Remote control active")
        _ind_lbl.setStyleSheet(
            "background:#e5484d;color:#ffffff;padding:10px 16px;border-radius:8px;font-weight:700;")
        _ind.addWidget(_ind_lbl)

        # DeskPulse brand wordmark — the actual live logo.svg, rendered at native
        # resolution (bundled under agent/assets/, see resource_path()).
        self.brand_lbl = QLabel()
        self.brand_lbl.setAlignment(Qt.AlignCenter)
        logo_pm = render_svg(resource_path("agent", "assets", "logo.svg"), 32)
        if not logo_pm.isNull():
            self.brand_lbl.setPixmap(logo_pm)
        else:
            self.brand_lbl.setText("DeskPulse")

        # Company branding logo (fetched from the server; hidden until one loads).
        self.logo_lbl = QLabel()
        self.logo_lbl.setAlignment(Qt.AlignCenter)
        self.logo_lbl.hide()

        self.user_lbl = QLabel("Signed in as " + (config.get("user_name") or "—"))
        self.user_lbl.setStyleSheet("color:#6b7390;")

        self.status_dot = QLabel("●")
        self.status_dot.setStyleSheet("color:#9aa3c0;font-size:18px;")
        self.status_text = QLabel("Idle — not tracking")
        self.status_text.setStyleSheet("font-weight:600;")
        head = QHBoxLayout()
        head.addWidget(self.status_dot)
        head.addWidget(self.status_text)
        head.addStretch(1)
        self.refresh_btn = QPushButton("⟳ Refresh")
        self.refresh_btn.setToolTip("Re-fetch tasks, clients & monitoring settings, and send any pending updates")
        self.refresh_btn.clicked.connect(self._refresh_data)
        head.addWidget(self.refresh_btn)

        self.client_combo = QComboBox()
        self.client_combo.addItem("— No client —", None)

        # Task picker + add/remove. The selected task is "what I'm working on".
        self.task = QComboBox()
        self.task.addItem("— No task —", None)
        self.task.currentIndexChanged.connect(self._on_task_change)
        add_task_btn = QPushButton("+ Add")
        add_task_btn.setToolTip("Add a new task")
        add_task_btn.clicked.connect(self._add_task)
        del_task_btn = QPushButton("Remove")
        del_task_btn.setToolTip("Remove the selected task")
        del_task_btn.clicked.connect(self._remove_task)
        task_row = QHBoxLayout()
        task_row.addWidget(self.task, 1)
        task_row.addWidget(add_task_btn)
        task_row.addWidget(del_task_btn)

        self.toggle = QPushButton("Start tracking")
        self.toggle.clicked.connect(self._toggle)

        self.auto_chk = QCheckBox("Automatically track during my scheduled work hours")
        self.auto_chk.setToolTip("Start tracking when your work schedule begins and stop when it ends.")
        self.auto_chk.setChecked(bool(config.get("auto_start_on_schedule", True)))
        self.auto_chk.toggled.connect(self._on_auto_toggle)

        self.active_lbl = QLabel("Active: 0m")
        self.inactive_lbl = QLabel("Inactive: 0m")
        self.app_lbl = QLabel("Current app: —")
        self.app_lbl.setWordWrap(True)
        self.activity_lbl = QLabel("Activity: 0%")
        stats = QVBoxLayout()
        for w in (self.active_lbl, self.inactive_lbl, self.activity_lbl, self.app_lbl):
            stats.addWidget(w)

        dash_btn = QPushButton("Open dashboard")
        dash_btn.clicked.connect(lambda: webbrowser.open(config["server_url"] + "/app"))
        logout_btn = QPushButton("Sign out")
        logout_btn.clicked.connect(self._logout)
        foot = QHBoxLayout()
        foot.addWidget(dash_btn)
        foot.addStretch(1)
        foot.addWidget(logout_btn)

        layout = QVBoxLayout(self)
        layout.addWidget(self.brand_lbl)
        layout.addWidget(self.logo_lbl)
        layout.addWidget(self.remote_banner)
        layout.addLayout(head)
        layout.addWidget(self.user_lbl)
        layout.addWidget(QLabel("Client / company"))
        layout.addWidget(self.client_combo)
        layout.addWidget(QLabel("Working on (task)"))
        layout.addLayout(task_row)
        layout.addWidget(self.toggle)
        layout.addWidget(self.auto_chk)
        layout.addSpacing(8)
        layout.addLayout(stats)
        layout.addSpacing(8)
        layout.addWidget(QLabel("DeskPulse monitors only while tracking is ON."))
        layout.addLayout(foot)

        self._load_branding()
        self._load_clients()
        self._load_tasks()

        self.timer = QTimer(self)
        self.timer.timeout.connect(self._refresh)
        self.timer.start(1000)

        # Evaluate the work schedule every minute (and shortly after launch, so an
        # agent started at Windows login begins tracking if it's already work time).
        self.sched_timer = QTimer(self)
        self.sched_timer.timeout.connect(self._check_schedule)
        self.sched_timer.start(60000)
        QTimer.singleShot(1500, self._check_schedule)

    def _load_branding(self):
        """Fetch the org's name + logo from the server and show the logo at the top.
        Best-effort: stays silent (and logo hidden) when offline or none is set."""
        try:
            me = self.client.get_me()
        except Exception:
            return
        self._apply_schedule(me)
        org = me.get("org") or {}
        name = (org.get("name") or "").strip()
        if name:
            self.setWindowTitle(f"DeskPulse — {name}")
        logo_url = org.get("logo_url")
        if not logo_url:
            return
        try:
            base = self.config["server_url"].rstrip("/")
            full = logo_url if logo_url.startswith("http") else base + logo_url
            data = requests.get(full, timeout=10).content
            pix = QPixmap()
            if pix.loadFromData(data) and not pix.isNull():
                self.logo_lbl.setPixmap(pix.scaledToHeight(48, Qt.SmoothTransformation))
                self.logo_lbl.show()
        except Exception:
            pass  # offline / unsupported image — leave the logo hidden

    def _load_clients(self):
        current = self.client_combo.currentData()
        self.client_combo.blockSignals(True)
        self.client_combo.clear()
        self.client_combo.addItem("— No client —", None)
        try:
            for c in self.client.get_clients():
                self.client_combo.addItem(f"{c['name']}", c["id"])
        except Exception:
            pass  # offline / no clients — leave the default option
        idx = self.client_combo.findData(current)
        if idx >= 0:
            self.client_combo.setCurrentIndex(idx)
        self.client_combo.blockSignals(False)

    def _load_tasks(self):
        current = self.task.currentData()
        self.task.blockSignals(True)
        self.task.clear()
        self.task.addItem("— No task —", None)
        try:
            for t in self.client.get_tasks():
                self.task.addItem(t["title"], t["id"])
        except Exception:
            pass
        # Restore prior selection if still present.
        idx = self.task.findData(current)
        if idx >= 0:
            self.task.setCurrentIndex(idx)
        self.task.blockSignals(False)

    def _add_task(self):
        title, ok = QInputDialog.getText(self, "Add task", "Task description:")
        if not ok or not title.strip():
            return
        try:
            res = self.client.create_task(title.strip(), self.client_combo.currentData())
            self._load_tasks()
            idx = self.task.findData(res.get("task_id"))
            if idx >= 0:
                self.task.setCurrentIndex(idx)
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(self, "Could not add task", str(exc))

    def _remove_task(self):
        task_id = self.task.currentData()
        if not task_id:
            return
        if QMessageBox.question(self, "Remove task",
                                f"Remove “{self.task.currentText()}”?") != QMessageBox.Yes:
            return
        try:
            self.client.delete_task(task_id)
            self._load_tasks()
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(self, "Could not remove task", str(exc))

    def _refresh_data(self):
        """Manual sync: push any queued/offline updates, then re-fetch the task
        list, client list and admin monitoring settings (applied live if tracking)."""
        self.refresh_btn.setEnabled(False)
        self.refresh_btn.setText("Refreshing…")
        try:
            try:
                self.client.flush_queue()   # send pending updates first
            except Exception:
                pass
            self._load_clients()
            self._load_tasks()
            try:
                self._apply_schedule(self.client.get_me())   # refresh work schedule
            except Exception:
                pass
            try:
                policy = self.client.get_policy()
                for key in ("screenshot_interval_min", "idle_threshold_min", "sync_interval_s",
                            "screenshot_blur", "track_screenshots", "track_windows", "track_processes"):
                    if key in policy and policy[key] is not None:
                        self.config[key] = policy[key]
                if self.tracker and getattr(self.tracker, "running", False):
                    self.tracker._apply_policy()   # apply new settings to the live session
            except Exception:
                pass
        finally:
            self.refresh_btn.setEnabled(True)
            self.refresh_btn.setText("⟳ Refresh")

    def _on_task_change(self):
        # While tracking, switching the task re-tags the running session so the
        # site's time-per-task reflects what the worker is actually working on.
        if self.tracker and getattr(self.tracker, "running", False):
            try:
                self.tracker.set_task(self.task.currentData())
            except Exception:
                pass

    def start_tracking(self, auto: bool = False):
        """Begin a tracking session. auto=True marks it as schedule-started."""
        if self.is_tracking():
            return
        try:
            self.client = WebhookClient(self.config["server_url"],
                                        self.config["device_id"], self.config["secret"])
            self.tracker = Tracker(self.client, self.config,
                                   client_id=self.client_combo.currentData(),
                                   task_id=self.task.currentData())
            self.tracker.start()
            self._auto_started = auto
            self.toggle.setText("Stop tracking")
        except Exception as exc:  # noqa: BLE001
            self.tracker = None
            if not auto:   # a scheduled auto-start stays quiet (may be offline)
                QMessageBox.critical(self, "Could not start",
                                     f"Failed to start a session:\n{exc}")

    def stop_tracking(self):
        if not self.is_tracking():
            return
        self.toggle.setEnabled(False)
        self.toggle.setText("Stopping…")
        self.tracker.stop()
        self.tracker = None
        self._auto_started = False
        self.toggle.setEnabled(True)
        self.toggle.setText("Start tracking")

    def _toggle(self):
        if self.is_tracking():
            self.stop_tracking()
        else:
            self.start_tracking(auto=False)

    def _on_auto_toggle(self, checked: bool):
        self.config["auto_start_on_schedule"] = bool(checked)
        self.config.save()
        if checked:
            self._was_in_window = False   # re-evaluate now (may start immediately)
            self._check_schedule()

    def _apply_schedule(self, me: dict):
        """Cache the worker's work schedule from a /me payload (persisted for offline)."""
        sched = (me or {}).get("schedule") or {}
        self._schedule = {
            "work_start": sched.get("work_start"),
            "work_end": sched.get("work_end"),
            "work_days": sched.get("work_days") or [],
        }
        self.config["work_start"] = self._schedule["work_start"]
        self.config["work_end"] = self._schedule["work_end"]
        self.config["work_days"] = self._schedule["work_days"]
        self.config.save()

    def _in_work_window(self) -> bool:
        sched = self._schedule or {}
        ws, we, days = sched.get("work_start"), sched.get("work_end"), sched.get("work_days")
        if not ws or not we or not days:
            return False
        try:
            start_t = datetime.strptime(str(ws)[:5], "%H:%M").time()
            end_t = datetime.strptime(str(we)[:5], "%H:%M").time()
        except (ValueError, TypeError):
            return False
        now = datetime.now()
        return now.isoweekday() in days and start_t <= now.time() <= end_t

    def _check_schedule(self):
        """Edge-triggered: start tracking when the scheduled window opens, and stop an
        auto-started session when it closes. A manual stop inside the window is honored
        (we don't re-start until the next window)."""
        if not self.config.get("auto_start_on_schedule", True):
            return
        in_window = self._in_work_window()
        if in_window and not self._was_in_window and not self.is_tracking():
            self.start_tracking(auto=True)
        elif not in_window and self._was_in_window and self.is_tracking() and self._auto_started:
            self.stop_tracking()
        self._was_in_window = in_window

    def _refresh(self):
        if self.tracker and self.tracker.running:
            s = self.tracker.status()
            self.status_dot.setStyleSheet("color:#1f9d6b;font-size:18px;")
            self.status_text.setText("● Monitoring")
            self.active_lbl.setText("Active: " + fmt_hms(s["active_s"]))
            self.inactive_lbl.setText("Inactive: " + fmt_hms(s["inactive_s"]))
            self.activity_lbl.setText(f"Activity: {s['current']['activity']}%")
            cur = s["current"]
            label = cur["app"] or "—"
            if cur["title"]:
                label += f" · {cur['title'][:50]}"
            self.app_lbl.setText("Current app: " + label)
        else:
            self.status_dot.setStyleSheet("color:#9aa3c0;font-size:18px;")
            self.status_text.setText("Idle — not tracking")
        self._refresh_remote()

    def _refresh_remote(self):
        """Show/hide the remote-control warning based on the controller's state.
        Called from the 1s GUI timer so all widget mutation stays on the GUI thread."""
        active = False
        if self.remote is not None:
            try:
                active = bool(self.remote.state().get("active"))
            except Exception:
                active = False
        if active:
            self.remote_banner.show()
            if not self.remote_indicator.isVisible():
                self.remote_indicator.adjustSize()
                self.remote_indicator.show()
                self.remote_indicator.raise_()
        else:
            self.remote_banner.hide()
            if self.remote_indicator.isVisible():
                self.remote_indicator.hide()

    def _logout(self):
        if self.is_tracking():
            if QMessageBox.question(self, "Sign out",
                                    "A tracking session is active. Stop it and sign out?") != QMessageBox.Yes:
                return
            self.tracker.stop()
            self.tracker = None
        if self.on_logout:
            self.on_logout()

    def is_tracking(self) -> bool:
        return bool(self.tracker and self.tracker.running)

    def shutdown(self):
        if self.tracker and self.tracker.running:
            self.tracker.stop()
