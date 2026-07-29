@echo off
setlocal
title Imprint Production - Startup

rem ================= paths (edit here if software moves) =================
set "ROOT=C:\ImprintProduction"
set "APP=%ROOT%\application"
set "LOGS=%ROOT%\logs"
set "URLFILE=%ROOT%\current-tunnel-url.txt"
set "CFD=%ROOT%\cloudflared\cloudflared.exe"
set "PHP=C:\xampp1\php\php.exe"
set "MYSQLD=C:\xampp1\mysql\bin\mysqld.exe"
set "MYSQLADMIN=C:\xampp1\mysql\bin\mysqladmin.exe"
set "MYSQL_INI=C:\xampp1\mysql\bin\my.ini"
set "PORT=8000"
rem ========================================================================

if not exist "%LOGS%" mkdir "%LOGS%"

echo ==========================================================
echo   IMPRINT PRODUCTION - STARTING ALL SERVICES
echo ==========================================================
echo.

rem ---------- 1/3 MySQL ----------
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "mysqld.exe" >nul

if errorlevel 1 (
    echo [1/3] Starting MySQL...

    start "Imprint MySQL" /MIN "%MYSQLD%" ^
        --defaults-file="%MYSQL_INI%" ^
        --standalone
) else (
    echo [1/3] MySQL is already running.
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

rem ---------- 2/3 Laravel ----------
powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue) { exit 1 } else { exit 0 }"

if errorlevel 1 (
    echo [2/3] Laravel is already running on port %PORT%.
) else (
    echo [2/3] Starting Laravel on http://127.0.0.1:%PORT% ...

    start "Imprint Laravel" /MIN cmd /c ^
    ""%PHP%" "%APP%\artisan" serve --host=127.0.0.1 --port=%PORT% > "%LOGS%\laravel.log" 2>&1"
)

rem Wait for Laravel to respond.
powershell -NoProfile -Command "foreach($i in 1..30){ try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:%PORT%/up' -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch {}; Start-Sleep -Seconds 1 }; exit 1"

if errorlevel 1 (
    echo ERROR: Laravel did not respond on port %PORT%.
    echo Check: %LOGS%\laravel.log
    pause
    exit /b 1
)

echo       Laravel is up.

rem ---------- Queue worker: enable when needed ----------
rem start "Imprint Queue" /MIN cmd /c ^
rem ""%PHP%" "%APP%\artisan" queue:work --tries=3 > "%LOGS%\queue.log" 2>&1"

rem ---------- 3/3 Cloudflare Quick Tunnel ----------
tasklist /FI "IMAGENAME eq cloudflared.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "cloudflared.exe" >nul

if not errorlevel 1 (
    echo [3/3] Quick Tunnel is already running - keeping the current address.
) else (
    echo [3/3] Starting Cloudflare Quick Tunnel...

    start "Imprint Tunnel" /MIN cmd /c ^
    ""%CFD%" tunnel --url http://127.0.0.1:%PORT% > "%LOGS%\cloudflared.log" 2>&1"
)

echo.
echo Waiting for the public address (up to 60 seconds)...

set "TUNNEL_URL="

for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-tunnel-url.ps1"`) do (
    set "TUNNEL_URL=%%i"
)

if "%TUNNEL_URL%"=="" goto :urlfail
if "%TUNNEL_URL%"=="ERROR" goto :urlfail

echo.
echo ==========================================================
echo   IMPRINT PRODUCTION IS RUNNING
echo ==========================================================
echo.
echo   PUBLIC ADDRESS - send this link to employees:
echo.
echo   %TUNNEL_URL%
echo.
echo ----------------------------------------------------------
echo.
echo   SERVER COMPUTER ONLY:
echo.
echo   http://127.0.0.1:%PORT%
echo.
echo ==========================================================
echo.
echo   Public link saved to:
echo   %URLFILE%
echo.
echo   Logs folder:
echo   %LOGS%
echo.
echo   IMPORTANT:
echo   The Cloudflare Quick Tunnel address changes whenever
echo   the tunnel is restarted.
echo.
echo   Always send employees the current Cloudflare address.
echo.
echo   Keep these minimized windows open:
echo   "Imprint MySQL"
echo   "Imprint Laravel"
echo   "Imprint Tunnel"
echo.
echo   Closing them stops the system.
echo ==========================================================
echo.

pause
exit /b 0

:urlfail
echo.
echo ERROR: Could not detect the Cloudflare tunnel address
echo within 60 seconds.
echo.
echo Check:
echo %LOGS%\cloudflared.log
echo.
echo Also check that this computer has internet access.
pause
exit /b 1