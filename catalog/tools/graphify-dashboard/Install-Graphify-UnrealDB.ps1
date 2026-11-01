#requires -Version 5.1
<#
  Restore the DEV Graphify UnrealDB read-only dashboard from versioned sources.
  Do not copy to production and do not execute UnrealDB tests.
  The two Graphify host scripts may contain other user modifications and are
  overwritten ONLY when -ReplaceHostScripts is explicitly specified.
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
$backups = Join-Path 'C:\Temp\Graphify-backups' (Get-Date -Format 'yyyyMMdd-HHmmss')
$extensions = @('UnrealDB-Status.py','UnrealDB-Dashboard.html',
                'UnrealDB-Dashboard.css','UnrealDB-Dashboard.js')
$hosts = @('Build-Dashboard.py','Local-Dashboard-Server.py')
$allFiles = @($extensions)
if($ReplaceHostScripts){$allFiles += $hosts}
foreach($name in $allFiles){
    $from = Join-Path $source $name
    $to = Join-Path $root $name
    if(!(Test-Path -LiteralPath $from)){throw "Missing versioned Graphify source $from"}
    if(Test-Path -LiteralPath $to){
        $same = (Get-FileHash -LiteralPath $from -Algorithm SHA256).Hash -ceq
                (Get-FileHash -LiteralPath $to -Algorithm SHA256).Hash
        if($same){Write-Output "UNCHANGED $name";continue}
        New-Item -ItemType Directory -Path $backups -Force | Out-Null
        Copy-Item -LiteralPath $to -Destination (Join-Path $backups $name) -Force
        Write-Output "BACKED_UP $name"
    }
    Copy-Item -LiteralPath $from -Destination $to -Force
    if((Get-FileHash -LiteralPath $from -Algorithm SHA256).Hash -cne
       (Get-FileHash -LiteralPath $to -Algorithm SHA256).Hash){
        throw "Source hash mismatch $name"
    }
    Write-Output "INSTALLED_SHA256_MATCH $name"
}
foreach($name in $hosts){
    $file = Join-Path $root $name
    if(!(Test-Path -LiteralPath $file)){
        throw "Graphify host script missing: $name"
    }
}
# Never silently publish this read-only status feed beyond loopback.
$server = Get-Content -LiteralPath (Join-Path $root 'Local-Dashboard-Server.py') -Raw
$builder = Get-Content -LiteralPath (Join-Path $root 'Build-Dashboard.py') -Raw
if(!$server.Contains('HOST, PORT = "127.0.0.1", 8765') -or
   !$server.Contains('STATUS_LIB.snapshot()') -or
   !$builder.Contains('UnrealDB-Dashboard.js')){
    throw 'Host scripts lack expected loopback-only UnrealDB integration. Inspect and use -ReplaceHostScripts explicitly.'
}
& python (Join-Path $root 'Build-Dashboard.py') | Out-Null
if($LASTEXITCODE -ne 0){throw 'Graphify generated dashboard build failed'}
if($RestartService){
    $address='http://127.0.0.1:8765/'
    $proc = @(Get-CimInstance Win32_Process |
        Where-Object {$_.Name -match '^python' -and
                      $_.CommandLine -like '*Graphify\Local-Dashboard-Server.py*'})
    if($proc.Count -gt 1){throw 'Multiple Graphify server processes: manual inspection required'}
    if($proc.Count -eq 1){
        $jobs = Invoke-RestMethod ($address+'api/status') -TimeoutSec 4
        $viewer = Invoke-RestMethod ($address+'api/viewer') -TimeoutSec 4
        if(@($jobs.jobs.PSObject.Properties.Value | Where-Object {$_.state -eq 'STARTED'}).Count -gt 0 -or
            $viewer.viewer.state -eq 'STARTED'){
            throw 'Graph work active: restart refused'
        }
        Stop-Process -Id $proc[0].ProcessId -Force
        Start-Sleep -Milliseconds 300
    }
    & (Join-Path $root 'Start-Dashboard.ps1') | Out-Null
    $state = Invoke-RestMethod ($address+'api/unrealdb') -TimeoutSec 10
    if(!$state.ok -or !$state.read_only){throw 'UnrealDB live API failed after restart'}
    Write-Output "LIVE_VERIFIED $address"
}
Write-Output 'GRAPHIFY_UNREALDB_EXTENSION_INSTALLED_NO_PRD_CHANGES'
