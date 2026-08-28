@echo off
rem ============================================================================
rem  DEV copy of start-offline: the dev site on the office network, port 8001,
rem  no public tunnel and no internet needed.
rem
rem  Same code path as start-all.bat - it just skips the tunnel step, so there
rem  is only one launcher to keep correct.
rem ============================================================================
call "%~dp0start-all.bat" LAN
