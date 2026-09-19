; DeskPulse — Inno Setup installer script.
; Compile with Inno Setup 6:  ISCC.exe agent\packaging\installer.iss
; (run build.ps1 first to produce dist\DeskPulse via PyInstaller).
;
; Design choices that avoid common install quirks & safety prompts:
;  * PrivilegesRequiredOverridesAllowed + PrivilegesRequired=lowest → installs
;    per-user into LocalAppData with NO UAC/admin prompt by default.
;  * A stable AppId GUID so upgrades replace cleanly instead of stacking.
;  * Optional "start at login" via the per-user Run registry key (no scheduler).
;  * SignTool hooks (commented) so a code-signing cert can sign both the installer
;    and the bundled exe — the only real way to clear SmartScreen "unknown publisher".

#define MyAppName "DeskPulse"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "DeskPulse"
#define MyAppExeName "DeskPulse.exe"

[Setup]
AppId={{B8E4B6C2-1D7A-4E2F-9A3C-DE5K7P0LSE01}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL=https://deskpulse.local/
DefaultDirName={autopf}\{#MyAppName}
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
OutputDir=..\..\dist\installer
OutputBaseFilename=DeskPulse-Setup-{#MyAppVersion}
SetupIconFile=icon.ico
UninstallDisplayIcon={app}\{#MyAppExeName}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64compatible

; --- Code signing (uncomment once you have a certificate) ---
; SignTool=signtool
; SignedUninstaller=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional icons:"
Name: "startupicon"; Description: "Start {#MyAppName} automatically when I sign in"; GroupDescription: "Startup:"

[Files]
; The entire PyInstaller onedir output.
Source: "..\..\dist\{#MyAppName}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{userdesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Registry]
; Optional auto-start at login (per-user Run key — no admin needed).
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; \
    ValueType: string; ValueName: "DeskPulse"; ValueData: """{app}\{#MyAppExeName}"""; \
    Flags: uninsdeletevalue; Tasks: startupicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch {#MyAppName}"; \
    Flags: nowait postinstall skipifsilent

[UninstallDelete]
; Full clean uninstall: remove the per-user config/queue/credentials directory
; (%APPDATA%\DeskPulse) that the app writes at runtime — Inno only removes the
; {app} install folder by default, not user data written elsewhere.
Type: filesandordirs; Name: "{userappdata}\DeskPulse"
