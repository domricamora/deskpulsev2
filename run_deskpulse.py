"""DeskPulse agent launcher.

A top-level entry point that imports the agent package with absolute imports.
Used as the PyInstaller entry (so the packaged app collects the whole `agent`
package cleanly) and convenient for running from source:

    python run_deskpulse.py
"""
from agent.main import main

if __name__ == "__main__":
    main()
