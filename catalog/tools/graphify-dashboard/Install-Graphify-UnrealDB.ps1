#requires -Version 5.1
<#
  Install the versioned, Graphify-only local project dashboard.
  The previous UnrealDB parity/compliance panel is intentionally not rendered.
  Existing local Graphify graph indexes, viewer settings, and the customized
  local server are preserved by default.
#>
[CmdletBinding()]
param(
    [string]$GraphifyRoot = (Join-Path $env:USERPROFILE 'Graphify'),
    [switch]$ReplaceHostScripts,
    [switch]$RestartService
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath $GraphifyRoot).Path
$source = $PSScriptRoot
$backup = Join-Path 'C:\Temp\Graphify-backups' (Get-Date -Format 'yyyyMMdd-HHmmss')
$files = @('Build-Dashboard.py')
if($ReplaceHostScripts){ $files += 'Local-Dashboard-Server.py' }
foreach($name in $files){
    $from = Join-Path $source $name
    $to = Join-Path $root $name
    if(!(Test-Path -LiteralPath $from)){throw "Versioned Graphify source missing: $from"}
    if(Test-Path -LiteralPath $to){
        $sourceSha = (Get-FileHash -LiteralPath $from -Algorithm SHA256).Hash
        $targetSha = (Get-FileHash -LiteralPath $to -Algorithm SHA256).Hash
        if($sourceSha -ceq $targetSha){Write-Output "UNCHANGED $name";continue}
        New-Item -ItemType Directory -Path $backup -Force | Out-Null
        Copy-Item -LiteralPath $to -Destination (Join-Path $backup $name) -Force
        Write-Output "BACKED_UP $name"
    }
    Copy-Item -LiteralPath $from -Destination $to -Force
    if((Get-FileHash -LiteralPath $from -Algorithm SHA256).Hash -cne
       (Get-FileHash -LiteralPath $to -Algorithm SHA256).Hash){
        throw "Graphify source hash mismatch $name"
    }
    Write-Output "INSTALLED_SHA256_MATCH $name"
}
$builder = Get-Content -LiteralPath (Join-Path $root 'Build-Dashboard.py') -Raw
$serverFile = Join-Path $root 'Local-Dashboard-Server.py'
if(!(Test-Path -LiteralPath $serverFile)){throw 'Local Graphify server missing'}
$server = Get-Content -LiteralPath $serverFile -Raw
if(!$server.Contains('HOST, PORT = "127.0.0.1", 8765') -or
   !$builder.Contains('Source Graph Library') -or
   $builder.Contains('UnrealDB-Dashboard.html') -or
   $builder.Contains('STATUS_HTML')){
    throw 'Graphify-only dashboard or loopback-only server contract failed'
}
& python (Join-Path $root 'Build-Dashboard.py') | Out-Null
if($LASTEXITCODE -ne 0){throw 'Graphify dashboard generation failed'}
$page = Get-Content -LiteralPath (Join-Path $root 'index.html') -Raw
if(!$page.Contains('Source Graph Library') -or
   $page.Contains('id="o06-dashboard"') -or
   $page.Contains('Current UE1') -or
   $page.Contains('/api/unrealdb')){
    throw 'Generated landing page still includes UnrealDB audit/compliance panel'
}
if($RestartService){
    $address='http://127.0.0.1:8765/'
    $proc=@(Get-CimInstance Win32_Process |
        Where-Object {$_.Name -match '^python' -and
          $_.CommandLine -like '*Graphify\Local-Dashboard-Server.py*'})
    if($proc.Count -gt 1){throw 'Multiple Graphify services: manual review required'}
    if($proc.Count -eq 1){
        $jobs=Invoke-RestMethod ($address+'api/status') -TimeoutSec 4
        $viewer=Invoke-RestMethod ($address+'api/viewer') -TimeoutSec 4
        if(@($jobs.jobs.PSObject.Properties.Value | Where-Object {$_.state -eq 'STARTED'}).Count -gt 0 -or
           $viewer.viewer.state -eq 'STARTED'){
            throw 'Graph index or viewer update active; restart refused'
        }
        Stop-Process -Id $proc[0].ProcessId -Force
        Start-Sleep -Milliseconds 300
    }
    & (Join-Path $root 'Start-Dashboard.ps1') | Out-Null
}
try{
    $res=Invoke-WebRequest 'http://127.0.0.1:8765/' -UseBasicParsing -TimeoutSec 5
    if($res.StatusCode -ne 200 -or $res.Content.Contains('id="o06-dashboard"')){
        throw 'Live Graphify page still includes compliance panel'
    }
    Write-Output 'LIVE_GRAPHIFY_ONLY_HTTP_200'
}catch{
    if($RestartService){throw}
    Write-Output 'GRAPHIFY_BUILD_OK_LIVE_SERVER_NOT_VERIFIED'
}
Write-Output 'GRAPHIFY_ONLY_DASHBOARD_UPDATED_NO_PRD_OR_ENGINE_CHANGES'
