"""Running-task snapshots via psutil."""
import psutil


def running_apps(limit: int = 40) -> list:
    """Distinct user-facing process names currently running.

    Filters out obvious system noise and de-duplicates by name; returns a list
    of {"app": name, "pid": pid}.
    """
    seen = {}
    for proc in psutil.process_iter(["name", "pid", "username"]):
        try:
            name = proc.info.get("name") or ""
            if not name or name.lower() in _NOISE:
                continue
            if name not in seen:
                seen[name] = proc.info.get("pid") or 0
            if len(seen) >= limit:
                break
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            continue
    return [{"app": name, "pid": pid} for name, pid in seen.items()]


_NOISE = {
    "system", "system idle process", "registry", "smss.exe", "csrss.exe",
    "wininit.exe", "services.exe", "lsass.exe", "svchost.exe", "fontdrvhost.exe",
    "dwm.exe", "winlogon.exe", "spoolsv.exe", "conhost.exe", "ctfmon.exe",
}
