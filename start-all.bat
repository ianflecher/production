@echo off
rem NOTE: no "enabledelayedexpansion" — the messages below contain "[!]" and
rem delayed expansion would swallow the exclamation marks. Variables set inside
rem the for/f loops are read after the loop with %VAR%, which works without it.
setlocal
title Imprint Production - Startup (Network + Public)

rem ================= paths (edit here if software moves) =================
set "ROOT=C:\ImprintProduction"
set "APP=%ROOT%\application"
set "LOGS=%ROOT%\logs"
set "URLFILE=%ROOT%\current-tunnel-url.txt"
set "CFD=%ROOT%\cloudflared\cloudflared.exe"
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=C:\xampp1\php\php.exe"
set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
if not exist "%MYSQLD%" set "MYSQLD=C:\xampp1\mysql\bin\mysqld.exe"
set "MYSQLADMIN=C:\xampp\mysql\bin\mysqladmin.exe"
if not exist "%MYSQLADMIN%" set "MYSQLADMIN=C:\xampp1\mysql\bin\mysqladmin.exe"
set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
if not exist "%MYSQL_INI%" set "MYSQL_INI=C:\xampp1\mysql\bin\my.ini"
set "PORT=8000"
rem ========================================================================

rem This is the EVERYTHING launcher: the app is served to the office network
rem AND through the Cloudflare tunnel at the same time.
rem   - staff on the office Wi-Fi use the fast local address
rem   - clients (design questionnaire links) use the public address
rem Binding to 0.0.0.0 covers 127.0.0.1 too, which is what the tunnel points at,
rem so one Laravel process serves both.
rem
rem The other two launchers still exist:
rem   start-offline.bat  - office network only, no internet needed
rem   start-imprint.bat  - public tunnel only (localhost binding)

if not exist "%LOGS%" mkdir "%LOGS%"

echo ==========================================================
echo   IMPRINT PRODUCTION - STARTING (NETWORK + PUBLIC)
echo ==========================================================
echo.

rem ---------- 1/4 MySQL ----------
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "mysqld.exe" >nul

if errorlevel 1 (
    echo [1/4] Starting MySQL...

    start "Imprint MySQL" /MIN "%MYSQLD%" ^
        --defaults-file="%MYSQL_INI%" ^
        --standalone
) else (
    echo [1/4] MySQL is already running.
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

rem ---------- 2/4 Laravel, bound to the whole network ----------
powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue) { exit 1 } else { exit 0 }"

rem Exit code tells us not just "in use" but WHICH address it is bound to:
rem   0 = free, 2 = bound to all interfaces (LAN ok), 3 = localhost only (LAN blind)
powershell -NoProfile -Command "$c = @(Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue); if ($c.Count -eq 0) { exit 0 }; if ($c.LocalAddress -contains '0.0.0.0' -or $c.LocalAddress -contains '::') { exit 2 } else { exit 3 }"

if errorlevel 3 (
    echo [2/4] [!] Laravel is running on port %PORT% but LOCALHOST ONLY.
    echo           Other computers on the office network CANNOT reach it.
    echo           This happens when it was started by start-imprint.bat.
    echo.
    echo           Close the "Imprint Laravel" window ^(or run stop-imprint.bat^)
    echo           and run this file again to serve the network as well.
    echo.
    goto :laravelcheck
)

if errorlevel 2 (
    echo [2/4] Laravel is already serving the whole network - reusing it.
    goto :laravelcheck
)

echo [2/4] Starting Laravel on the network ^(0.0.0.0:%PORT%^) ...

start "Imprint Laravel (LAN)" /MIN cmd /c ^
""%PHP%" "%APP%\artisan" serve --host=0.0.0.0 --port=%PORT% > "%LOGS%\laravel.log" 2>&1"

:laravelcheck

powershell -NoProfile -Command "foreach($i in 1..30){ try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:%PORT%/up' -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch {}; Start-Sleep -Seconds 1 }; exit 1"

