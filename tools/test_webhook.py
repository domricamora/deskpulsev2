"""End-to-end webhook smoke test (no GUI).

Registers a device for a user, opens a session, posts activity/windows/idle and a
screenshot, then closes the session — exercising the same HMAC-signed contract the
desktop agent uses. Run after the server is up and a user exists.

    py tools/test_webhook.py http://localhost/vtnew/deskpulse/server/public ava@demo.test Demo12345
"""
import hashlib
import hmac
import io
import json
import sys
from datetime import datetime, timezone

import requests


def now():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def main():
    if len(sys.argv) < 4:
        print(__doc__)
        sys.exit(1)
    base, email, password = sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3]

    # 1. Register device.
    r = requests.post(f"{base}/webhooks/auth",
                      json={"email": email, "password": password, "device_name": "smoke-test"})
    r.raise_for_status()
    dev = r.json()
    device_id, secret = dev["device_id"], dev["secret"]
    print("registered device", device_id)

    def send(method, path, body=None, raw=None):
        data = raw if raw is not None else (json.dumps(body).encode() if body is not None else b"")
        sig = hmac.new(secret.encode(), data, hashlib.sha256).hexdigest()
        headers = {"X-DeskPulse-Device": str(device_id), "X-DeskPulse-Signature": sig}
        if raw is None:
            headers["Content-Type"] = "application/json"
        resp = requests.request(method, f"{base}{path}", data=data, headers=headers)
        resp.raise_for_status()
        return resp

    # 2. Start session.
    sid = send("POST", "/webhooks/session", {"started_at": now()}).json()["session_id"]
    print("session", sid)

    # 3. Activity + windows + idle.
    send("POST", f"/webhooks/session/{sid}/activity",
         {"samples": [{"ts": now(), "keyboard": 120, "mouse": 80, "pct": 73}]})
    send("POST", f"/webhooks/session/{sid}/windows",
         {"windows": [{"ts": now(), "app": "Code.exe", "title": "test — VS Code", "focus_seconds": 600}],
          "processes": [{"ts": now(), "app": "chrome.exe", "pid": 4321}]})
    send("POST", f"/webhooks/session/{sid}/idle",
         {"periods": [{"start": now(), "end": now()}]})

    # 4. Screenshot (1x1 PNG).
    png = (b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06"
           b"\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05"
           b"\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82")
    send("POST", f"/webhooks/session/{sid}/screenshot?ext=png&ts={now()}&blurred=0", raw=png)

    # 5. Stop session.
    send("PATCH", f"/webhooks/session/{sid}", {"ended_at": now(), "active_s": 3000, "inactive_s": 600})
    print("OK — session closed. Check the dashboard.")


if __name__ == "__main__":
    main()
