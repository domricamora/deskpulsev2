"""Phase 8 — the real desktop agent, run against the Laravel server.

`tools/test_webhook.py` re-implements the HMAC contract. This does not: it imports
the agent's own modules and lets them talk. `WebhookClient` signs and sends every
request, `Tracker` builds every payload, and the agent's disk-backed queue is what
decides whether anything silently failed. A break here is a break an installed
agent hits.

Nothing under `agent/` is modified — it is frozen. Two things are injected from
outside it:

* the config directory (`APPDATA` / `XDG_CONFIG_HOME` -> a temp dir), so the
  developer's real credentials and offline queue are never touched;
* `config.SERVER_URL`, which is deliberately locked to production and is the
  only reason this launcher has to exist at all.

    py tools/test_agent_compat.py http://localhost/deskpulsev2/public ava@demo.test Demo12345

Every check runs, so one pass reports every incompatibility rather than the
first. Exits non-zero if any of them failed.
"""
import os
import re
import socket
import sys
import tempfile
import time
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent

RESULTS = []


def check(name: str, ok: bool, detail: str = "") -> bool:
    RESULTS.append((name, ok, detail))
    print("  %s  %s%s" % ("ok  " if ok else "FAIL", name, ("  - " + detail) if detail else ""))
    return ok


def section(title: str) -> None:
    print("\n" + title)


