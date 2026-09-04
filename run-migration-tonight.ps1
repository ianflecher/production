<#
    Run the pending database migration, after hours, unattended.

    A schema change on the live database is not something to fire and hope, so
    this takes a fresh backup FIRST and refuses to migrate if that backup did
    not work. Everything it does is written to logs\migrate.log, so the morning
    can see what happened without anyone having been awake for it.

    Registered as a one-time scheduled task; safe to run by hand as well.
#>

$RepoDir = 'C:\ImprintProduction'
$AppDir  = Join-Path $RepoDir 'application'
$Php     = 'C:\xampp\php\php.exe'
$Backup  = Join-Path $RepoDir 'backup-imprint.ps1'
$LogFile = Join-Path $RepoDir 'logs\migrate.log'

function Write-Log($msg) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $LogFile -Value $line -Encoding utf8
    Write-Output $line
}

New-Item -ItemType Directory -Force -Path (Split-Path $LogFile) | Out-Null

Write-Log '=== Migration run start ==='

try {
    # ---- 1. Somewhere to fall back to ---------------------------------------
    if (-not (Test-Path $Backup)) { throw "backup script not found at $Backup" }

    Write-Log 'Taking a backup before touching the schema...'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Backup | Out-Null

    if ($LASTEXITCODE -ne 0) {
        throw "backup failed (exit $LASTEXITCODE) - not migrating"
    }
    Write-Log 'Backup OK.'

    # ---- 2. What is about to run --------------------------------------------
    $pending = & $Php (Join-Path $AppDir 'artisan') migrate:status --pending 2>&1 | Out-String
    Write-Log "Pending before: $($pending.Trim())"

    # ---- 3. The migration ----------------------------------------------------
    # --force because nobody is here to answer the "in production?" prompt.
    Push-Location $AppDir
    $out = & $Php artisan migrate --force 2>&1 | Out-String
    $code = $LASTEXITCODE
    Pop-Location

    Write-Log ($out.Trim())

    if ($code -ne 0) { throw "migrate exited $code" }

    Write-Log '=== Migration OK ==='
}
catch {
    Write-Log "!!! Migration FAILED: $($_.Exception.Message)"
    Write-Log 'The backup taken above is the way back: restore it, or run'
    Write-Log '  php artisan migrate:rollback --step=1'
    exit 1
}
