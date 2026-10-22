[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][ValidatePattern('^[A-Za-z0-9_.-]+$')][string]$Name,
 [Parameter(Mandatory=$true)][string]$Source,
 [ValidateRange(1,12)][int]$Workers=4
)
$ErrorActionPreference='Stop'
$exe=Join-Path $env:USERPROFILE '.local\bin\graphify.exe'
if(!(Test-Path -LiteralPath $exe)){throw "Graphify executable not found: $exe"}
if(!(Test-Path -LiteralPath $Source -PathType Container)){throw "Source directory not found: $Source"}
$shortcuts=Join-Path $env:APPDATA 'Microsoft\Windows\Network Shortcuts'
if($Source.StartsWith($shortcuts+'\',[StringComparison]::OrdinalIgnoreCase)){
 $link=Join-Path $Source 'target.lnk'
 if(Test-Path -LiteralPath $link -PathType Leaf){
  $resolver=Join-Path $env:USERPROFILE 'Graphify\Resolve-Network-Shortcut.ps1'
  $resolved=& powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $resolver -Link $link
  if($LASTEXITCODE -ne 0 -or !(Test-Path -LiteralPath $resolved -PathType Container)){throw "Network shortcut target is not an accessible folder: $Source"}
  Write-Output ("Resolved shortcut: {0} -> {1}" -f $Source,$resolved)
  $Source=$resolved
 }
}
$out=Join-Path (Join-Path $env:USERPROFILE 'Graphify') $Name
New-Item -ItemType Directory -Path $out -Force | Out-Null
Write-Output ("Indexing {0} into {1}" -f $Source,$out)
# Windows PowerShell 5 presents benign native stderr warnings as error
# records. Exit code, not stderr text, determines command success.
$previous=$ErrorActionPreference
$ErrorActionPreference='Continue'
try {
 & $exe extract $Source --code-only --max-workers $Workers --out $out 2>&1 | ForEach-Object {Write-Output "$_"}
 $extractCode=$LASTEXITCODE
} finally {$ErrorActionPreference=$previous}
if($extractCode -ne 0){throw "Graphify extraction exit $extractCode"}
$graph=Join-Path $out 'graphify-out\graph.json'
if(!(Test-Path -LiteralPath $graph)){throw "Graph missing: $graph"}
# Deterministic offline labels from original source symbols. The underlying
# graph.json is untouched, so Graphify CLI/MCP retain all original identities.
$labeler=Join-Path $env:USERPROFILE 'Graphify\Build-Community-Labels.py'
$displayLabels=Join-Path $out 'graphify-out\.graphify_display_labels.json'
& python $labeler $graph --output $displayLabels
if($LASTEXITCODE -ne 0){throw 'Source-based community naming failed'}
# The node cap applies ONLY to Graphify's interactive HTML exporter.
# Graphify graph.json and all query/MCP references retain every source node.
$settings=Join-Path $env:USERPROFILE 'Graphify\viewer-settings.json'
$nodeLimit=5000
if(Test-Path -LiteralPath $settings -PathType Leaf){
 $selection=Get-Content -LiteralPath $settings -Raw | ConvertFrom-Json
 $requested=[int]$selection.node_limit
 if($requested -lt 2000 -or $requested -gt 5000 -or ($requested%500) -ne 0){
  throw 'Invalid local HTML viewer limit (must be 2000..5000 in steps of 500)'
 }
 $nodeLimit=$requested
}
$previous=$ErrorActionPreference
$ErrorActionPreference='Continue'
try {
 & $exe export html --graph $graph --labels $displayLabels --node-limit $nodeLimit 2>&1 | ForEach-Object {Write-Output "$_"}
 $exportCode=$LASTEXITCODE
} finally {$ErrorActionPreference=$previous}
if($exportCode -ne 0){throw "Graphify HTML export failed (exit $exportCode)"}
$visual=Join-Path $out 'graphify-out\graph.html'
if(!(Test-Path -LiteralPath $visual -PathType Leaf)){throw 'Graphify HTML output missing'}
# Keep the previous convenience filename, with the SAME selected limit.
$communityView=Join-Path $out 'graphify-out\graph-communities.html'
Copy-Item -LiteralPath $visual -Destination $communityView -Force
$deduper=Join-Path $env:USERPROFILE 'Graphify\Disambiguate-Graph-HTML.py'
& python $deduper $visual
if($LASTEXITCODE -ne 0){throw 'Graph HTML labels failed'}
& python $deduper $communityView
if($LASTEXITCODE -ne 0){throw 'Graph community labels failed'}
Write-Output ("HTML_VIEW_NODE_LIMIT={0}; FULL_SOURCE_GRAPH_UNCHANGED={1}" -f $nodeLimit,$graph)
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