def main() -> int:
    if len(sys.argv) < 4:
        print(__doc__)
        return 1
    base, email, password = sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3]

    # Sandbox the agent's config/queue before anything imports agent.config —
    # config_dir() resolves these at import time.
    sandbox = tempfile.mkdtemp(prefix="deskpulse-compat-")
    os.environ["APPDATA"] = sandbox
    os.environ["XDG_CONFIG_HOME"] = sandbox
    sys.path.insert(0, str(REPO))

    import agent.config as agent_config
    agent_config.SERVER_URL = base      # the agent hard-locks this to production

    import requests
    from agent.config import Config
    from agent.webhook_client import WebhookClient

    print("agent -> %s  (config in %s)" % (base, sandbox))

    # -- 1. Registration - what LoginWindow._submit does ----------------------
    section("registration (LoginWindow)")
    client = WebhookClient(base)
    data = client.register(email, password, socket.gethostname() or "compat-harness")
    check("device_id is an int", isinstance(data.get("device_id"), int), repr(data.get("device_id")))
    check("secret is 64 hex", bool(re.fullmatch(r"[0-9a-f]{64}", str(data.get("secret") or ""))))
    check("user.name present", bool((data.get("user") or {}).get("name")),
          "LoginWindow stores it as the signed-in name")

    config = Config()
    config["device_id"], config["secret"] = client.device_id, client.secret
    config["user_name"] = (data.get("user") or {}).get("name") or email
    config.save()

    # -- 2. Bootstrap - DeskPulseApp._start / MainWindow._load_branding -------
    section("bootstrap (/me, branding, schedule)")
    me = client.get_me()
    check("user.name present", bool((me.get("user") or {}).get("name")))
    check("org object present", isinstance(me.get("org"), dict))
    schedule = me.get("schedule") or {}
    check("schedule keys present",
          all(k in schedule for k in ("work_start", "work_end", "work_days")))
    days = schedule.get("work_days")
    check("work_days is a list of ints",
          isinstance(days, list) and all(isinstance(d, int) for d in days), repr(days))
    for key in ("work_start", "work_end"):
        value = schedule.get(key)
        check("%s is HH:MM:SS or null" % key,
              value is None or bool(re.fullmatch(r"\d{2}:\d{2}:\d{2}", str(value))), repr(value))

    logo_url = (me.get("org") or {}).get("logo_url")
    if logo_url:
        full = logo_url if logo_url.startswith("http") else base + logo_url
        try:
            resp = requests.get(full, timeout=10)
            check("org logo resolves",
                  resp.status_code == 200 and resp.headers.get("content-type", "").startswith("image/"),
                  "%s -> %s %s" % (full, resp.status_code, resp.headers.get("content-type")))
        except Exception as exc:                                   # noqa: BLE001
            check("org logo resolves", False, "%s -> %s" % (full, exc))
    else:
        print("  ..    no org logo set - branding fetch not exercised")

    # -- 3. Pickers - MainWindow._load_clients / _load_tasks ------------------
    section("clients and tasks (MainWindow pickers)")
    clients = client.get_clients()
    check("clients is a list", isinstance(clients, list))
    check("every client has id and name",
          all(isinstance(c, dict) and "id" in c and "name" in c for c in clients),
          "the combo indexes c['name'] and c['id'] directly")

    created = client.create_task("Phase 8 compatibility run", None)
    task_id = created.get("task_id")
    check("create_task returns task_id", isinstance(task_id, int), repr(task_id))
    tasks = client.get_tasks()
    check("every task has id and title",
          all(isinstance(t, dict) and "id" in t and "title" in t for t in tasks))
    check("created task is listed", any(t.get("id") == task_id for t in tasks))

    # -- 4. Policy - Tracker._apply_policy ------------------------------------
    section("monitoring policy")
    policy = client.get_policy()
    expected = {
        "screenshot_interval_min": int, "idle_threshold_min": int, "sync_interval_s": int,
        "screenshot_blur": bool, "track_screenshots": bool, "track_windows": bool,
        "track_processes": bool,
    }
    for key, kind in expected.items():
        check("policy.%s is %s" % (key, kind.__name__),
              isinstance(policy.get(key), kind), repr(policy.get(key)))

    # -- 5. Remote control - RemoteController polls this every 3s -------------
    section("remote control poll")
    try:
        answer = client.get_json("/webhooks/remote/poll")
        # No session pending for a freshly registered device, which is the
        # normal idle answer the poller loops on.
        check("poll answers with a session field", "session" in answer, repr(answer)[:80])
    except requests.HTTPError as exc:
        resp = exc.response
        check("poll fails as JSON, not HTML",
              resp is not None and resp.headers.get("content-type", "").startswith("application/json"),
              "%s %s" % (resp.status_code if resp is not None else "?",
                         resp.headers.get("content-type") if resp is not None else ""))

    # -- 6. A real tracked session - Tracker start/sample/flush/stop ----------
    section("tracked session (Tracker)")
    try:
        from agent.monitor.tracker import Tracker, now_iso
    except Exception as exc:                                       # noqa: BLE001
        print("  ..    tracker not importable here (%s) - headless host, section skipped" % exc)
        Tracker = None

    if Tracker is not None:
        config["sync_interval_s"] = 15
        tracker = Tracker(client, config, client_id=None, task_id=task_id)
        tracker.start()
        check("session opened", isinstance(tracker.session_id, int), repr(tracker.session_id))
        session_id = tracker.session_id

        time.sleep(12)          # SAMPLE_INTERVAL_S is 10 - one sample lands
        check("a sample was taken", bool(tracker._samples) or tracker.active_s > 0,
              "active_s=%s samples=%s" % (tracker.active_s, len(tracker._samples)))

        tracker.set_task(task_id)

        # post_screenshot() swallows every failure by design, so drive the same
        # signer directly - otherwise a rejected upload looks like a pass.
        try:
            from agent.monitor.screenshot import capture_all_webp
            index, image = capture_all_webp(blur=False)[0]
            client._send("POST", "/webhooks/session/%s/screenshot?ext=webp&ts=%s&blurred=0"
                                 % (session_id, now_iso()), raw=image)
            check("screenshot accepted", True, "%d bytes of webp" % len(image))
        except Exception as exc:                                   # noqa: BLE001
            check("screenshot accepted", False, str(exc))

        # -- offline replay: the queue is the agent's own failure record --
        live, client.server_url = client.server_url, "http://127.0.0.1:9"
        client.post_activity(session_id, [{"ts": now_iso(), "keyboard": 4, "mouse": 9, "pct": 12}],
                             tracker.active_s, tracker.inactive_s)
        queued = len(WebhookClient._queue_load())
        client.server_url = live
        check("offline send is queued, not lost", queued == 1, "%d queued" % queued)
        client.flush_queue()
        check("queued send replays on reconnect", not WebhookClient._queue_load())

        time.sleep(12)
        tracker.stop()
        check("session closed", not tracker.running)
        check("nothing was silently queued", not WebhookClient._queue_load(),
              repr(WebhookClient._queue_load()))

    # -- 7. Cleanup -----------------------------------------------------------
    client.delete_task(task_id)

    failed = [name for name, ok, _ in RESULTS if not ok]
    print("\n%d/%d checks passed" % (len(RESULTS) - len(failed), len(RESULTS)))
    if failed:
        print("failed: " + ", ".join(failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
