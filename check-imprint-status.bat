@echo off
setlocal EnableDelayedExpansion
title Imprint Production - Status
set "ROOT=C:\ImprintProduction"

echo ============= IMPRINT PRODUCTION - SYSTEM STATUS =============
echo.

tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "mysqld.exe" >nul && (echo MySQL:         RUNNING) || (echo MySQL:         NOT RUNNING)

powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) { exit 0 } exit 1" && (echo Port 8000:     LISTENING) || (echo Port 8000:     NOT LISTENING - Laravel is down)

powershell -NoProfile -Command "try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8000/up' -UseBasicParsing -TimeoutSec 5; if ($r.StatusCode -eq 200) { exit 0 } } catch {}; exit 1" && (echo Laravel app:   OK - responding on http://127.0.0.1:8000) || (echo Laravel app:   NOT RESPONDING)

tasklist /FI "IMAGENAME eq cloudflared.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "cloudflared.exe" >nul && (echo Quick Tunnel:  RUNNING) || (echo Quick Tunnel:  NOT RUNNING - the public link is dead)

echo.
if exist "%ROOT%\current-tunnel-url.txt" (
    set /p TURL=<"%ROOT%\current-tunnel-url.txt"
    echo Public address on record: !TURL!
    echo   ^(only valid while the Quick Tunnel shows RUNNING^)
) else (
    echo Public address on record: none - run start-imprint.bat
)

echo.
echo Logs: %ROOT%\logs  ^(laravel.log, cloudflared.log^)
echo.
pause
