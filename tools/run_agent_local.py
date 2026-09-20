"""Run the real desktop agent against a local server instead of production.

`agent/config.py` locks `SERVER_URL` to https://deskpulse.click and ignores any
`server_url` in an on-disk config — deliberately, so a tampered config cannot
redirect a worker's monitoring data. That lock is also why the agent cannot be
pointed at a development server from inside itself, so this launcher does it
from outside. Nothing under `agent/` is modified.

    py tools/run_agent_local.py http://localhost/deskpulsev2/public
    py tools/run_agent_local.py http://localhost/deskpulsev2/public --fresh

`--fresh` keeps the agent's config and offline queue in a temp directory, so a
local run cannot overwrite the credentials of a real installed agent on this
machine. Without it the agent uses %APPDATA%/DeskPulse as it normally would.

For the unattended contract check, see tools/test_agent_compat.py.
"""
import os
import sys
import tempfile
from pathlib import Path


def main() -> int:
    argv = [a for a in sys.argv[1:] if not a.startswith("-")]
    if not argv:
        print(__doc__)
        return 1

    if "--fresh" in sys.argv:
        sandbox = tempfile.mkdtemp(prefix="deskpulse-agent-")
        os.environ["APPDATA"] = sandbox
        os.environ["XDG_CONFIG_HOME"] = sandbox
        print("config sandboxed in " + sandbox)

    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

    import agent.config as agent_config
    agent_config.SERVER_URL = argv[0].rstrip("/")

    # Imported only now: ui.login_window does `from ..config import SERVER_URL`,
    # which binds the value at import time.
    from agent.main import main as run_agent

    print("agent -> " + agent_config.SERVER_URL)
    return run_agent()


if __name__ == "__main__":
    sys.exit(main())
