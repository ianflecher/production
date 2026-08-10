<#
    backup-imprint.ps1  —  Automated offsite backup for the Imprint production system.

    Produces ONE self-contained restore kit per run:
        imprint-backup-<timestamp>.zip
            ├── database.sql     full mysqldump of imprint_production
            ├── code.zip         snapshot of all tracked source at HEAD (just unzip)
            └── RESTORE.txt      step-by-step restore instructions

    The zip is copied to OneDrive (offsite / cloud) and old backups are pruned.
    Safe to run anytime — it only READS the database and the git repo.

    Manual run:   powershell -ExecutionPolicy Bypass -File C:\ImprintProduction\backup-imprint.ps1
#>

$ErrorActionPreference = 'Stop'

# ---- Configuration ---------------------------------------------------------
$RepoDir    = 'C:\ImprintProduction'
$AppDir     = Join-Path $RepoDir 'application'
$EnvFile    = Join-Path $AppDir '.env'
# C:\xampp first: an older C:\xampp1 can still be on disk after a
# reinstall, and a dump taken with the wrong one backs up nothing.
$MysqlDump  = 'C:\xampp\mysql\bin\mysqldump.exe'
if (-not (Test-Path $MysqlDump)) { $MysqlDump = 'C:\xampp1\mysql\bin\mysqldump.exe' }
$OneDrive   = Join-Path $env:USERPROFILE 'OneDrive\ImprintBackups'
$LogDir     = Join-Path $RepoDir 'logs'
$LogFile    = Join-Path $LogDir 'backup.log'
# Backups run every 4 hours (6 a day), so 180 keeps roughly 30 days of history.
# Each one is ~3.3 MB, i.e. about 600 MB held in OneDrive at steady state.
$Keep       = 180         # how many most-recent backups to retain offsite
$Stamp      = Get-Date -Format 'yyyyMMdd-HHmmss'

function Write-Log($msg) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
    Add-Content -Path $LogFile -Value $line -Encoding utf8
    Write-Host $line
}

function Get-EnvVal($name) {
    $m = Select-String -Path $EnvFile -Pattern ("^{0}=(.*)$" -f [regex]::Escape($name)) | Select-Object -First 1
    if (-not $m) { return '' }
    $v = $m.Matches[0].Groups[1].Value.Trim()
    # strip surrounding single or double quotes
    if ($v.Length -ge 2 -and (($v[0] -eq '"' -and $v[-1] -eq '"') -or ($v[0] -eq "'" -and $v[-1] -eq "'"))) {
        $v = $v.Substring(1, $v.Length - 2)
    }
    return $v
}

$work = Join-Path $env:TEMP "imprint-backup-$Stamp"
$cnf  = $null

