param([switch]$OpenBrowser)
$ErrorActionPreference='Stop'
$port=8765
$url="http://127.0.0.1:$port/"
$server=Join-Path $env:USERPROFILE 'Graphify\Local-Dashboard-Server.py'
if(!(Test-Path -LiteralPath $server)){throw "Missing Graphify service: $server"}
$active=$false
try{
 $r=Invoke-WebRequest -UseBasicParsing -Uri ($url+'api/status') -TimeoutSec 3
 $active=($r.StatusCode -eq 200)
}catch{}
if(!$active){
 $python=(Get-Command python.exe -ErrorAction Stop).Source
 Start-Process -FilePath $python -ArgumentList @('"' + $server + '"') -WorkingDirectory (Split-Path $server -Parent) -WindowStyle Hidden | Out-Null
 $ok=$false
 foreach($attempt in 1..20){
  Start-Sleep -Milliseconds 300
  try{$r=Invoke-WebRequest -UseBasicParsing -Uri ($url+'api/status') -TimeoutSec 2;if($r.StatusCode -eq 200){$ok=$true;break}}catch{}
 }
 if(!$ok){throw 'Graphify local dashboard could not start (port 8765 or Python issue)'}
}
Write-Output ('GRAPHIFY_DASHBOARD='+$url)
if($OpenBrowser){Start-Process $url}
