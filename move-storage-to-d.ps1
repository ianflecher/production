<#
    Move the uploaded files off C: and leave a junction behind.

    C: has about 2 GB free. storage\app is where every layout, mockup, payment
    proof and message photo lands, so it is the folder that grows for as long
    as the shop is used. D: has half a terabyte.

    Only storage\app moves. storage\framework and storage\logs are a few
    megabytes and want to stay fast and local.

    A junction is left in its place, so Laravel, the tech pack, the documents
    and every path already written into the database keep working unchanged -
    nothing in the app knows the files are on another disk.

    The tunnel is deliberately NOT stopped: killing cloudflared would hand the
    shop a brand new public address and every questionnaire link already sent
    to a client would die. Only PHP stops, for the seconds the move takes.

    No elevation needed: a directory junction is not a symbolic link and any
    account that can write to the folder can make one. That is what lets this
    run from Task Scheduler as the ordinary admin account, the same way the
    nightly backup does.

        powershell -ExecutionPolicy Bypass -File C:\ImprintProduction\move-storage-to-d.ps1
        ... -WhatIf     to see what it would do and change nothing

    Scheduled as the Windows task "ImprintStorageMove". It runs whether or not
    anything else is watching - no Claude session, no chat window, nothing that
    can hit a usage limit. Windows starts it, and it finishes on its own.
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$Source = 'C:\ImprintProduction\application\storage\app',
    [string]$Target = 'D:\ImprintStorage\app',
    [string]$StartScript = 'C:\ImprintProduction\start-all.bat'
)

$ErrorActionPreference = 'Stop'
$log = "C:\ImprintProduction\logs\storage-move-$(Get-Date -Format 'yyyyMMdd-HHmmss').log"

function Say([string]$text) {
    $line = "{0}  {1}" -f (Get-Date -Format 'HH:mm:ss'), $text
    Write-Host $line
    Add-Content -Path $log -Value $line -Encoding utf8
}

Say "=== storage move starting ==="

# ---------- 1. Refuse to start unless everything is as expected ----------

if (-not (Test-Path $Source)) { throw "Source not found: $Source" }

if ((Get-Item $Source).LinkType -eq 'Junction') {
    Say "Already a junction -> $((Get-Item $Source).Target). Nothing to do."
    exit 0
}

if (Test-Path $Target) { throw "Target already exists: $Target - move it aside first" }

$sourceFiles = Get-ChildItem $Source -Recurse -File -Force -ErrorAction SilentlyContinue
$sourceCount = $sourceFiles.Count
$sourceBytes = ($sourceFiles | Measure-Object Length -Sum).Sum
Say ("Source holds {0} files, {1:N0} MB" -f $sourceCount, ($sourceBytes / 1MB))

$free = (Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='D:'").FreeSpace
if ($free -lt ($sourceBytes * 3)) { throw "Not enough room on D: ($([math]::Round($free/1GB,1)) GB free)" }

# ---------- 2. Stop PHP only. The tunnel keeps its address. ----------

if ($PSCmdlet.ShouldProcess('the Laravel server', 'stop')) {
    $php = Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -match 'ImprintProduction' -or $_.CommandLine -match 'artisan serve' }

    foreach ($p in $php) {
        Say "stopping php $($p.ProcessId)"
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }

    Start-Sleep -Seconds 2
}

# ---------- 3. Move, then check every file arrived ----------

if ($PSCmdlet.ShouldProcess($Source, "move to $Target")) {
    New-Item -ItemType Directory -Path (Split-Path $Target -Parent) -Force | Out-Null

    Say "moving..."
    $rc = Start-Process robocopy -ArgumentList @($Source, $Target, '/E', '/MOVE', '/R:2', '/W:2', '/NFL', '/NDL', '/NP') -Wait -PassThru -NoNewWindow

    # robocopy: under 8 means it worked. Anything else and the files stay put.
    if ($rc.ExitCode -ge 8) { throw "robocopy failed with exit code $($rc.ExitCode) - files have NOT been moved" }

    $movedCount = (Get-ChildItem $Target -Recurse -File -Force -ErrorAction SilentlyContinue).Count
    Say "moved $movedCount files (was $sourceCount)"

    if ($movedCount -ne $sourceCount) { throw "File count does not match - STOPPING with the files still on D: at $Target" }
}

# ---------- 4. Leave the junction behind ----------

if ($PSCmdlet.ShouldProcess($Source, 'replace with a junction')) {
    if (Test-Path $Source) { Remove-Item $Source -Force -Recurse }

    cmd /c mklink /J "$Source" "$Target" | Out-Null

    $link = Get-Item $Source
    if ($link.LinkType -ne 'Junction') { throw "Junction was not created - the files are at $Target" }

    Say "junction: $Source -> $($link.Target)"

    # Read and write through it, the way the app will.
    $probe = Join-Path $Source '.move-probe'
    'ok' | Out-File $probe -Encoding utf8
    if (-not (Test-Path (Join-Path $Target '.move-probe'))) { throw 'A file written through the junction did not land on D:' }
    Remove-Item $probe -Force
    Say 'read/write through the junction works'
}

# ---------- 5. Start the app again and prove it answers ----------

if ($PSCmdlet.ShouldProcess('the app', 'start')) {
    Say 'starting...'
    Start-Process cmd -ArgumentList '/c', $StartScript -WindowStyle Minimized

    $up = $false
    foreach ($i in 1..40) {
        Start-Sleep -Seconds 2
        try {
            if ((Invoke-WebRequest 'http://127.0.0.1:8000/up' -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200) { $up = $true; break }
        } catch {}
    }

    if (-not $up) { Say 'WARNING: the app did not answer on 8000 - check logs\laravel.log'; exit 1 }
    Say 'the app is up'
}

Say '=== done ==='
Say "Files now live at $Target. The backup script must include that folder - see backup-imprint.ps1."
