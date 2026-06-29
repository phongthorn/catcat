if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process powershell -Verb RunAs -ArgumentList "-ExecutionPolicy Bypass -File `"$PSCommandPath`""
    exit
}

$cf = "C:\Program Files (x86)\cloudflared\cloudflared.exe"
$config = "C:\ProgramData\cloudflared\config.yml"

# Uninstall cleanly
sc.exe stop Cloudflared 2>&1 | Out-Null
Start-Sleep 2
sc.exe delete Cloudflared 2>&1 | Out-Null
Start-Sleep 2

# Copy config and credentials
New-Item -ItemType Directory -Force "C:\ProgramData\cloudflared" | Out-Null
Copy-Item "C:\Users\MMC-PHONG\.cloudflared\config.yml" $config -Force
Copy-Item "C:\Users\MMC-PHONG\.cloudflared\2f22f096-0106-41ac-b02f-88ce0eaa94b8.json" "C:\ProgramData\cloudflared\2f22f096-0106-41ac-b02f-88ce0eaa94b8.json" -Force
(Get-Content $config) -replace 'credentials-file:.*', 'credentials-file: C:\ProgramData\cloudflared\2f22f096-0106-41ac-b02f-88ce0eaa94b8.json' | Set-Content $config

# Install service (registers the service)
& $cf --config $config service install

# Patch registry to add --config argument to the service binary path
$regPath = "HKLM:\SYSTEM\CurrentControlSet\Services\Cloudflared"
$binPath = "`"$cf`" --config `"$config`" tunnel run"
Set-ItemProperty -Path $regPath -Name ImagePath -Value $binPath
Write-Host "Service ImagePath set to: $binPath" -ForegroundColor Green

Start-Sleep 1
sc.exe start Cloudflared
Start-Sleep 8

Write-Host "=== Service Status ===" -ForegroundColor Cyan
sc.exe query Cloudflared
Start-Sleep 4
& $cf tunnel info catcat
Read-Host "Press Enter to close"
