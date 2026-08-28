rem Only a tunnel pointing at OUR port counts - the live one is usually up and
rem points at 8000, which would otherwise read as a healthy dev tunnel.
powershell -NoProfile -Command "$p = @(Get-CimInstance Win32_Process -Filter \"Name='cloudflared.exe'\" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match ':8001' }); if ($p.Count -gt 0) { exit 0 } exit 1" && (echo Dev tunnel:    RUNNING) || (echo Dev tunnel:    NOT RUNNING - the public link is dead)

powershell -NoProfile -Command "$c = @(Get-NetTCPConnection -LocalPort 8001 -State Listen -ErrorAction SilentlyContinue); if ($c.LocalAddress -contains '0.0.0.0' -or $c.LocalAddress -contains '::') { exit 0 } exit 1" && (echo Office net:    REACHABLE - address below) || (echo Office net:    NO - this machine only)

set "LANIP="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\get-lan-ip.ps1"`) do set "LANIP=%%i"
if not "!LANIP!"=="ERROR" if not "!LANIP!"=="" echo Network addr:  http://!LANIP!:8001