try {
    Write-Log "=== Backup start ($Stamp) ==="

    # ---- Read DB credentials from .env ------------------------------------
    $dbHost = Get-EnvVal 'DB_HOST'; if (-not $dbHost) { $dbHost = '127.0.0.1' }
    $dbPort = Get-EnvVal 'DB_PORT'; if (-not $dbPort) { $dbPort = '3306' }
    $dbName = Get-EnvVal 'DB_DATABASE'
    $dbUser = Get-EnvVal 'DB_USERNAME'
    $dbPass = Get-EnvVal 'DB_PASSWORD'
    if (-not $dbName) { throw "DB_DATABASE not found in $EnvFile" }

    New-Item -ItemType Directory -Path $work -Force | Out-Null

    # ---- 1. Database dump (password passed via temp defaults file) ---------
    # Writing the password into a temp .cnf keeps it off the command line / process list.
    $cnf = New-TemporaryFile
    @(
        '[client]'
        "host=$dbHost"
        "port=$dbPort"
        "user=$dbUser"
        "password=$dbPass"
    ) | Set-Content -Path $cnf -Encoding ascii

    $dumpFile = Join-Path $work 'database.sql'
    $errFile  = Join-Path $work 'dump.err'
    $args = @(
        "--defaults-extra-file=$cnf"
        '--single-transaction'
        '--routines'
        '--triggers'
        '--default-character-set=utf8mb4'
        '--add-drop-table'
        $dbName
    )
    $p = Start-Process -FilePath $MysqlDump -ArgumentList $args `
        -RedirectStandardOutput $dumpFile -RedirectStandardError $errFile `
        -NoNewWindow -Wait -PassThru
    if ($p.ExitCode -ne 0) {
        $err = (Get-Content $errFile -Raw -ErrorAction SilentlyContinue)
        throw "mysqldump failed (exit $($p.ExitCode)): $err"
    }
    $dumpSize = (Get-Item $dumpFile).Length
    if ($dumpSize -lt 512 -or -not (Select-String -Path $dumpFile -Pattern 'CREATE TABLE' -Quiet)) {
        throw "database dump looks invalid (size=$dumpSize bytes, no CREATE TABLE found)"
    }
    Remove-Item $errFile -Force -ErrorAction SilentlyContinue   # empty on success; keep it out of the zip
    Write-Log ("Database dumped: {0:N0} bytes" -f $dumpSize)

    # ---- 2. Code snapshot (current tracked files at HEAD) -----------------
    # A git archive of HEAD — every tracked source file, minus gitignored bulk
    # like the 52MB cloudflared.exe. Self-contained: just unzip to restore.
    $codeZip = Join-Path $work 'code.zip'
    & git -C $RepoDir archive --format=zip -o $codeZip HEAD 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $codeZip)) { throw "git archive failed" }
    Write-Log ("Code snapshot created: {0:N0} bytes" -f (Get-Item $codeZip).Length)

    # ---- 3. Restore instructions ------------------------------------------
    $restore = @"
IMPRINT PRODUCTION — RESTORE INSTRUCTIONS
Backup taken: $Stamp

This kit fully restores the app + data onto a fresh machine.

1. RESTORE THE CODE
   Unzip code.zip — it contains every tracked source file at HEAD.
   (The 52MB cloudflared.exe is intentionally excluded; re-download it if needed.)

2. RESTORE THE DATABASE
   - Create an empty MySQL database, e.g.:  CREATE DATABASE imprint_production;
   - Import:  mysql -u <user> -p imprint_production < database.sql

3. CONFIG
   - Recreate application/.env (it is intentionally NOT in the backup — it holds
     secrets). Copy application/.env.example and fill in DB + APP_KEY.
   - composer install, then the app is ready.

Database: $dbName   Host: ${dbHost}:$dbPort
"@
    Set-Content -Path (Join-Path $work 'RESTORE.txt') -Value $restore -Encoding utf8

    # ---- 4. Zip it all ----------------------------------------------------
    $zipName = "imprint-backup-$Stamp.zip"
    $zipTmp  = Join-Path $env:TEMP $zipName
    if (Test-Path $zipTmp) { Remove-Item $zipTmp -Force }
    Compress-Archive -Path (Join-Path $work '*') -DestinationPath $zipTmp -CompressionLevel Optimal
    $zipSize = (Get-Item $zipTmp).Length
    Write-Log ("Archive built: {0} ({1:N0} bytes)" -f $zipName, $zipSize)

    # ---- 5. Copy offsite (OneDrive) ---------------------------------------
    if (-not (Test-Path $OneDrive)) { New-Item -ItemType Directory -Path $OneDrive -Force | Out-Null }
    Copy-Item -Path $zipTmp -Destination (Join-Path $OneDrive $zipName) -Force
    Write-Log "Copied offsite -> $OneDrive"
    Remove-Item $zipTmp -Force

    # ---- 6. Prune old backups (only after a successful new one) ------------
    $old = Get-ChildItem -Path $OneDrive -Filter 'imprint-backup-*.zip' |
           Sort-Object Name -Descending | Select-Object -Skip $Keep
    foreach ($f in $old) { Remove-Item $f.FullName -Force; Write-Log "Pruned old backup: $($f.Name)" }

    Write-Log "=== Backup OK ==="
}
catch {
    Write-Log "!!! Backup FAILED: $($_.Exception.Message)"
    throw
}
finally {
    if ($cnf -and (Test-Path $cnf)) { Remove-Item $cnf -Force -ErrorAction SilentlyContinue }
    if (Test-Path $work) { Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue }
}
