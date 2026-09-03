<#
    backup-imprint.ps1  ?  Automated offsite backup for the Imprint production system.

    Produces ONE self-contained restore kit per run:
        imprint-backup-<timestamp>.zip
            ??? database.sql     full mysqldump of imprint_production
            ??? code.zip         snapshot of all tracked source at HEAD (just unzip)
            ??? RESTORE.txt      step-by-step restore instructions

    The zip is copied to OneDrive (offsite / cloud). Anything older than a month
    is pruned, except the first backup of each month, which is kept for good.

    Uploaded files - every layout, mockup, tech pack picture and payment proof -
    are mirrored SEPARATELY, not put in the zip. The zip is taken six times a
    day and 180 are kept; 400+ MB of pictures in each would be some 70 GB of
    OneDrive holding the same images over and over. The mirror is one current
    copy, and each run only carries what is new.
    Safe to run anytime ? it only READS the database and the git repo.

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
# The uploaded files, kept as a plain folder rather than inside the zips.
$UploadMirror = Join-Path $env:USERPROFILE 'OneDrive\ImprintUploads'
$LogDir     = Join-Path $RepoDir 'logs'
$LogFile    = Join-Path $LogDir 'backup.log'
# A backup older than a month goes. Counted in DAYS rather than in files:
# "keep the newest 180" only meant a month while the schedule stayed at six a
# day, and would quietly mean four days or four months if that ever changed.
#
# One backup a month is kept for good - the first of each month. Thirty days of
# history answers "undo what we did this morning"; the monthly ones answer
# "what did this order look like in March", which is the question that turns up
# long after every four-hourly copy is gone. They cost about 6 MB a year.
$KeepDays   = 30          # how long ordinary backups are held offsite
$KeepMonthly = $true      # ...and keep the first of each month for good
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
    # A git archive of HEAD ? every tracked source file, minus gitignored bulk
    # like the 52MB cloudflared.exe. Self-contained: just unzip to restore.
    $codeZip = Join-Path $work 'code.zip'
    & git -C $RepoDir archive --format=zip -o $codeZip HEAD 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $codeZip)) { throw "git archive failed" }
    Write-Log ("Code snapshot created: {0:N0} bytes" -f (Get-Item $codeZip).Length)

    # ---- 3. Restore instructions ------------------------------------------
    $restore = @"
IMPRINT PRODUCTION ? RESTORE INSTRUCTIONS
Backup taken: $Stamp

This kit fully restores the app + data onto a fresh machine.

1. RESTORE THE CODE
   Unzip code.zip ? it contains every tracked source file at HEAD.
   (The 52MB cloudflared.exe is intentionally excluded; re-download it if needed.)

2. RESTORE THE DATABASE
   - Create an empty MySQL database, e.g.:  CREATE DATABASE imprint_production;
   - Import:  mysql -u <user> -p imprint_production < database.sql

3. RESTORE THE UPLOADED FILES
   Copy OneDrive\ImprintUploads back to application/storage/app.
   These are the layouts, mockups, tech pack pictures and payment proofs.
   They are NOT in this zip - they are mirrored beside it, because they are
   hundreds of megabytes and do not change from one backup to the next.

4. CONFIG
   - Recreate application/.env (it is intentionally NOT in the backup ? it holds
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

    # ---- 6. Mirror the uploaded files -------------------------------------
    #
    # These are the one thing here that cannot be recreated: a client's artwork,
    # the artist's mockups, the proof of a payment. Until today they were in no
    # backup at all - the code snapshot is `git archive HEAD`, which is TRACKED
    # files, and everything under storage/app is gitignored.
    #
    # Additive on purpose: robocopy /E copies what is new or changed and never
    # deletes. A mirror that deletes would faithfully carry a mistake - or a
    # ransomware run - straight into the only copy that survives it.
    #
    # storage\app is a junction to D: since the disk move, so the real path is
    # resolved rather than assumed: if the junction is ever undone this keeps
    # backing up the right folder instead of quietly backing up nothing.
    $uploadSource = Join-Path $AppDir 'storage\app'
    $sourceItem = Get-Item $uploadSource -ErrorAction SilentlyContinue

    if ($sourceItem -and $sourceItem.LinkType -eq 'Junction' -and $sourceItem.Target) {
        $uploadSource = @($sourceItem.Target)[0]
    }

    if (Test-Path $uploadSource) {
        if (-not (Test-Path $UploadMirror)) { New-Item -ItemType Directory -Path $UploadMirror -Force | Out-Null }

        $rc = Start-Process robocopy -ArgumentList @(
            $uploadSource, $UploadMirror, '/E', '/R:1', '/W:1', '/NFL', '/NDL', '/NP', '/NJH', '/NJS'
        ) -Wait -PassThru -NoNewWindow

        # robocopy says 0 = nothing to do, 1 = files copied, up to 7 = odd but
        # done. 8 and over is a real failure.
        if ($rc.ExitCode -ge 8) {
            Write-Log "!!! Upload mirror FAILED (robocopy $($rc.ExitCode)) - the zip is still good"
        } else {
            $mirrored = Get-ChildItem $UploadMirror -Recurse -File -Force -ErrorAction SilentlyContinue
            Write-Log ("Uploads mirrored: {0} files, {1:N0} MB -> {2}" -f `
                $mirrored.Count, (($mirrored | Measure-Object Length -Sum).Sum / 1MB), $UploadMirror)
        }
    } else {
        Write-Log "!!! Uploads folder not found at $uploadSource - nothing mirrored"
    }

    # ---- 7. Prune old backups (only after a successful new one) ------------
    $cutoff = (Get-Date).AddDays(-$KeepDays)
    $all = Get-ChildItem -Path $OneDrive -Filter 'imprint-backup-*.zip'

    # The first backup of each month is the one that is kept, so "the monthly
    # one" is always the same file and never a different copy each run.
    $monthlyKeepers = @()
    if ($KeepMonthly) {
        $monthlyKeepers = $all |
            Group-Object { $_.Name -replace '^imprint-backup-(\d{6}).*$', '$1' } |   # YYYYMM
            ForEach-Object { ($_.Group | Sort-Object Name | Select-Object -First 1).Name }
    }

    $pruned = 0
    foreach ($f in $all) {
        if ($f.LastWriteTime -ge $cutoff) { continue }
        if ($monthlyKeepers -contains $f.Name) { continue }

        Remove-Item $f.FullName -Force
        Write-Log "Pruned old backup: $($f.Name)"
        $pruned++
    }

    $left = Get-ChildItem -Path $OneDrive -Filter 'imprint-backup-*.zip'
    Write-Log ("Retention: {0} pruned, {1} kept ({2:N0} MB){3}" -f `
        $pruned, $left.Count, (($left | Measure-Object Length -Sum).Sum / 1MB),
        $(if ($KeepMonthly) { ", including $($monthlyKeepers.Count) monthly" } else { '' }))

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
