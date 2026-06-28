# Stop the full Catcat stack: Rust catcat.exe + Docker (nginx/php/mysql).
$compose = Join-Path $PSScriptRoot 'docker\docker-compose.yml'
Stop-Process -Name "catcat" -Force -ErrorAction SilentlyContinue
docker compose -f $compose stop
Write-Host "Server stopped"
