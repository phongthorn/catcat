# Generate a self-signed cert for nginx (localhost). Uses a throwaway
# openssl container so you don't need openssl installed on Windows.
$ErrorActionPreference = "Stop"
$certDir = Join-Path $PSScriptRoot "nginx\certs"
New-Item -ItemType Directory -Force -Path $certDir | Out-Null

docker run --rm -v "${certDir}:/certs" --entrypoint openssl alpine/openssl `
    req -x509 -nodes -newkey rsa:2048 -days 825 `
    -keyout /certs/catcat.key -out /certs/catcat.crt `
    -subj "/CN=localhost" `
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"

Write-Host "Wrote catcat.crt / catcat.key to $certDir"
