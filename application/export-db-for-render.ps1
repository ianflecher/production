# Packs the SQLite database into the one text file Render will take.
#
# Render secret files are plaintext and capped at 1 MB, and the database is
# 2.4 MB of binary - so it is gzipped and base64'd, which brings it to roughly
# 640 KB. docker-start.sh reverses this on the server at boot.
#
#   .\export-db-for-render.ps1
#
# Then paste the contents of imprint.sqlite.b64 into the Render dashboard
# under Secret Files, with the filename imprint.sqlite.b64.
param(
    [string]$Database = "database/imprint.sqlite",
    [string]$OutFile  = "imprint.sqlite.b64"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $Database)) {
    throw "No database at $Database - run this from the application folder."
}

$raw = [System.IO.File]::ReadAllBytes((Resolve-Path $Database))

$buffer = New-Object System.IO.MemoryStream
$gzip = New-Object System.IO.Compression.GZipStream($buffer, [System.IO.Compression.CompressionLevel]::Optimal)
$gzip.Write($raw, 0, $raw.Length)
$gzip.Close()

$text = [System.Convert]::ToBase64String($buffer.ToArray())
[System.IO.File]::WriteAllText((Join-Path (Get-Location) $OutFile), $text)

$kb = [math]::Round($text.Length / 1KB)

Write-Output ("database : {0:N0} KB" -f ($raw.Length / 1KB))
Write-Output ("packed   : {0:N0} KB  ->  $OutFile" -f $kb)
# The number to check against the server. A text box that silently takes only
# the start of a long paste reports no error anywhere; comparing this with
# what the container says it received is what tells you that happened.
Write-Output ("characters: {0:N0}  <- the container must report this exact number" -f $text.Length)

if ($kb -gt 950) {
    Write-Output ""
    Write-Output "TOO BIG. Render caps all secret files at 1 MB combined and this is $kb KB."
    Write-Output "The database has outgrown this route - use a Render Postgres instance instead."
} else {
    Write-Output ""
    Write-Output "Fits, with $(1024 - $kb) KB to spare."
    Write-Output "Paste the contents into Render > Secret Files, filename: imprint.sqlite.b64"
}
