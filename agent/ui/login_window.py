"""Login / device-registration window."""
import socket

from PySide6.QtCore import Signal
from PySide6.QtWidgets import (QFormLayout, QLabel, QLineEdit, QMessageBox,
                               QPushButton, QVBoxLayout, QWidget)

from ..config import SERVER_URL
from ..webhook_client import WebhookClient


class LoginWindow(QWidget):
    logged_in = Signal()

    def __init__(self, config):
        super().__init__()
        self.config = config
        self.setWindowTitle("DeskPulse — Sign in")
        self.setMinimumWidth(380)

        self.email = QLineEdit()
        self.email.setPlaceholderText("you@company.com")
        self.password = QLineEdit()
        self.password.setEchoMode(QLineEdit.Password)
        self.device = QLineEdit(socket.gethostname())

        form = QFormLayout()
        form.addRow("Email", self.email)
        form.addRow("Password", self.password)
        form.addRow("Device name", self.device)

        self.btn = QPushButton("Sign in & register this device")
        self.btn.clicked.connect(self._submit)

        layout = QVBoxLayout(self)
        title = QLabel("Connect to DeskPulse")
        title.setStyleSheet("font-size:18px;font-weight:600;")
        layout.addWidget(title)
        layout.addWidget(QLabel("Sign in with your DeskPulse account. This registers "
                                "the current computer as a tracked device."))
        layout.addLayout(form)
        layout.addWidget(self.btn)

    def _submit(self):
        email = self.email.text().strip()
        password = self.password.text()
        if not (email and password):
            QMessageBox.warning(self, "Missing info", "Please fill in all fields.")
            return
        self.btn.setEnabled(False)
        self.btn.setText("Connecting…")
        try:
            client = WebhookClient(SERVER_URL)
            data = client.register(email, password, self.device.text().strip() or "Desktop")
            self.config["device_id"] = client.device_id
            self.config["secret"] = client.secret
            self.config["user_name"] = (data.get("user") or {}).get("name") or email
            self.config.save()
            self.logged_in.emit()
        except Exception as exc:  # noqa: BLE001 — surface any failure to the user
            QMessageBox.critical(self, "Sign-in failed",
                                 f"Could not connect or sign in:\n{exc}")
        finally:
            self.btn.setEnabled(True)
            self.btn.setText("Sign in & register this device")
