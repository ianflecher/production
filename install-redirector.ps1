# Sets up the stable link, once per machine.
#
# Creates a small public GitHub Pages repository whose only job is to forward
# to the tunnel address of the moment, clones it here, publishes the first
# page, and registers the timer that keeps it current.
#
# Needs GitHub to be logged in first - that part cannot be automated and is not
# something a script should be doing on somebody's behalf:
#
#     gh auth login
#
# Then:
#
#     powershell -ExecutionPolicy Bypass -File C:\ImprintProduction\install-redirector.ps1
#
# The repository is PUBLIC, because GitHub Pages on a private repository is a
# paid feature. What it holds is one page naming the current tunnel address -
# the same address clients are already given, and every brief behind it still
# needs its own token. No shop data goes near it.

param(
    [string]$Root = "C:\ImprintProduction",
    [string]$RepoName = "imprint-link",
    [string]$PagesRepo = "C:\ImprintProduction\redirector",
    [int]$EveryMinutes = 10,
    [string]$RunAs = $env:USERNAME
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw "the GitHub CLI is not installed - https://cli.github.com"
}

& gh auth status *> $null
if ($LASTEXITCODE -ne 0) {
    throw "GitHub is not logged in on this machine. Run:  gh auth login"
}

$owner = (& gh api user --jq .login).Trim()
if (-not $owner) { throw "could not read the GitHub account name" }

$slug = "$owner/$RepoName"

# Make the repository only if it is not already there, so this is safe to run
# again after a half-finished attempt.
& gh repo view $slug *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Output "creating $slug (public, Pages needs it)"
    & gh repo create $slug --public --description "Stable link to the Imprint Production system" --confirm *> $null
    if ($LASTEXITCODE -ne 0) { throw "could not create $slug" }
} else {
    Write-Output "$slug already exists - using it"
}

if (-not (Test-Path (Join-Path $PagesRepo ".git"))) {
    Write-Output "cloning into $PagesRepo"
    & gh repo clone $slug $PagesRepo *> $null
    if ($LASTEXITCODE -ne 0) { throw "could not clone $slug" }
}

Push-Location $PagesRepo
try {
    # A first commit, so Pages has something to build and the updater has a
    # branch to push onto.
    if (-not (Test-Path "index.html")) {
        Set-Content -Path "index.html" -Encoding utf8 -Value @"
<!doctype html>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title>Imprint Customs</title>
<p>Setting up&hellip;</p>
"@
        git add index.html | Out-Null
        git -c user.name="Imprint Tunnel" -c user.email="noreply@imprintcustoms.ph" `
            commit -m "The stable link" --quiet
        git push --quiet origin HEAD 2>&1 | Out-Null
    }

    $branch = (git rev-parse --abbrev-ref HEAD).Trim()
} finally {
    Pop-Location
}

Write-Output "turning on GitHub Pages"
& gh api -X POST "repos/$slug/pages" -f "source[branch]=$branch" -f "source[path]=/" *> $null
# Already on is not a failure.

# Point it at today's address straight away.
& powershell -NoProfile -ExecutionPolicy Bypass `
    -File (Join-Path $Root "update-redirector.ps1") -Root $Root -PagesRepo $PagesRepo

$action = New-ScheduledTaskAction `
    -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" `
    -Argument ("-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -NonInteractive " +
               "-File `"$(Join-Path $Root 'update-redirector.ps1')`"")

# Offset from the tunnel watchdog rather than running alongside it: the
# watchdog is what changes the address, so this wants to run after it, not at
# the same moment.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date.AddMinutes(7) `
    -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes)

$principal = New-ScheduledTaskPrincipal -UserId $RunAs -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName "ImprintRedirector" `
    -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description "Keeps the stable GitHub Pages link pointing at the current Cloudflare tunnel address. See update-redirector.ps1" `
    -Force | Out-Null

Write-Output ""
Write-Output "Done. The link to give clients:"
Write-Output "    https://$owner.github.io/$RepoName/"
Write-Output ""
Write-Output "It may take a few minutes to answer the first time while GitHub builds the page."
Write-Output "Updated every $EveryMinutes minutes by the ImprintRedirector task."
