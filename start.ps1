Stop-Process -Name "panda" -Force -ErrorAction SilentlyContinue
Set-Location $PSScriptRoot
.\target\debug\panda.exe
