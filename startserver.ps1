# Start the full Catcat stack: Docker (nginx/php/mysql) + the Rust catcat.exe.
# - Always stops any old instances first (no double-run).
# - Runs catcat.exe in the foreground so its logs stream to this window.
# - Cleanup is guarded three ways so nothing is ever left lingering:
#     1. try/finally  — covers Ctrl+C and errors once the stack is up.
#     2. PowerShell.Exiting event — backstop for graceful session exit paths
#        that can skip finally (e.g. `exit`, host shutdown).
#     3. Stop-Stack is idempotent — safe to fire more than once.

$ErrorActionPreference = 'Stop'
$compose = Join-Path $PSScriptRoot 'docker\docker-compose.yml'
$adb     = Join-Path $PSScriptRoot 'server\target\debug\tools\adb.exe'

function Stop-Stack {
    param([string]$Compose)
    Write-Host "`n[catcat] stopping..." -ForegroundColor Yellow
    Stop-Process -Name "catcat" -Force -ErrorAction SilentlyContinue
    docker compose -f $Compose stop
    Write-Host "[catcat] stopped." -ForegroundColor Yellow
}

function Start-Adb {
    param([string]$AdbExe)
    Write-Host "[catcat] starting ADB server ($AdbExe)..." -ForegroundColor Cyan
    $env:ADB_PATH = $AdbExe
    & $AdbExe start-server
}

# 1. Clean slate — kill anything from a previous run before starting.
Stop-Stack -Compose $compose

# 2. Start ADB daemon (catcat.exe and the scrcpy session both need it on :5037).
Start-Adb -AdbExe $adb

# 3. Backstop: run cleanup if the session exits by a path that skips `finally`.
#    $compose is passed via -MessageData since the action runs in its own scope.
$exitJob = Register-EngineEvent PowerShell.Exiting -MessageData $compose -Action {
    $c = $Event.MessageData
    Stop-Process -Name "catcat" -Force -ErrorAction SilentlyContinue
    docker compose -f $c stop
}

# 4. Everything that needs teardown lives inside the try, so even a Ctrl+C
#    during `docker compose up` is cleaned up by finally.
try {
    Write-Host "[catcat] starting Docker stack..." -ForegroundColor Cyan
    docker compose -f $compose up -d

    Write-Host "[catcat] starting Rust server (Ctrl+C to stop everything)..." -ForegroundColor Cyan
    Set-Location (Join-Path $PSScriptRoot 'server')
    $logFile = Join-Path $PSScriptRoot 'server\catcat.log'
    & .\target\debug\catcat.exe 2>&1 | Tee-Object -FilePath $logFile -Append
}
finally {
    Set-Location $PSScriptRoot
    Stop-Stack -Compose $compose
    Unregister-Event -SubscriptionId $exitJob.Id -ErrorAction SilentlyContinue
}
