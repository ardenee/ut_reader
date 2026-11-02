#requires -Version 5.1
<#
 Select an explicit Epic source contract's smallest sufficient test set.
 No unrelated acceptance suite executions by default. Existing O06 runner
 retains durable STARTED/PASS/FAIL and fingerprint-based PASS reuse.
#>
[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][string]$Contract,
 [ValidateSet('focus','affected','gate')][string]$Tier='focus',
 [switch]$ListOnly,
 [switch]$Force
)
$ErrorActionPreference='Stop'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$plan=Get-Content -LiteralPath (Join-Path $repo 'catalog\compliance\UE1-O06-TEST-PLAN.json') -Raw | ConvertFrom-Json
$entry=$plan.contracts.PSObject.Properties[$Contract]
if($null -eq $entry){throw "No declared test plan for contract $Contract"}
$cfg=$entry.Value
$selected=@($cfg.focus)
if($Tier -in @('affected','gate')){$selected+=@($cfg.affected)}
if($Tier -eq 'gate'){$selected+=@($cfg.gate)}
$selected=@($selected|Where-Object {$_}|Select-Object -Unique)
foreach($name in $selected){
 if($name -notmatch '^verify-ue1-.*\.php$' -or
    !(Test-Path -LiteralPath (Join-Path $repo "catalog\bin\$name"))){
  throw "Plan references unknown O06 test $name"
 }
}
# Do not quietly use a selected narrow suite when another UE1 reader is
# concurrently changing; explicit plan expansion or contract separation
# is required. We do not require that all declared readers changed.
$pending=@(&git -C $repo status --porcelain --untracked-files=all -- 'catalog/src/Infrastructure/Readers')
$changed=@($pending|ForEach-Object {
 $p=$_.Substring(3).Trim(' "').Replace('/','\')
 if($p -match 'CatalogUE1.*\.php$'){[IO.Path]::GetFileName($p)}
}|Where-Object {$_}|Select-Object -Unique)
$unmapped=@($changed|Where-Object {$_ -notin @($cfg.readers)})
if($unmapped.Count){
 throw "Scoped test contract $Contract has unlisted changed readers: $($unmapped -join ', '). Update the plan or select the correct contract first."
}
Write-Output ("O06_SCOPE contract={0} tier={1} selected={2} modified_readers={3}" -f $Contract,$Tier,$selected.Count,$changed.Count)
$selected|ForEach-Object {"PLAN $_"}
if($ListOnly){return}
$checkpoint=Join-Path $PSScriptRoot 'update-ue1-o06-checkpoint.py'
& python $checkpoint --activate $Contract --stage "testing-$Tier" --action "Selected $($selected.Count) tests with explicit contract dependencies" --next "Run scoped $Tier test batch; inspect terminal PASS/FAIL and next open contract"
if($LASTEXITCODE -ne 0){throw 'Cannot write durable initial checkpoint'}
$runner=Join-Path $PSScriptRoot 'run-ue1-o06-tests.ps1'
& $runner -Tests $selected -Force:$Force
$exit=$LASTEXITCODE
if($exit -ne 0){
 & python $checkpoint --activate $Contract --stage "failed-$Tier" --action "Scoped $Tier test batch failed" --next 'Inspect most recent journaled FAIL, correct root cause and rerun focused test'
 throw "O06 scoped $Tier test failure"
}
& python $checkpoint --activate $Contract --stage "passed-$Tier" --action "All selected $Tier DEV verification results PASS or matching PASS cache reused" --next 'Inspect acceptance limits, update source contract documentation, then commit and hash-deploy if complete'
if($LASTEXITCODE -ne 0){throw 'Cannot finish durable O06 checkpoint'}
