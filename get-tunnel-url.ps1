# Detects the current https://*.trycloudflare.com address from the cloudflared
# log and writes it to current-tunnel-url.txt. Used by start-imprint.bat.
#
# A quick tunnel gets a fresh address every start, and the log keeps every one
# it has ever had, so the LAST match is the live one - the first is history.
#
# If our own log has nothing (the tunnel was started by hand, outside this
# script, and is logging somewhere else), fall back to the newest cloudflared
# log Windows has lying about, so a hand-started tunnel is still found.
#
# The fallback must be a log of a tunnel that is running NOW. The launchers
# empty our own log and start cloudflared, then call this straight away - so
# for the first few seconds our log is legitimately empty while the address is
# still being fetched. The fallback used to win that race and hand out the
# address of THIS MORNING'S tunnel, which is dead: staff were sent a link that
# answered 530. So it is only consulted after the primary log has had its
# chance, and only when the file is still being written to.
param(
    [string]$LogPath = "C:\ImprintProduction\logs\cloudflared.log",
    [string]$OutFile = "C:\ImprintProduction\current-tunnel-url.txt",
    [int]$TimeoutSec = 60
)

# An address is only handed out once it ANSWERS. Reading the newest line in the
# log proves cloudflared once announced that address, not that it still works -
# on 2026-09-11 the newest line in the log was a tunnel Cloudflare had already
# reclaimed. Staff get whatever this script writes, so it is the last place that
# can stop a dead link going out, and it waits rather than publish one.
$pattern = 'https://[a-z0-9-]+\.trycloudflare\.com'

function Test-TunnelUrl([string]$url) {
    try {
        $r = Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
        return ([int]$r.StatusCode -lt 400)
    } catch {
        $resp = $_.Exception.Response
        if ($resp) { return ([int]$resp.StatusCode -lt 400) }
        return $false
    }
}

function Get-LatestUrl([string]$path) {
    if (-not (Test-Path $path)) { return $null }
    $content = Get-Content $path -Raw -ErrorAction SilentlyContinue
    if (-not $content) { return $null }
    $m = [regex]::Matches($content, $pattern)
    if ($m.Count -eq 0) { return $null }
    return $m[$m.Count - 1].Value
}

$started  = Get-Date
$deadline = $started.AddSeconds($TimeoutSec)
$url = $null

# Our own log is the only one trusted for the first 20 seconds, and a stale
# fallback is never trusted: a tunnel that is up is still writing to its log.
$primaryOnlyUntil = $started.AddSeconds(20)
$freshEnough      = $started.AddMinutes(-3)

while ((Get-Date) -lt $deadline) {
    $candidate = Get-LatestUrl $LogPath

    if (-not $candidate -and (Get-Date) -ge $primaryOnlyUntil) {
        $fallback = Get-ChildItem "$env:TEMP\imprint-cloudflare-*.log" -ErrorAction SilentlyContinue |
                    Where-Object { $_.LastWriteTime -ge $freshEnough } |
                    Sort-Object LastWriteTime -Descending | Select-Object -First 1
        if ($fallback) {
            $candidate = Get-LatestUrl $fallback.FullName
        }
    }

    # A brand new address is not reachable the instant it is announced, so a
    # failure here is not final - the loop keeps asking until the deadline.
    if ($candidate -and (Test-TunnelUrl $candidate)) {
        $url = $candidate
        break
    }

    Start-Sleep -Seconds 2
}

if ($url) {
    $url | Out-File $OutFile -Encoding ascii
    Write-Output $url
    exit 0
}

Write-Output "ERROR"
exit 1
