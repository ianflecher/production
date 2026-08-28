@echo off
rem ============================================================================
rem  IMPRINT - DEVELOPMENT COPY - START
rem
rem  Works on a fresh clone: installs what's missing, prepares the dev database,
rem  then opens the dev site. Safe to run again any time - each step is skipped
rem  when it has already been done, and it never touches existing data.
rem
rem  This file used to be the live one, copied here unchanged. It took ROOT from
rem  its own folder (this folder, correctly) but kept PORT=8000, bound to
rem  0.0.0.0, and named imprint_production in its SQL. So it served THIS code on
rem  the SHOP'S port, to the whole office network and through the tunnel, while
rem  deciding whether to seed by counting the live users table.
rem
rem  Now: port 8001, this machine only, imprint_dev.
rem ============================================================================
setlocal
title Imprint DEV - Start (port 8001)

set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"
set "APP=%ROOT%\application"
set "LOGS=%ROOT%\logs"
set "PORT=8001"
set "DBNAME=imprint_dev"

rem ---- Find PHP and MySQL.
rem      C:\xampp is tried first. An older C:\xampp1 can still be
rem      sitting on disk after a reinstall, and existing is not the same
rem      as working: preferring it pointed the app at a dead database.
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=C:\xampp1\php\php.exe"
if not exist "%PHP%" (
    where php >nul 2>&1 && set "PHP=php"
)

set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
if not exist "%MYSQLD%" set "MYSQLD=C:\xampp1\mysql\bin\mysqld.exe"
set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
if not exist "%MYSQL%" set "MYSQL=C:\xampp1\mysql\bin\mysql.exe"
set "MYSQLADMIN=C:\xampp\mysql\bin\mysqladmin.exe"
if not exist "%MYSQLADMIN%" set "MYSQLADMIN=C:\xampp1\mysql\bin\mysqladmin.exe"
set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
if not exist "%MYSQL_INI%" set "MYSQL_INI=C:\xampp1\mysql\bin\my.ini"

if not exist "%LOGS%" mkdir "%LOGS%"

echo ==========================================================
echo   IMPRINT - DEVELOPMENT COPY
echo   The live shop is untouched on port 8000.
echo ==========================================================
echo.

rem ---------- 0. Is PHP there at all? ----------
"%PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo ERROR: PHP was not found.
    echo.
    echo Install XAMPP ^(https://www.apachefriends.org^) and run this again.
    echo Expected at C:\xampp\php\php.exe
    pause
    exit /b 1
)

rem ---------- 1. Dependencies ----------
if exist "%APP%\vendor\autoload.php" (
    echo [1/6] Dependencies already installed.
) else (
    echo [1/6] Installing dependencies ^(first run, this takes a minute^)...
    where composer >nul 2>&1
    if errorlevel 1 (
        echo.
        echo ERROR: Composer was not found.
        echo Install it from https://getcomposer.org/download and run this again.
        pause
        exit /b 1
    )
    pushd "%APP%"
    call composer install --no-interaction --prefer-dist
    popd
    if not exist "%APP%\vendor\autoload.php" (
        echo ERROR: Dependencies failed to install. See the messages above.
        pause
        exit /b 1
    )
)

rem ---------- 1b. Writable folders Laravel needs at runtime ----------
rem Belt and braces: these are tracked, but a zip download or an over-zealous
rem cleanup can lose them, and without storage\framework\views every page 500s.
for %%d in (
    "storage\framework\views"
    "storage\framework\sessions"
    "storage\framework\cache\data"
    "storage\logs"
    "storage\app\public"
    "bootstrap\cache"
) do if not exist "%APP%\%%~d" mkdir "%APP%\%%~d" 2>nul

rem ---------- 2. Settings file ----------
if exist "%APP%\.env" (
    echo [2/6] Settings file already present.
) else (
    echo [2/6] Creating the settings file from .env.example...
    copy /Y "%APP%\.env.example" "%APP%\.env" >nul
    "%PHP%" "%APP%\artisan" key:generate --force
    echo.
    echo       [!] Point DB_DATABASE at %DBNAME% and APP_URL at port %PORT%
    echo           in %APP%\.env before going further, or this copy will
    echo           share the live database.
    echo.
    pause
)

