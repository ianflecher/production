# Keeps the public address alive without anybody watching it.
#
# A Cloudflare quick tunnel has no fixed lifetime and no warning when it ends.
# On 2026-09-11 the machine ran out of ephemeral UDP ports, the QUIC control
# stream could not be re-established and Cloudflare reclaimed the hostname; on
# 2026-09-13 the process was simply gone. Both times the address stayed in
# current-tunnel-url.txt, staff and clients kept using it, and it answered
# nothing. Nobody found out until somebody complained.
#
# Nothing here is new logic. test-tunnel.ps1 already answers "is our address
# usable" and clears away a tunnel it finds dead; get-tunnel-url.ps1 already
# refuses to publish an address that does not answer. This runs those two on a
# timer and starts a fresh tunnel in between, which is the part a person was
# doing by hand.
#
# Safe to run while everything is healthy: it does nothing at all in that case,
# which is the normal outcome and why it says so quietly.

param(
    [int]$Port = 8000,
    [string]$Root = "C:\ImprintProduction"
)

$ErrorActionPreference = 'Stop'

$cfd     = Join-Path $Root "cloudflared\cloudflared.exe"
$logDir  = Join-Path $Root "logs"
$cfdLog  = Join-Path $logDir "cloudflared.log"
$ownLog  = Join-Path $logDir "tunnel-watchdog.log"
$urlFile = Join-Path $Root "current-tunnel-url.txt"

if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }

function Write-Line([string]$message) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    Add-Content -Path $ownLog -Value $line -Encoding utf8
    Write-Output $line
}

# Keep the log from growing without end - it is written to every ten minutes.
if ((Test-Path $ownLog) -and ((Get-Item $ownLog).Length -gt 512KB)) {
    Get-Content $ownLog -Tail 500 | Set-Content $ownLog -Encoding utf8
}

# The app itself has to be up first. A tunnel pointed at nothing publishes an
# address that answers 502, which is worse than no address: it looks alive.
try {
    $up = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/up" -UseBasicParsing -TimeoutSec 5
    if ([int]$up.StatusCode -ne 200) { throw "status $($up.StatusCode)" }
} catch {
    Write-Line "app is not answering on port $Port - leaving the tunnel alone (start-all.bat starts the app)"
    exit 0
}

$usable = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Root "test-tunnel.ps1") -Port $Port

if ("$usable".Trim() -eq "1") {
    # Nothing to do. Not logged: ten minutes of "fine" every day buries the
    # lines that matter.
    exit 0
}

Write-Line "tunnel not usable - starting a fresh one"

if (-not (Test-Path $cfd)) {
    Write-Line "ERROR: cloudflared is missing at $cfd"
    exit 1
}

# Fresh log, so the address detector cannot read the previous run's URL.
Set-Content -Path $cfdLog -Value "" -Encoding utf8

Start-Process -FilePath "cmd.exe" `
    -ArgumentList "/c `"`"$cfd`" tunnel --url http://127.0.0.1:$Port > `"$cfdLog`" 2>&1`"" `
    -WindowStyle Minimized

$was = ""
if (Test-Path $urlFile) { $was = (Get-Content $urlFile -Raw).Trim() }

# get-tunnel-url.ps1 waits for an address AND proves it answers before it
# publishes, so whatever lands in the file is usable.
$now = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Root "get-tunnel-url.ps1")
$now = "$now".Trim()

if ($now -and $now -ne "ERROR") {
    Write-Line "new public address: $now  (was $was)"
    exit 0
}

Write-Line "ERROR: the tunnel started but no working address appeared - see $cfdLog"
exit 1
