# Start or reuse local GitDiagram server on DEV, isolated from Graphify.
$ErrorActionPreference='Stop'
$root=Join-Path $env:USERPROFILE 'GitDiagram'
$node='C:\\Program Files\\nodejs\\node.exe'
$next=Join-Path $root 'node_modules\\next\\dist\\bin\\next'
if(-not (Test-Path -LiteralPath $next)){throw 'Install dependencies with bun ci --ignore-scripts first'}
try {
  $response=Invoke-WebRequest -Uri 'http://127.0.0.1:3001/' -UseBasicParsing -TimeoutSec 3
  if($response.StatusCode -eq 200){Write-Output 'GitDiagram already running at http://127.0.0.1:3001/';exit 0}
} catch {}
$log=Join-Path $env:TEMP 'GitDiagram-local-3001.log'
$err=Join-Path $env:TEMP 'GitDiagram-local-3001-error.log'
$p=Start-Process -FilePath $node -ArgumentList @('node_modules/next/dist/bin/next','dev','-p','3001','-H','127.0.0.1') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $log -RedirectStandardError $err -PassThru
Write-Output ('Started GitDiagram '+$p.Id+' at http://127.0.0.1:3001/; AI generation credentials not configured')
