<#
  DeskPulse production build.

  Packages the server/ tree into deskpulse-production.zip, laid out so the host's
  document root points at <extracted>/public (the app's native layout — no path
  rewriting). On first request the app auto-creates the database, schema and the
  bootstrap super admin from config.php, so the deployment runs with no manual setup.

  Excluded from the bundle:
    - the desktop-agent installer .exe under public/downloads (upload manually)
    - runtime uploads (screenshots/logos), dev debug files
    - the redundant public/setup.php + install/ SQL (superseded by auto-provisioning)

  Usage:  powershell -ExecutionPolicy Bypass -File tools/build_release.ps1
#>
$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot        # repo root
$server = Join-Path $root 'server'
$stage  = Join-Path $env:TEMP ('deskpulse-build-' + [guid]::NewGuid().ToString('N'))
$out    = Join-Path $root 'deskpulse-production.zip'

Write-Host "Staging -> $stage"
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# Copy the whole server/ tree, then prune what shouldn't ship.
Copy-Item -Path (Join-Path $server '*') -Destination $stage -Recurse -Force

# --- Prune ---
$prune = @(
  'public\downloads',        # desktop-agent .exe (uploaded manually)
  'public\uploads',          # runtime data (screenshots, logos)
  'public\setup.php',        # redundant with auto-provisioning; uses stale SQL
  'install',                 # stale deskpulse-fresh.sql
  '_dbg.txt', '_t.php'       # dev debug leftovers
)
foreach ($p in $prune) {
  $full = Join-Path $stage $p
  if (Test-Path $full) { Remove-Item -Recurse -Force $full }
}

# Recreate the empty writable runtime dirs (with a placeholder so they survive zipping).
foreach ($d in @('public\uploads', 'public\uploads\logos', 'public\downloads')) {
  $full = Join-Path $stage $d
  New-Item -ItemType Directory -Force -Path $full | Out-Null
  Set-Content -Path (Join-Path $full '.keep') -Value '' -NoNewline
}

# A short deploy note.
$readme = @"
DeskPulse - production bundle
=============================
1. Upload the CONTENTS of this archive to your hosting account.
2. Point the site's document root at the 'public' folder.
3. Create a MySQL database + user in your hosting panel that match server config.php
   (db name/user/pass). config.php is already included with your production values.
4. Upload the desktop-agent installer to public/downloads/ (left out of this zip).
5. Visit the site. On first load it auto-creates the schema and the super-admin
   account, then you can sign in. (Optional guided setup: /install.php - delete after.)

Default super admin is provisioned from config.php ('bootstrap_admin').
Change its password after first sign-in.
"@
Set-Content -Path (Join-Path $stage 'DEPLOY.txt') -Value $readme

# --- Zip (contents at the archive root, POSIX '/' separators so it extracts
#     correctly on Linux/cPanel — Windows PowerShell's Compress-Archive uses '\'). ---
if (Test-Path $out) { Remove-Item -Force $out }
Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null
$zip = [System.IO.Compression.ZipFile]::Open($out, [System.IO.Compression.ZipArchiveMode]::Create)
try {
  Get-ChildItem -Path $stage -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring($stage.Length + 1) -replace '\\', '/'
    [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
      $zip, $_.FullName, $rel, [System.IO.Compression.CompressionLevel]::Optimal)
  }
} finally { $zip.Dispose() }

$size = [math]::Round((Get-Item $out).Length / 1MB, 2)
Write-Host "Built $out ($size MB)"
Remove-Item -Recurse -Force $stage
