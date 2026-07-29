@echo off
title Imprint Production - Restart

echo Restarting Imprint Production...
echo A NEW public address will be generated - remember to send it to the agents.
echo.

call "%~dp0stop-imprint.bat" /nopause
timeout /t 3 /nobreak >nul
call "%~dp0start-imprint.bat"
