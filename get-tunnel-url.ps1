# Detects the current https://*.trycloudflare.com address from the cloudflared
# log and writes it to current-tunnel-url.txt. Used by start-imprint.bat.
param(
    [string]$LogPath = "C:\ImprintProduction\logs\cloudflared.log",
    [string]$OutFile = "C:\ImprintProduction\current-tunnel-url.txt",
    [int]$TimeoutSec = 60
)

$deadline = (Get-Date).AddSeconds($TimeoutSec)
$url = $null

while ((Get-Date) -lt $deadline) {
    if (Test-Path $LogPath) {
        $content = Get-Content $LogPath -Raw -ErrorAction SilentlyContinue
        if ($content -and $content -match 'https://[a-z0-9-]+\.trycloudflare\.com') {
            $url = $Matches[0]
            break
        }
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
