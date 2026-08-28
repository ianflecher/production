@echo off
rem ============================================================================
rem  DEV copy of start-imprint: the dev site published through its own
rem  Cloudflare quick tunnel on port 8001, bound to this machine only - the
rem  tunnel reaches 127.0.0.1, so the office network still cannot see it.
rem
rem  Same code path as start-all.bat - it just skips the LAN binding and the
rem  firewall rule.
rem ============================================================================
call "%~dp0start-all.bat" TUNNEL
