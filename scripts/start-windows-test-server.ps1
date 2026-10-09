param([string]$ServerIp='192.168.86.225',[int]$Port=8443)
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$local=Join-Path $root '.local/https-server'
New-Item -ItemType Directory -Force $local | Out-Null
$certificatePath=Join-Path $local 'zpx-test-server.cer'
$cert=Get-ChildItem Cert:/LocalMachine/My | Where-Object {$_.FriendlyName -eq "ZPX Delivery test API $ServerIp" -and $_.NotAfter -gt (Get-Date).AddDays(7)} | Select-Object -First 1
if(-not $cert){$cert=New-SelfSignedCertificate -Type Custom -Subject "CN=$ServerIp" -FriendlyName "ZPX Delivery test API $ServerIp" -KeyAlgorithm RSA -KeyLength 2048 -HashAlgorithm SHA256 -KeyExportPolicy NonExportable -CertStoreLocation Cert:/LocalMachine/My -NotAfter (Get-Date).AddMonths(3) -TextExtension @("2.5.29.17={text}IPAddress=$ServerIp",'2.5.29.37={text}1.3.6.1.5.5.7.3.1')}
$null=Export-Certificate -Cert $cert -FilePath $certificatePath
$null=Import-Certificate -FilePath $certificatePath -CertStoreLocation Cert:/LocalMachine/Root
$existing=& netsh http show sslcert "ipport=${ServerIp}:$Port"
if(($existing -join "`n") -notmatch $cert.Thumbprint){
 if(($existing -join "`n") -match 'Certificate Hash'){throw 'Port already has another TLS binding; existing binding preserved.'}
 & netsh http add sslcert "ipport=${ServerIp}:$Port" "certhash=$($cert.Thumbprint)" 'appid={83a151f4-0f24-4e88-8c40-ae2ee3f974ad}' certstorename=MY | Out-Null
 if($LASTEXITCODE -ne 0){throw 'TLS binding failed'}
}
$rule="ZPX Delivery test API TCP $Port"
if(-not (Get-NetFirewallRule -DisplayName $rule -ErrorAction SilentlyContinue)){$null=New-NetFirewallRule -DisplayName $rule -Direction Inbound -Action Allow -Protocol TCP -LocalAddress $ServerIp -LocalPort $Port -RemoteAddress LocalSubnet -Profile Private}
try{$null=Invoke-WebRequest 'http://127.0.0.1:8000/health/ready' -TimeoutSec 5}catch{
 $api=Join-Path $PSScriptRoot 'windows-api.ps1'
 $null=Start-Process (Join-Path $PSHOME 'pwsh.exe') -ArgumentList @('-NoProfile','-File',('"'+$api+'"')) -WindowStyle Hidden -RedirectStandardOutput (Join-Path $local 'api.log') -RedirectStandardError (Join-Path $local 'api.err')
}
$proxy=Join-Path $PSScriptRoot 'windows-https-proxy.ps1'
$proxyPattern='-File\s+"?'+[regex]::Escape($proxy)
$existingProxy=Get-CimInstance Win32_Process | Where-Object {$_.Name -eq 'pwsh.exe' -and $_.CommandLine -match $proxyPattern}
if(-not $existingProxy){$null=Start-Process (Join-Path $PSHOME 'pwsh.exe') -ArgumentList @('-NoProfile','-File',('"'+$proxy+'"'),'-ServerIp',$ServerIp,'-Port',$Port) -WindowStyle Hidden -RedirectStandardOutput (Join-Path $local 'proxy.log') -RedirectStandardError (Join-Path $local 'proxy.err')}
Write-Output "Test API: https://${ServerIp}:$Port/ ; public trust certificate: $certificatePath"
