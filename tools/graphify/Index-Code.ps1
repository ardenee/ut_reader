[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][ValidatePattern('^[A-Za-z0-9_.-]+$')][string]$Name,
 [Parameter(Mandatory=$true)][string]$Source,
 [ValidateRange(1,12)][int]$Workers=4
)
$ErrorActionPreference='Stop'
$exe=Join-Path $env:USERPROFILE '.local\bin\graphify.exe'
if(!(Test-Path -LiteralPath $exe)){throw "Graphify executable not found: $exe"}
if(!(Test-Path -LiteralPath $Source)){throw "Source directory not found: $Source"}
$out=Join-Path (Join-Path $env:USERPROFILE 'Graphify') $Name
New-Item -ItemType Directory -Path $out -Force | Out-Null
Write-Output ("Indexing {0} into {1}" -f $Source,$out)
& $exe extract $Source --code-only --max-workers $Workers --out $out
if($LASTEXITCODE -ne 0){throw "Graphify extraction exit $LASTEXITCODE"}
$graph=Join-Path $out 'graphify-out\graph.json'
if(!(Test-Path -LiteralPath $graph)){throw "Graph missing: $graph"}
# Generate a navigable browser view. Large graphs become aggregated community views.
& $exe export html --graph $graph --node-limit 2400
if($LASTEXITCODE -ne 0){throw "Graphify HTML export failed"}
$metadata=Get-Item -LiteralPath $graph
Write-Output ("GRAPH_OK graph={0} bytes={1} modified_utc={2:o}" -f $graph,$metadata.Length,$metadata.LastWriteTimeUtc)
$meta=Join-Path $out 'project.json'
if(!(Test-Path -LiteralPath $meta)){
 $family=if($Name -match '^ue1'){'UE1'}elseif($Name -match '^ue2'){'UE2'}elseif($Name -match '^ue3'){'UE3'}elseif($Name -match '^ue4'){'UE4'}elseif($Name -match '^ue5'){'UE5'}else{'Project'}
 $obj=[ordered]@{name=$Name;family=$family;version='';source=$Source;detail='AST-only local source graph'}
 [IO.File]::WriteAllText($meta,($obj|ConvertTo-Json -Depth 6),[Text.Encoding]::UTF8)
}
$dashboard=Join-Path $env:USERPROFILE 'Graphify\Build-Dashboard.py'
if(Test-Path -LiteralPath $dashboard){
 & python $dashboard
 if($LASTEXITCODE -ne 0){throw "Dashboard regeneration failed"}
}