if errorlevel 1 (
    echo ERROR: Laravel did not respond on port %PORT%.
    echo Check: %LOGS%\laravel.log
    pause
    exit /b 1
)

echo       Laravel is up.

rem ---------- 3/4 Firewall so other PCs can reach us ----------
netsh advfirewall firewall show rule name="Imprint LAN %PORT%" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Imprint LAN %PORT%" dir=in action=allow protocol=TCP localport=%PORT% >nul 2>&1
    if errorlevel 1 (
        echo [3/4] [!] Could not add the firewall rule automatically.
        echo           Right-click this file and "Run as administrator" ONCE,
        echo           otherwise other computers cannot connect.
    ) else (
        echo [3/4] Firewall opened for port %PORT%.
    )
) else (
    echo [3/4] Firewall rule already present.
)

rem ---------- 4/4 Cloudflare Quick Tunnel (public address for clients) ----------
tasklist /FI "IMAGENAME eq cloudflared.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "cloudflared.exe" >nul

if not errorlevel 1 (
    echo [4/4] Quick Tunnel is already running - keeping the current address.
) else (
    echo [4/4] Starting Cloudflare Quick Tunnel...

    rem Fresh log so the address detector cannot read a previous run's URL.
    break > "%LOGS%\cloudflared.log"

    start "Imprint Tunnel" /MIN cmd /c ^
    ""%CFD%" tunnel --url http://127.0.0.1:%PORT% > "%LOGS%\cloudflared.log" 2>&1"
)

echo.
echo Waiting for the public address (up to 60 seconds)...

set "TUNNEL_URL="

for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-tunnel-url.ps1"`) do (
    set "TUNNEL_URL=%%i"
)

if "%TUNNEL_URL%"=="ERROR" set "TUNNEL_URL="

rem ---------- Find this PC's network address ----------
set "LANIP="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-lan-ip.ps1"`) do (
    set "LANIP=%%i"
)
if "%LANIP%"=="ERROR" set "LANIP="

echo.
echo ==========================================================
echo   IMPRINT PRODUCTION IS RUNNING
echo ==========================================================
echo.

if not "%LANIP%"=="" (
    echo   STAFF - on the office network / Wi-Fi:
    echo.
    echo     http://%LANIP%:%PORT%
    echo.
) else (
    echo   STAFF - could not detect this PC's network address.
    echo   Run  ipconfig  and use  http://YOUR-IP:%PORT%
    echo.
)

echo ----------------------------------------------------------
echo.

if not "%TUNNEL_URL%"=="" (
    echo   CLIENTS / OFF-SITE - public address:
    echo.
    echo     %TUNNEL_URL%
    echo.
    echo   Client design-questionnaire links use this address
    echo   automatically, even when you are on the office network.
) else (
    echo   [!] NO PUBLIC ADDRESS.
    echo.
    echo   The Cloudflare tunnel did not start, so the system is
    echo   reachable on the office network ONLY.
    echo.
    echo   Client design-questionnaire links will NOT work until
    echo   the tunnel is running - the design brief page shows a
    echo   warning instead of giving out an unusable link.
    echo.
    echo   Check internet access and %LOGS%\cloudflared.log
)

echo.
echo ==========================================================
echo.
echo   SERVER COMPUTER ONLY:  http://127.0.0.1:%PORT%
echo.
echo   Public link saved to:
echo   %URLFILE%
echo.
echo   Keep these minimized windows open:
echo   "Imprint MySQL"
echo   "Imprint Laravel (LAN)"
if not "%TUNNEL_URL%"=="" echo   "Imprint Tunnel"
echo.
echo   Closing them stops the system.
echo.
echo   NOTE: the Cloudflare address changes every time the tunnel
echo   restarts. The app picks up the new one automatically.
echo ==========================================================
echo.

pause
exit /b 0
