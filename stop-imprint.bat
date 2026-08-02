@echo off
title Imprint Production - Stop

echo Stopping Imprint Production services...
echo.

rem ---------- 1/3 Cloudflare Quick Tunnel ----------
taskkill /IM cloudflared.exe /F >nul 2>&1
if not errorlevel 1 (
    echo [1/3] Quick Tunnel stopped. The old public address is now dead.
) else (
    echo [1/3] Quick Tunnel was not running.
)

rem The saved address is now dead. Clear it so the app stops handing it out on
rem client questionnaire links (it warns instead when there is no public address).
break > "%~dp0current-tunnel-url.txt"

rem ---------- 2/3 Laravel dev server + queue worker ----------
rem Kills only PHP processes that belong to this app (ImprintProduction paths
rem or the port-8000 dev server) so other PHP apps are untouched.
powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -and ($_.CommandLine -match 'ImprintProduction' -or ($_.CommandLine -match 'artisan serve' -and $_.CommandLine -match '8000')) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }" >nul 2>&1
echo [2/3] Laravel application stopped.

rem ---------- 3/3 MySQL is left running on purpose ----------
echo [3/3] MySQL left running - other applications on this PC may use it.

echo.
echo Done. Starting again with start-imprint.bat creates a NEW public address.
if /I "%~1"=="/nopause" exit /b 0
pause
