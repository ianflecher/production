@echo off
title Imprint DEV - Stop (port 8001)

rem ============================================================================
rem  Stops the DEV copy only.
rem
rem  This used to be the live file copied here unchanged: it ran
rem  "taskkill /IM cloudflared.exe /F", which kills the SHOP'S tunnel too, and
rem  matched PHP processes on "ImprintProduction" - a substring of
rem  ImprintProduction-dev, so it hit both. Stopping dev stopped production.
rem
rem  Everything below is matched on port 8001 and this folder's path.
rem ============================================================================

echo Stopping the Imprint DEV services (port 8001)...
echo.

rem ---------- 1/3 This copy's Cloudflare tunnel ----------
powershell -NoProfile -Command "$p = @(Get-CimInstance Win32_Process -Filter \"Name='cloudflared.exe'\" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match ':8001' }); if ($p.Count -eq 0) { exit 1 }; $p | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; exit 0"
if not errorlevel 1 (
    echo [1/3] Dev tunnel stopped. Its public address is now dead.
) else (
    echo [1/3] No dev tunnel was running. ^(The live tunnel is left alone.^)
)

rem The saved address is dead. Clear it so the app stops handing it out on
rem client questionnaire links - it warns instead when there is none.
break > "%~dp0current-tunnel-url.txt"

rem ---------- 2/3 This copy's Laravel server ----------
rem Only PHP processes serving THIS folder or port 8001. "ImprintProduction-dev"
rem is matched explicitly so the live install's processes never match.
powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -and ($_.CommandLine -match 'ImprintProduction-dev' -or ($_.CommandLine -match 'artisan serve' -and $_.CommandLine -match '8001')) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }" >nul 2>&1
echo [2/3] Dev site stopped.

rem ---------- 3/3 MySQL is left running on purpose ----------
echo [3/3] MySQL left running - the live shop uses the same server.

echo.
echo Done. The live shop on port 8000 was not touched.
echo Starting again creates a NEW public address.
if /I "%~1"=="/nopause" exit /b 0
pause
