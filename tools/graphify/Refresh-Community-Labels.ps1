[CmdletBinding()]
param([string]$Project = '', [ValidateRange(2000,5000)][int]$NodeLimit = 5000) # HTML viewer only
$ErrorActionPreference='Stop'
$root=Join-Path $env:USERPROFILE 'Graphify'
$exe=Join-Path $env:USERPROFILE '.local\bin\graphify.exe'
$builder=Join-Path $root 'Build-Community-Labels.py'
$journal=Join-Path $root 'Community-Labels-Journal.jsonl'
$success=0
$failed=0
$projects=@(Get-ChildItem -LiteralPath $root -Directory | Where-Object {
 Test-Path -LiteralPath (Join-Path $_.FullName 'graphify-out\graph.json') -PathType Leaf
})
if($Project){$projects=@($projects|Where-Object {$_.Name -eq $Project})}
if($projects.Count -eq 0){throw "No indexed projects matched"}
foreach($p in $projects){
 $graph=Join-Path $p.FullName 'graphify-out\graph.json'
 $labels=Join-Path $p.FullName 'graphify-out\.graphify_display_labels.json'
 $html=Join-Path $p.FullName 'graphify-out\graph.html'
 $before=(Get-FileHash -LiteralPath $graph -Algorithm SHA256).Hash
 $state='FAIL';$reason=''
 try{
  Write-Output ("START {0}" -f $p.Name)
  & python $builder $graph --output $labels
  if($LASTEXITCODE -ne 0){throw 'Label generator failed'}
  $old=$ErrorActionPreference
  $ErrorActionPreference='Continue'
  try{
   & $exe export html --graph $graph --labels $labels --node-limit $NodeLimit 2>&1 |
     ForEach-Object {Write-Output "$_"}
   $exit=$LASTEXITCODE
  } finally {$ErrorActionPreference=$old}
  if($exit -ne 0){throw "Graphify export exit $exit"}
  if(!(Test-Path -LiteralPath $html -PathType Leaf)){throw 'Community HTML was not created'}
  # $html was generated with the user-selected 2,000–5,000 node cap.
  # The original graph.json is NEVER modified or truncated.
  $fallback=Join-Path $p.FullName 'graphify-out\graph-communities.html'
  Copy-Item -LiteralPath $html -Destination $fallback -Force
  $deduper=Join-Path $root 'Disambiguate-Graph-HTML.py'
  & python $deduper $html
  if($LASTEXITCODE -ne 0){throw 'Graph HTML deduplication failed'}
  & python $deduper $fallback
  if($LASTEXITCODE -ne 0){throw 'Community HTML deduplication failed'}
  Write-Output ("HTML_NODE_LIMIT={0}; FALLBACK={1}" -f $NodeLimit,$fallback)
  $after=(Get-FileHash -LiteralPath $graph -Algorithm SHA256).Hash
  if($after -ne $before){throw 'Graph source was unexpectedly changed'}
  $state='PASS';$success++
 }catch{
  $failed++
  $reason=$_.Exception.Message
  Write-Warning ("FAILED {0}: {1}" -f $p.Name,$reason)
 }
 $event=[ordered]@{at_utc=[DateTime]::UtcNow.ToString('o');project=$p.Name;
   state=$state;graph_sha256=$before;labels=$labels;reason=$reason}
 Add-Content -LiteralPath $journal -Encoding UTF8 -Value ($event|ConvertTo-Json -Compress)
 Write-Output ("{0} {1}" -f $state,$p.Name)
}
Write-Output ("NAMED_GRAPHS_PASS={0}/{1} FAILED={2}" -f $success,$projects.Count,$failed)
if($failed){exit 1}
