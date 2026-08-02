@echo off
setlocal
title Imprint Production - Offline / Network (LAN) Startup

rem ================= paths (edit here if software moves) =================
set "ROOT=C:\ImprintProduction"
set "APP=%ROOT%\application"
set "LOGS=%ROOT%\logs"
set "PHP=C:\xampp1\php\php.exe"
set "MYSQLD=C:\xampp1\mysql\bin\mysqld.exe"
set "MYSQLADMIN=C:\xampp1\mysql\bin\mysqladmin.exe"
set "MYSQL_INI=C:\xampp1\mysql\bin\my.ini"
set "PORT=8000"
rem ========================================================================

rem This is the OFFLINE / LOCAL-NETWORK launcher. It serves the app to every
rem computer on the same office network (no internet, no Cloudflare). Use
rem start-imprint.bat instead when you need the public internet tunnel.
rem Run EITHER launcher at a time - both use port %PORT%.

if not exist "%LOGS%" mkdir "%LOGS%"

rem No tunnel runs in offline mode, so any saved public address is stale. Clear
rem it: the app then warns that client questionnaire links are in-house only,
rem instead of handing out a dead address.
break > "%ROOT%\current-tunnel-url.txt"

echo ==========================================================
echo   IMPRINT PRODUCTION - STARTING (OFFLINE / NETWORK MODE)
echo ==========================================================
echo.

rem ---------- 1/2 MySQL ----------
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "mysqld.exe" >nul

if errorlevel 1 (
    echo [1/2] Starting MySQL...

    start "Imprint MySQL" /MIN "%MYSQLD%" ^
        --defaults-file="%MYSQL_INI%" ^
        --standalone
) else (
    echo [1/2] MySQL is already running.
)

set /a MYSQLTRIES=0

:mysqlwait
"%MYSQLADMIN%" -u root ping >nul 2>&1

if not errorlevel 1 goto :mysqlok

set /a MYSQLTRIES+=1

if %MYSQLTRIES% GEQ 30 (
    echo ERROR: MySQL did not respond after 30 seconds.
    echo Check XAMPP or the MySQL log.
    pause
    exit /b 1
)

timeout /t 1 /nobreak >nul
goto :mysqlwait

:mysqlok
echo       MySQL is up.

rem ---------- 2/2 Laravel (bound to the whole network) ----------
powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue) { exit 1 } else { exit 0 }"

if errorlevel 1 (
    echo [2/2] Something is already running on port %PORT%.
    echo       If you started it with start-imprint.bat, that copy is
    echo       localhost-only. Close its "Imprint Laravel" window and
    echo       run this file again for network access.
) else (
    echo [2/2] Starting Laravel on the network ^(0.0.0.0:%PORT%^) ...

    start "Imprint Laravel (LAN)" /MIN cmd /c ^
    ""%PHP%" "%APP%\artisan" serve --host=0.0.0.0 --port=%PORT% > "%LOGS%\laravel.log" 2>&1"
)

rem Wait for Laravel to respond (localhost works because 0.0.0.0 includes it).
powershell -NoProfile -Command "foreach($i in 1..30){ try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:%PORT%/up' -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch {}; Start-Sleep -Seconds 1 }; exit 1"

if errorlevel 1 (
    echo ERROR: Laravel did not respond on port %PORT%.
    echo Check: %LOGS%\laravel.log
    pause
    exit /b 1
)

echo       Laravel is up.

rem ---------- Firewall: let other PCs reach port %PORT% (needs admin once) ----------
netsh advfirewall firewall show rule name="Imprint LAN %PORT%" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Imprint LAN %PORT%" dir=in action=allow protocol=TCP localport=%PORT% >nul 2>&1
    if errorlevel 1 (
        echo       [!] Could not add the firewall rule automatically.
        echo           Right-click this file and "Run as administrator" once,
        echo           or allow inbound TCP port %PORT% in Windows Firewall.
    ) else (
        echo       Firewall opened for port %PORT%.
    )
) else (
    echo       Firewall rule already present.
)

rem ---------- Find this PC's network address ----------
set "LANIP="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-lan-ip.ps1"`) do (
    set "LANIP=%%i"
)

if "%LANIP%"=="" goto :ipfail
if "%LANIP%"=="ERROR" goto :ipfail

echo.
echo ==========================================================
echo   IMPRINT PRODUCTION IS RUNNING (NETWORK MODE)
echo ==========================================================
echo.
echo   NETWORK ADDRESS - give this to employees on the same
echo   office network / Wi-Fi:
echo.
echo   http://%LANIP%:%PORT%
echo.
echo ----------------------------------------------------------
echo.
echo   SERVER COMPUTER ONLY:
echo.
echo   http://127.0.0.1:%PORT%
echo.
echo ==========================================================
echo.
echo   NOTES:
echo   - This works ONLY for people on the same network.
echo     For off-site / work-from-home access, use start-imprint.bat
echo     (Cloudflare tunnel) instead.
echo   - The address stays the same as long as this PC keeps the
echo     IP %LANIP%. Ask IT to "reserve" this PC's IP on the router
echo     so it never changes.
echo.
echo   Keep these minimized windows open:
echo   "Imprint MySQL"
echo   "Imprint Laravel (LAN)"
echo.
echo   Closing them stops the system.
echo ==========================================================
echo.

pause
exit /b 0

:ipfail
echo.
echo Laravel is running, but this computer's network IP could not be
echo detected automatically. Find it by running  ipconfig  (look for
echo "IPv4 Address", usually 192.168.x.x), then share:
echo.
echo   http://YOUR-IP:%PORT%
echo.
pause
exit /b 0
