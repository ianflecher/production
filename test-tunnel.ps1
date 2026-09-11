# Answers one question for the launchers: is OUR quick tunnel actually usable?
#
# The old check asked whether a cloudflared process existed for our port. A
# quick tunnel can lose its address while its process keeps running - Cloudflare
# reclaims the hostname, cloudflared sits retrying a registration that no longer
# exists, and a process check still says yes.
#
# On 2026-09-11 that is exactly what happened. The tunnel had been up since the
# previous morning; the machine ran out of ephemeral UDP ports, the QUIC control
# stream could not be re-established, and the address was reclaimed. The process
# stayed. So the launcher reported "already running", skipped starting a new
# tunnel, reprinted yesterday's address from the log, and staff were handed a
# link whose DNS no longer existed. Nothing in the startup noticed.
#
# So the question asked here is whether the ADDRESS answers, not whether a
# process is alive. Anything below 400 counts: the app redirects / to the login
# page, which is a 200 after the redirect is followed. A 5xx does not count -
# by this point in the launcher the app is already started, so a 502 or a 530
# is the edge telling us it cannot reach a tunnel, which is the very thing being
# tested for.
#
# A tunnel found dead is killed, so the caller is free to start a fresh one.
# Nothing is killed unless it is both ours - matched by port, because another
# project on this machine runs its own cloudflared - and proved dead.
#
# Writes 1 (usable) or 0 (not usable; anything dead has been cleared away).

param(
    [int]$Port = 8000,
    [string]$UrlFile = "C:\ImprintProduction\current-tunnel-url.txt"
)

function Test-TunnelUrl([string]$url) {
    try {
        $r = Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
        return ([int]$r.StatusCode -lt 400)
    } catch {
        # A reply at all means the hostname resolved and the edge answered; the
        # status still decides. No reply means no tunnel.
        $resp = $_.Exception.Response
        if ($resp) { return ([int]$resp.StatusCode -lt 400) }
        return $false
    }
}

$ours = @(
    Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like "*127.0.0.1:$Port*" }
)

if ($ours.Count -eq 0) { Write-Output 0; exit 0 }

$url = Get-Content $UrlFile -ErrorAction SilentlyContinue | Select-Object -First 1
if ($url) { $url = $url.Trim() }

# Running with no address on file is a tunnel we cannot vouch for, so it is
# treated as dead rather than left to be reported as fine.
if ($url -and (Test-TunnelUrl $url)) { Write-Output 1; exit 0 }

foreach ($p in $ours) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }

Write-Output 0
exit 0
