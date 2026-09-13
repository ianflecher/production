# Registers the scheduled task that keeps the public address alive.
#
# watch-tunnel.ps1 is in the repository; the TASK that runs it is not - a
# scheduled task lives in Windows, not in git, so a fresh machine gets the
# script and none of the timer. This is the missing half, written down so
# setting it up again is a command rather than a memory.
#
# Run it once per machine, from an ordinary PowerShell window:
#
#     powershell -ExecutionPolicy Bypass -File C:\ImprintProduction\install-tunnel-watchdog.ps1
#
# Safe to run twice: -Force replaces the task rather than adding a second one.
# To see what it has been doing:  Get-Content logs\tunnel-watchdog.log -Tail 20
# To stop it:                     Unregister-ScheduledTask ImprintTunnelWatchdog

param(
    [string]$Root = "C:\ImprintProduction",
    [int]$EveryMinutes = 10,
    [string]$RunAs = $env:USERNAME
)

$ErrorActionPreference = 'Stop'

$script = Join-Path $Root "watch-tunnel.ps1"

if (-not (Test-Path $script)) {
    throw "watch-tunnel.ps1 is not at $script - point -Root at the folder that holds it."
}

$action = New-ScheduledTaskAction `
    -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -NonInteractive -File `"$script`""

# Repeating from the top of today rather than "now": the start time is only a
# base for the repetition, and anchoring it to a clock hour keeps the runs on
# predictable minutes after a restart.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date.AddMinutes(2) `
    -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)

$principal = New-ScheduledTaskPrincipal -UserId $RunAs -LogonType Interactive -RunLevel Limited

# StartWhenAvailable so a machine that was asleep still catches up; IgnoreNew so
# a slow run (get-tunnel-url.ps1 waits up to a minute for an address) is never
# overlapped by the next tick.
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName "ImprintTunnelWatchdog" `
    -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description "Checks every $EveryMinutes minutes that the Cloudflare quick tunnel still answers, and starts a fresh one plus republishes the address when it does not. See watch-tunnel.ps1" `
    -Force | Out-Null

Write-Output "ImprintTunnelWatchdog registered - runs every $EveryMinutes minutes as $RunAs."
Write-Output "Log (quiet unless it fixes something): $(Join-Path $Root 'logs\tunnel-watchdog.log')"
