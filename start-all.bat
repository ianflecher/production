@echo off
rem NOTE: no "enabledelayedexpansion" — the messages below contain "[!]" and
rem delayed expansion would swallow the exclamation marks.
setlocal
title Imprint DEV - Startup (Network + Public, port 8001)

rem ============================================================================
rem  DEV copy of start-all: serves THIS folder's code to the office network AND
rem  through its own Cloudflare quick tunnel, on port 8001.
rem
rem  It is still the dev copy. Everything below is 8001 / imprint_dev / this
rem  folder's logs and tunnel file — the live shop keeps port 8000 and its own
rem  tunnel, and the two never share an address.
rem
rem  Two things to know before handing the address out:
rem    - anything on the office network can now reach a half-finished branch
rem    - client questionnaire links generated HERE point at the DEV tunnel,
rem      because PublicUrl reads this folder's current-tunnel-url.txt
rem ============================================================================

set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"
set "APP=%ROOT%\application"
set "LOGS=%ROOT%\logs"
set "URLFILE=%ROOT%\current-tunnel-url.txt"
set "PORT=8001"

rem Mode comes from the launcher that called us:
rem   (none)  both - office network AND public tunnel
rem   LAN     office network only, no tunnel
rem   TUNNEL  public tunnel only, bound to this machine
set "MODE=%~1"
set "BINDHOST=0.0.0.0"
if /I "%MODE%"=="TUNNEL" set "BINDHOST=127.0.0.1"

rem cloudflared: this folder's copy if someone put one here, otherwise the
rem live install's binary. Sharing the executable is fine - each run is its own
rem process with its own --url and its own log.
set "CFD=%ROOT%\cloudflared\cloudflared.exe"
if not exist "%CFD%" set "CFD=C:\ImprintProduction\cloudflared\cloudflared.exe"

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=C:\xampp1\php\php.exe"
set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
if not exist "%MYSQLD%" set "MYSQLD=C:\xampp1\mysql\bin\mysqld.exe"
set "MYSQLADMIN=C:\xampp\mysql\bin\mysqladmin.exe"
if not exist "%MYSQLADMIN%" set "MYSQLADMIN=C:\xampp1\mysql\bin\mysqladmin.exe"
set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
if not exist "%MYSQL_INI%" set "MYSQL_INI=C:\xampp1\mysql\bin\my.ini"

if not exist "%LOGS%" mkdir "%LOGS%"

echo ==========================================================
echo   IMPRINT DEV - STARTING (NETWORK + PUBLIC) - PORT %PORT%
echo   The live shop is untouched on port 8000.
echo ==========================================================
echo.

rem ---------- 1/4 MySQL (shared server, imprint_dev database) ----------
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "mysqld.exe" >nul
if errorlevel 1 (
    echo [1/4] Starting MySQL...
    start "Imprint MySQL" /MIN "%MYSQLD%" --defaults-file="%MYSQL_INI%" --standalone
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
    pause
    exit /b 1
)
timeout /t 1 /nobreak >nul
goto :mysqlwait
:mysqlok
echo       MySQL is up.

rem ---------- 2/4 Laravel, bound to the whole network ----------
rem Exit code says not just "in use" but WHICH address it is bound to:
rem   0 = free, 2 = all interfaces (LAN ok), 3 = localhost only (LAN blind)
powershell -NoProfile -Command "$c = @(Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue); if ($c.Count -eq 0) { exit 0 }; if ($c.LocalAddress -contains '0.0.0.0' -or $c.LocalAddress -contains '::') { exit 2 }; exit 3"

if errorlevel 3 if /I "%MODE%"=="TUNNEL" (
    echo [2/4] Dev site is already running on this machine - reusing it.
    goto :laravelcheck
)

if errorlevel 3 (
    echo [2/4] [!] The dev site is running on port %PORT% but LOCALHOST ONLY.
    echo           Other computers cannot reach it. That is what start-dev.bat
    echo           does - close its window ^(or run stop-imprint.bat^) and run
    echo           this file again.
    echo.
    goto :laravelcheck
)

if errorlevel 2 (
    echo [2/4] Dev site is already serving the whole network - reusing it.
    goto :laravelcheck
)

echo [2/4] Starting the dev site on %BINDHOST%:%PORT% ...
start "Imprint DEV Server" /MIN cmd /c ^
""%PHP%" "%APP%\artisan" serve --host=%BINDHOST% --port=%PORT% > "%LOGS%\laravel.log" 2>&1"

:laravelcheck
powershell -NoProfile -Command "foreach($i in 1..30){ try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:%PORT%/up' -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch {}; Start-Sleep -Seconds 1 }; exit 1"
if errorlevel 1 (
    echo ERROR: The dev site did not respond on port %PORT%.
    echo Check: %LOGS%\laravel.log
    pause
    exit /b 1
)
echo       Dev site is up.

rem ---------- 3/4 Firewall so other PCs can reach us ----------
if /I "%MODE%"=="TUNNEL" (
    echo [3/4] Public-only run - no firewall rule needed.
    goto :afterFirewall
)

