# DeskPulse — one-step database installer (Windows / WAMP).
#
#   powershell -ExecutionPolicy Bypass -File install.ps1          # create DB + tables
#   powershell -ExecutionPolicy Bypass -File install.ps1 -Seed    # + demo data
#
# Finds the WAMP PHP binary automatically (or set $env:PHP to override).

param([switch]$Seed)

$ErrorActionPreference = "Stop"
$Repo = $PSScriptRoot

# Locate PHP.
$Php = $env:PHP
if (-not $Php) {
    $candidates = @(
        (Get-Command php.exe -ErrorAction SilentlyContinue).Source
    ) + (Get-ChildItem "C:\wamp64\bin\php\*\php.exe" -ErrorAction SilentlyContinue |
         Sort-Object FullName -Descending | Select-Object -ExpandProperty FullName)
    $Php = $candidates | Where-Object { $_ } | Select-Object -First 1
}
if (-not $Php -or -not (Test-Path $Php)) {
    Write-Error "PHP not found. Set `$env:PHP to your php.exe path and retry."
}
Write-Host "==> Using PHP: $Php"

# Ensure config.php exists (copy from example on first run).
$cfg = Join-Path $Repo "server\config.php"
if (-not (Test-Path $cfg)) {
    Copy-Item (Join-Path $Repo "server\config.example.php") $cfg
    Write-Host "==> Created server\config.php from example — edit DB credentials if needed."
}

$args = @("$Repo\server\install.php")
if ($Seed) { $args += "--seed" }
& $Php @args
