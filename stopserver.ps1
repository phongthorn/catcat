# Stop the full Panda stack: Rust panda.exe + Docker (nginx/php/mysql).
$compose = Join-Path $PSScriptRoot 'docker\docker-compose.yml'
Stop-Process -Name "panda" -Force -ErrorAction SilentlyContinue
docker compose -f $compose stop
Write-Host "Server stopped"
