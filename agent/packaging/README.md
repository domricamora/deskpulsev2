# Packaging the DeskPulse agent (installable, trust & quirks)

This folder turns the Python agent into an installable Windows app and documents how
to make it pass Windows safety checks. Build with:

```powershell
powershell -ExecutionPolicy Bypass -File agent\packaging\build.ps1
```

Outputs:
- `dist\DeskPulse\` — the onedir app (`DeskPulse.exe` + dependencies)
- `dist\installer\DeskPulse-Setup-<version>.exe` — the installer (if Inno Setup is present)

## What's already done to survive safety checks & install quirks

| Concern | Mitigation in this build |
|---|---|
| Anonymous binary flagged by SmartScreen/AV | Embedded **version resource** (`version_info.txt`) with company/product/version |
| onefile self-extractors trip AV heuristics | **onedir** build (`deskpulse.spec`), nothing unpacks to `%TEMP%` |
| UPX-packed exes flagged | **UPX disabled** in the spec |
| "App needs admin" / UAC friction | **`asInvoker`** manifest + **per-user install** to LocalAppData (`PrivilegesRequired=lowest`) — no elevation |
| Blurry UI on HiDPI | **PerMonitorV2 DPI awareness** in the manifest |
| Compatibility shims | Win 7–11 **supportedOS** ids in the manifest |
| Duplicate installs on upgrade | Stable **AppId GUID** in `installer.iss` |
| Autostart without a scheduler quirk | Optional per-user **Run** registry key (opt-in task) |

## The one thing code can't do: SmartScreen "unknown publisher"

Windows SmartScreen shows a blue "Windows protected your PC" prompt for any executable
from an unknown publisher until it builds reputation. **The only real fix is an
Authenticode code-signing certificate** (OV builds reputation over time; **EV** is
trusted almost immediately). That is a paid certificate you must purchase from a CA
(e.g. DigiCert, Sectigo, SSL.com).

Once you have one:

1. Uncomment the `signtool` step in `build.ps1` and sign `DeskPulse.exe`.
2. Uncomment `SignTool=signtool` + `SignedUninstaller=yes` in `installer.iss` and
   register the tool in Inno Setup (Tools → Configure Sign Tools) as:
   ```
   signtool sign /fd SHA256 /a /tr http://timestamp.digicert.com /td SHA256 $f
   ```
3. Sign both the exe and the installer.

Without a certificate, users can still install via **"More info → Run anyway"** — the
app is otherwise complete and functional.

### Reducing antivirus false positives

- Keep the onedir + no-UPX setup above.
- Submit the signed binary to Microsoft (https://www.microsoft.com/wdsi/filesubmission)
  and major AV vendors for false-positive review.
- Don't obfuscate; ship the same build you submit.

## Manual build steps (if not using build.ps1)

```powershell
py -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt
.venv\Scripts\python agent\packaging\make_icon.py
.venv\Scripts\python -m PyInstaller agent\packaging\deskpulse.spec --noconfirm --clean
ISCC.exe agent\packaging\installer.iss
```