rem ---------- 3. MySQL ----------
rem Shared with the live site - the same server, a different database on it.
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | "%SystemRoot%\System32\find.exe" /I "mysqld.exe" >nul
if errorlevel 1 (
    echo [3/6] Starting MySQL...
    if not exist "%MYSQLD%" (
        echo.
        echo ERROR: MySQL was not found. Install XAMPP and run this again.
        pause
        exit /b 1
    )
    start "Imprint MySQL" /MIN "%MYSQLD%" --defaults-file="%MYSQL_INI%" --standalone
) else (
    echo [3/6] MySQL is already running.
)

set /a TRIES=0
:waitmysql
"%MYSQLADMIN%" -u root ping >nul 2>&1
if not errorlevel 1 goto mysqlok
set /a TRIES+=1
if %TRIES% GEQ 30 (
    echo ERROR: MySQL did not start. Open XAMPP and start MySQL manually.
    pause
    exit /b 1
)
timeout /t 1 /nobreak >nul
goto waitmysql
:mysqlok
echo       MySQL is up.

rem ---------- 4. Database ----------
echo [4/6] Preparing the %DBNAME% database...
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS %DBNAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >nul 2>&1
"%PHP%" "%APP%\artisan" migrate --force
if errorlevel 1 (
    echo ERROR: The database could not be prepared. See the messages above.
    pause
    exit /b 1
)

rem ---------- 5. Staff accounts + uploads link ----------
echo [5/6] Checking staff accounts...
rem Only seed an EMPTY system, and count THIS copy's users - counting the live
rem ones decided dev's seeding by production's state.
set "USERCOUNT=0"
for /f %%c in ('""%MYSQL%" -u root -N -e "SELECT COUNT(*) FROM %DBNAME%.users;" 2^>nul"') do set "USERCOUNT=%%c"

if "%USERCOUNT%"=="0" (
    echo       First run - creating the staff accounts...
    "%PHP%" "%APP%\artisan" db:seed --class=UserSeeder --force
) else (
    echo       %USERCOUNT% staff accounts already set up - left alone.
)
if not exist "%APP%\public\storage" (
    "%PHP%" "%APP%\artisan" storage:link >nul 2>&1
)

rem ---------- 6. Serve ----------
rem 0.0.0.0 so the office network can reach this copy as well; the tunnel
rem points at this machine's loopback, which 0.0.0.0 covers too. Use
rem start-dev.bat for a this-machine-only run.
powershell -NoProfile -Command "if (Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue) { exit 1 } else { exit 0 }"
if errorlevel 1 (
    echo [6/6] Already running on port %PORT%.
) else (
    echo [6/6] Starting the dev site on port %PORT% ^(whole network^)...
    netsh advfirewall firewall show rule name="Imprint DEV LAN %PORT%" >nul 2>&1 || netsh advfirewall firewall add rule name="Imprint DEV LAN %PORT%" dir=in action=allow protocol=TCP localport=%PORT% >nul 2>&1
    start "Imprint DEV Server" /MIN cmd /c ""%PHP%" "%APP%\artisan" serve --host=0.0.0.0 --port=%PORT% > "%LOGS%\laravel.log" 2>&1"
)

powershell -NoProfile -Command "foreach($i in 1..30){ try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:%PORT%/up' -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch {}; Start-Sleep -Seconds 1 }; exit 1"
if errorlevel 1 (
    echo ERROR: The dev site did not start. See %LOGS%\laravel.log
    pause
    exit /b 1
)

echo.
echo ==========================================================
echo   DEV COPY RUNNING
echo ==========================================================
echo.
echo   On this computer :  http://127.0.0.1:%PORT%
echo   Database         :  %DBNAME%
echo.
set "LANIP="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-lan-ip.ps1"`) do set "LANIP=%%i"
if not "%LANIP%"=="ERROR" if not "%LANIP%"=="" echo   Office network   :  http://%LANIP%:%PORT%
echo.
echo   No public address yet - run start-all.bat for the LAN
echo   plus a Cloudflare tunnel. The live shop is separate, on
echo   port 8000.
echo.
echo   Keep the minimised "Imprint MySQL" and "Imprint DEV Server"
echo   windows open - closing them stops the dev site.
echo ==========================================================
echo.

start "" "http://127.0.0.1:%PORT%"
pause
exit /b 0
