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

# Render caps a secret file at 500 KiB - not the 1 MB the docs quote for all
# of them combined - so the packed database goes in as several parts and the
# container joins them back up. 400,000 characters is about 390 KiB, which
# leaves room for the limit to be measured slightly differently at their end.
$chunk = 400000
$parts = [math]::Ceiling($text.Length / $chunk)

Get-ChildItem -Path . -Filter "$OutFile.*" -ErrorAction SilentlyContinue | Remove-Item

for ($i = 0; $i -lt $parts; $i++) {
    $slice = $text.Substring($i * $chunk, [math]::Min($chunk, $text.Length - $i * $chunk))
    $name = "{0}.{1:d2}" -f $OutFile, ($i + 1)
    [System.IO.File]::WriteAllText((Join-Path (Get-Location) $name), $slice)
    Write-Output ("  {0}  {1:N0} KB" -f $name, ($slice.Length / 1KB))
}

Write-Output ""
Write-Output ("database  : {0:N0} KB" -f ($raw.Length / 1KB))
Write-Output ("packed    : {0:N0} KB across $parts file(s)" -f ($text.Length / 1KB))
Write-Output ("characters: {0:N0}  <- the container must report this exact number" -f $text.Length)
Write-Output ""

if (($text.Length / 1KB) -gt 1000) {
    Write-Output "TOO BIG. All secret files together cannot exceed 1 MB and this is over it."
    Write-Output "The database has outgrown this route - use a Render Postgres instance instead."
} else {
    Write-Output "Upload each part above as its own secret file in Render, under the same name."
    Write-Output "The container joins them in order and restores the database from the result."
}