netsh advfirewall firewall show rule name="Imprint DEV LAN %PORT%" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Imprint DEV LAN %PORT%" dir=in action=allow protocol=TCP localport=%PORT% >nul 2>&1
    if errorlevel 1 (
        rem Adding a rule needs administrator. Without one the site still comes
        rem up and still works on THIS machine, so a quiet warning here scrolls
        rem past and the office is left wondering why nothing loads. Stop.
        echo.
        echo   ############################################################
        echo   #  [!] THE OFFICE NETWORK CANNOT REACH THIS SITE YET.      #
        echo   ############################################################
        echo.
        echo   The firewall rule for port %PORT% could not be added -
        echo   that needs administrator rights.
        echo.
        echo   Fix it ONCE, either way:
        echo     - close this window, right-click this file,
        echo       "Run as administrator", and run it again
        echo     - or run this in an admin Command Prompt:
        echo.
        echo       netsh advfirewall firewall add rule name="Imprint DEV LAN %PORT%" dir=in action=allow protocol=TCP localport=%PORT%
        echo.
        echo   The rule is permanent - you only do this once.
        echo   Everything else below still works on this computer.
        echo.
        pause
    ) else (
        echo [3/4] Firewall opened for port %PORT%.
    )
) else (
    echo [3/4] Firewall rule already present.
)

:afterFirewall

rem ---------- 4/4 Cloudflare Quick Tunnel for THIS port ----------
if /I "%MODE%"=="LAN" (
    echo [4/4] Office-network run - no public tunnel.
    goto :noTunnel
)

rem Cannot go by "is cloudflared.exe running" the way the live launcher does -
rem the live tunnel is usually up already and it points at 8000. Ask whether a
rem tunnel exists for OUR port instead.
powershell -NoProfile -Command "$p = @(Get-CimInstance Win32_Process -Filter \"Name='cloudflared.exe'\" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match ':%PORT%' }); if ($p.Count -gt 0) { exit 1 } else { exit 0 }"

if errorlevel 1 (
    echo [4/4] A tunnel for port %PORT% is already running - keeping its address.
) else (
    if not exist "%CFD%" (
        echo [4/4] [!] cloudflared.exe not found at:
        echo           %CFD%
        echo           No public address this run.
        goto :afterTunnel
    )
    echo [4/4] Starting Cloudflare Quick Tunnel for port %PORT%...
    rem Fresh log so the address detector cannot read a previous run's URL.
    break > "%LOGS%\cloudflared.log"
    start "Imprint DEV Tunnel" /MIN cmd /c ^
    ""%CFD%" tunnel --url http://127.0.0.1:%PORT% > "%LOGS%\cloudflared.log" 2>&1"
)

:afterTunnel
echo.
:noTunnel
if /I not "%MODE%"=="LAN" echo Waiting for the public address (up to 60 seconds)...

set "TUNNEL_URL="
if /I "%MODE%"=="LAN" goto :afterUrl
rem This folder's log and this folder's URL file - the live ones are separate.
for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-tunnel-url.ps1" -LogPath "%LOGS%\cloudflared.log" -OutFile "%URLFILE%"`) do (
    set "TUNNEL_URL=%%i"
)
:afterUrl
if "%TUNNEL_URL%"=="ERROR" set "TUNNEL_URL="

rem ---------- This PC's network address ----------
set "LANIP="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-lan-ip.ps1"`) do (
    set "LANIP=%%i"
)
if "%LANIP%"=="ERROR" set "LANIP="

echo.
echo ==========================================================
echo   IMPRINT DEV IS RUNNING (NETWORK + PUBLIC)
echo ==========================================================
echo.

if /I "%MODE%"=="TUNNEL" goto :skipLan

if not "%LANIP%"=="" (
    echo   On the office network:
    echo.
    echo     http://%LANIP%:%PORT%
    echo.
) else (
    echo   Could not detect this PC's network address.
    echo   Run  ipconfig  and use  http://YOUR-IP:%PORT%
    echo.
)

:skipLan
echo ----------------------------------------------------------
echo.

if not "%TUNNEL_URL%"=="" (
    echo   Public address for this DEV copy:
    echo.
    echo     %TUNNEL_URL%
    echo.
    echo   [!] Questionnaire links generated in this copy point HERE,
    echo       not at the live site. The address also changes every
    echo       time the tunnel restarts.
) else (
    echo   [!] NO PUBLIC ADDRESS - office network only.
    echo       Check internet access and %LOGS%\cloudflared.log
)

echo.
echo ==========================================================
echo.
echo   On this computer     :  http://127.0.0.1:%PORT%
echo   Database             :  imprint_dev
echo   Public link saved to :  %URLFILE%
echo.
echo   Keep these minimised windows open:
echo   "Imprint MySQL"
echo   "Imprint DEV Server"
if not "%TUNNEL_URL%"=="" echo   "Imprint DEV Tunnel"
echo.
echo   Closing them stops the dev site. The live shop on port
echo   8000 is not affected either way.
echo.
echo ==========================================================
echo.

start "" "http://127.0.0.1:%PORT%"
pause
exit /b 0
