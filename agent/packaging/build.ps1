# DeskPulse agent — build script (Windows PowerShell).
# Produces dist\DeskPulse\ (onedir) and, if Inno Setup is installed,
# dist\installer\DeskPulse-Setup-<version>.exe
#
# Usage (from the repo root):
#   powershell -ExecutionPolicy Bypass -File agent\packaging\build.ps1

$ErrorActionPreference = "Stop"
$Repo = (Resolve-Path "$PSScriptRoot\..\..").Path
Set-Location $Repo

Write-Host "==> Repo: $Repo"

# 1. Virtual environment + dependencies.
if (-not (Test-Path "$Repo\.venv")) {
    Write-Host "==> Creating virtualenv"
    py -m venv .venv
}
$Py = "$Repo\.venv\Scripts\python.exe"
Write-Host "==> Installing dependencies"
& $Py -m pip install --upgrade pip
& $Py -m pip install -r requirements.txt

# 2. Generate the icon.
Write-Host "==> Generating icon"
& $Py agent\packaging\make_icon.py

# 3. Build the onedir bundle with PyInstaller.
Write-Host "==> Running PyInstaller"
& $Py -m PyInstaller agent\packaging\deskpulse.spec --noconfirm --clean

# 4. Optional: sign the exe (requires a code-signing certificate).
#    & signtool sign /fd SHA256 /a /tr http://timestamp.digicert.com /td SHA256 `
#        "$Repo\dist\DeskPulse\DeskPulse.exe"

# 5. Build the installer if Inno Setup is available.
$Iscc = (Get-Command ISCC.exe -ErrorAction SilentlyContinue).Source
if (-not $Iscc) {
    foreach ($p in @("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
                     "$env:ProgramFiles\Inno Setup 6\ISCC.exe",
                     "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe")) {
        if (Test-Path $p) { $Iscc = $p; break }
    }
}
if ($Iscc) {
    Write-Host "==> Building installer with Inno Setup"
    & $Iscc agent\packaging\installer.iss
    Write-Host "==> Installer written to dist\installer\"
} else {
    Write-Warning "Inno Setup (ISCC.exe) not found. Install it from https://jrsoftware.org/isdl.php to build the installer. The onedir app is in dist\DeskPulse\."
}

Write-Host "==> Done."
