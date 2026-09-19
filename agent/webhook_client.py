"""HTTP client for the DeskPulse PHP webhook API.

Signs every request body with HMAC-SHA256 using the device secret (matching the
server's verify_webhook). Failed sends are pushed to a disk-backed queue and
retried on the next flush so monitoring survives offline periods.
"""
import hashlib
import hmac
import json
import time
from typing import Optional

import requests

from .config import QUEUE_PATH


class WebhookClient:
    def __init__(self, server_url: str, device_id=None, secret=None):
        self.server_url = server_url.rstrip("/")
        self.device_id = device_id
        self.secret = secret
        self.timeout = 15

    # ── auth / bootstrap ──
    def register(self, email: str, password: str, device_name: str) -> dict:
        """One-time device registration with user credentials (no HMAC yet)."""
        resp = requests.post(
            f"{self.server_url}/webhooks/auth",
            json={"email": email, "password": password, "device_name": device_name},
            timeout=self.timeout,
        )
        resp.raise_for_status()
        data = resp.json()
        self.device_id = data["device_id"]
        self.secret = data["secret"]
        return data

    def get_me(self) -> dict:
        """Validate this device/account; raises on 401 if revoked/deleted."""
        return self._send("GET", "/webhooks/me").json()

    def get_clients(self) -> list:
        return self._send("GET", "/webhooks/clients").json()

    def get_policy(self) -> dict:
        """Admin-controlled monitoring policy for this device's org."""
        return self._send("GET", "/webhooks/policy").json()

    # ── tasks ──
    def get_tasks(self) -> list:
        return self._send("GET", "/webhooks/tasks").json()

    def create_task(self, title: str, client_id=None) -> dict:
        return self._send("POST", "/webhooks/tasks",
                          {"title": title, "client_id": client_id}).json()

    def delete_task(self, task_id) -> None:
        self._send("DELETE", f"/webhooks/tasks/{task_id}")

    # ── session lifecycle ──
    def start_session(self, client_id, started_at: str, task_id=None) -> int:
        resp = self._send("POST", "/webhooks/session",
                          {"client_id": client_id, "task_id": task_id,
                           "started_at": started_at})
        return resp.json()["session_id"]

    def stop_session(self, session_id, ended_at, active_s, inactive_s) -> None:
        self._enqueue_or_send(
            "PATCH", f"/webhooks/session/{session_id}",
            {"ended_at": ended_at, "active_s": active_s, "inactive_s": inactive_s})

    def set_task(self, session_id, task_id) -> None:
        """Re-tag an open session with the task the worker is now working on."""
        self._send("POST", f"/webhooks/session/{session_id}/task", {"task_id": task_id})

    # ── data (queued; tolerate offline) ──
    def post_activity(self, session_id, samples: list,
                      active_s: int = None, inactive_s: int = None) -> None:
        # Carry the running active/inactive totals so the server can reflect the
        # activity % on a still-open session in realtime (not only at stop).
        body = {"samples": samples}
        if active_s is not None:
            body["active_s"] = active_s
            body["inactive_s"] = inactive_s
        self._enqueue_or_send("POST", f"/webhooks/session/{session_id}/activity", body)

    def post_windows(self, session_id, windows: list, processes: list) -> None:
        self._enqueue_or_send("POST", f"/webhooks/session/{session_id}/windows",
                              {"windows": windows, "processes": processes})

    def post_idle(self, session_id, periods: list) -> None:
        self._enqueue_or_send("POST", f"/webhooks/session/{session_id}/idle",
                              {"periods": periods})

    def post_screenshot(self, session_id, image_bytes: bytes, ext: str,
                        ts: str, blurred: bool, monitor=None) -> None:
        path = (f"/webhooks/session/{session_id}/screenshot"
                f"?ext={ext}&ts={ts}&blurred={'1' if blurred else '0'}")
        if monitor is not None:
            path += f"&monitor={int(monitor)}"
        try:
            self._send("POST", path, raw=image_bytes)
        except Exception:
            pass  # screenshots are best-effort; don't block the tracker

    # ── remote control (real-time; NEVER queued) ──
    def get_json(self, path: str) -> dict:
        """Signed GET with an empty body. Bypasses the offline queue."""
        return self._send("GET", path).json()

    def post_bytes(self, path: str, raw: bytes, query: str = "") -> requests.Response:
        """Signed POST of raw bytes (like post_screenshot). Bypasses the offline
        queue — remote-control frames must be live or dropped, never replayed."""
        full = path + (("?" + query) if query else "")
        return self._send("POST", full, raw=raw)

    # ── signing / transport ──
    def _sign(self, body: bytes) -> str:
        return hmac.new(self.secret.encode(), body, hashlib.sha256).hexdigest()

    def _send(self, method: str, path: str, json_body: Optional[dict] = None,
              raw: Optional[bytes] = None) -> requests.Response:
        if raw is not None:
            body = raw
        elif json_body is not None:
            body = json.dumps(json_body).encode()
        else:
            body = b""
        headers = {
            "X-DeskPulse-Device": str(self.device_id),
            "X-DeskPulse-Signature": self._sign(body),
        }
        if raw is None:
            headers["Content-Type"] = "application/json"
        resp = requests.request(method, f"{self.server_url}{path}",
                                data=body, headers=headers, timeout=self.timeout)
        resp.raise_for_status()
        return resp

    def _enqueue_or_send(self, method: str, path: str, json_body: dict) -> None:
        try:
            self._send(method, path, json_body)
        except Exception:
            self._queue_append({"method": method, "path": path, "body": json_body,
                                "ts": time.time()})

    # ── disk-backed offline queue ──
    @staticmethod
    def _queue_load() -> list:
        if QUEUE_PATH.exists():
            try:
                return json.loads(QUEUE_PATH.read_text("utf-8"))
            except (ValueError, OSError):
                return []
        return []

    @staticmethod
    def _queue_save(items: list) -> None:
        try:
            QUEUE_PATH.write_text(json.dumps(items), "utf-8")
        except OSError:
            pass

    def _queue_append(self, item: dict) -> None:
        items = self._queue_load()
        items.append(item)
        self._queue_save(items[-500:])  # cap to avoid unbounded growth

    def flush_queue(self) -> None:
        """Attempt to resend any queued requests; keep failures for next time."""
        items = self._queue_load()
        if not items:
            return
        remaining = []
        for item in items:
            try:
                self._send(item["method"], item["path"], item["body"])
            except Exception:
                remaining.append(item)
        self._queue_save(remaining)
