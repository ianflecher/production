@echo off
title Imprint DEV - Restart (port 8001)

echo Restarting the Imprint DEV copy (port 8001)...
echo The live shop on port 8000 is not touched.
echo A NEW public address will be generated - it changes on every restart.
echo.

call "%~dp0stop-imprint.bat" /nopause
timeout /t 3 /nobreak >nul
call "%~dp0start-all.bat"
