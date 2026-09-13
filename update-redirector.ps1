# Keeps one stable link pointing at whatever address the tunnel has today.
#
# A quick tunnel gets a new address every time it restarts, and it restarts
# about hourly. Anything a client saved yesterday is dead. The shop cannot fix
# that with a permanent address of its own without moving imprintcustoms.ph
# onto Cloudflare's DNS, and that domain carries the Google Workspace mail.
#
# So instead: one page that never moves, which forwards to the address of the
# moment. Clients keep the stable link; this rewrites where it points.
#
# It publishes to a GitHub Pages repository, which costs nothing. The page
# carries the current tunnel address in the open - that address is already
# handed to clients, and every brief behind it needs its own token, so the
# page gives away nothing that was private.
#
# Run on a timer. It does nothing at all unless the address has changed, so
# most runs end without touching the repository.

param(
    [string]$Root = "C:\ImprintProduction",
    # A clone of the GitHub Pages repo. Created by install-redirector.ps1.
    [string]$PagesRepo = "C:\ImprintProduction\redirector",
    [string]$UrlFile = "C:\ImprintProduction\current-tunnel-url.txt"
)

$ErrorActionPreference = 'Stop'

# Windows PowerShell turns ANY stderr line from a native .exe into a
# terminating error while ErrorActionPreference is Stop, and git talks on
# stderr when it is perfectly happy. This runs unattended every ten minutes,
# so the exit code is the answer and the noise is just noise.
function Invoke-Native {
    param([Parameter(Mandatory)][string]$File, [string[]]$Arguments = @())

    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $File @Arguments 2>&1 | Out-String
        return [pscustomobject]@{ Code = $LASTEXITCODE; Output = $output }
    } finally {
        $ErrorActionPreference = $previous
    }
}

$log = Join-Path $Root "logs\redirector.log"

function Write-Line([string]$message) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    Add-Content -Path $log -Value $line -Encoding utf8
    Write-Output $line
}

if ((Test-Path $log) -and ((Get-Item $log).Length -gt 512KB)) {
    Get-Content $log -Tail 500 | Set-Content $log -Encoding utf8
}

if (-not (Test-Path (Join-Path $PagesRepo ".git"))) {
    Write-Line "no redirector checkout at $PagesRepo - run install-redirector.ps1 once"
    exit 1
}

if (-not (Test-Path $UrlFile)) {
    Write-Line "no tunnel address on file yet - nothing to point at"
    exit 0
}

$url = (Get-Content $UrlFile -Raw).Trim()

if (-not $url -or $url -eq "ERROR") {
    Write-Line "no usable tunnel address on file - leaving the last one in place"
    exit 0
}

# Never publish an address that does not answer. The whole point of the page is
# that the link works; pointing it at a dead tunnel is worse than leaving it on
# the previous one, which at least worked an hour ago.
try {
    $probe = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15
    if ([int]$probe.StatusCode -ge 400) { throw "status $($probe.StatusCode)" }
} catch {
    Write-Line "the address on file does not answer ($url) - not publishing it"
    exit 0
}

$indexPath = Join-Path $PagesRepo "index.html"
$current = ""
if (Test-Path $indexPath) { $current = Get-Content $indexPath -Raw }

# Already pointing there: nothing to do, and no commit for the sake of one.
if ($current -match [regex]::Escape($url)) {
    exit 0
}

$stamp = Get-Date -Format 'd MMM yyyy, h:mm tt'

# A meta refresh AND a script AND a plain link: the first two do the work, and
# the link is what a client sees if both are blocked or the page is opened
# somewhere that runs neither.
$html = @"
<!doctype html>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<meta http-equiv="refresh" content="0; url=$url">
<title>Imprint Customs</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 3rem auto; max-width: 32rem;
         padding: 0 1.2rem; color: #17202E; line-height: 1.5; }
  a { color: #2563eb; }
  small { color: #94A0AE; }
</style>
<h1>Imprint Customs</h1>
<p>Taking you to the site&hellip;</p>
<p>If nothing happens, <a href="$url">open it here</a>.</p>
<p><small>Address updated $stamp</small></p>
<script>location.replace("$url");</script>
"@

Set-Content -Path $indexPath -Value $html -Encoding utf8

Push-Location $PagesRepo
try {
    Invoke-Native git @('add', 'index.html') | Out-Null

    $committed = Invoke-Native git @('-c', 'user.name=Imprint Tunnel',
        '-c', 'user.email=noreply@imprintcustoms.ph',
        'commit', '-m', "Point at $url", '--quiet')
    if ($committed.Code -ne 0) { throw "commit failed: $($committed.Output)" }

    $pushed = Invoke-Native git @('push', '--quiet')
    if ($pushed.Code -ne 0) { throw "push failed: $($pushed.Output)" }

    Write-Line "redirector now points at $url"
} catch {
    Write-Line "ERROR publishing: $($_.Exception.Message)"
    exit 1
} finally {
    Pop-Location
}
