#requires -Version 5.1
<#
.SYNOPSIS
 Resumable finite G5 compiled-native proof batch; no unrelated O06 replay.
.DESCRIPTION
 Calls two focused O06 contracts. The O06 runner's append-only
 STARTED/PASS/FAIL journal and source/test SHA content-hash reuse are
 authoritative; local batch marker is only progress, never proof.
 Re-run after any interruption to reuse verified current matching PASS.
 All transient batch data is under C:\Temp\UnrealDB\O06, PRD read-only.
.EXAMPLE
 powershell -NoProfile -ExecutionPolicy Bypass -File catalog/bin/run-ue1-o06-g5-resumable-batch.ps1
#>
[CmdletBinding()]
param([switch]$Status,[switch]$Force)
$ErrorActionPreference='Stop'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$state='C:\Temp\UnrealDB\O06\g5-native-resumable-batch-state.json'
$steps=@(
 @{name='g5-v432-native-refbones-full-elements';test='verify-ue1-o06-g5-native-refbones-v432.php'},
 @{name='g5-v432-native-animseq-and-notifies';test='verify-ue1-o06-g5-native-animseq-notifies-v432.php'},
 @{name='g5-v432-native-motionchunk-headers';test='verify-ue1-o06-g5-native-v432-motionchunk-headers.php'}
)
function AtomicState([string]$current,[string]$stage){
 $data=[ordered]@{
  schema=1;batch='g5-v432-genuine-native-refbones-animseq-and-motionchunk'
  updated_at_utc=[DateTime]::UtcNow.ToString('o')
  current_contract=$current;stage=$stage
  source_of_truth='catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'
  resume='Rerun without -Force. Runner reuses only matching source/test SHA PASS.'
  steps=$steps
 }
 $temp=$state+'.tmp'
 [System.IO.File]::WriteAllText($temp,($data|ConvertTo-Json -Depth 6),[System.Text.UTF8Encoding]::new($false))
 Move-Item -LiteralPath $temp -Destination $state -Force
}
if($Status){
 if(Test-Path $state){Get-Content -LiteralPath $state -Raw}
 else{Write-Host 'No local batch state. O06 TEST-EVENTS is the persistent journal.'}
 return
}
$planData=Get-Content (Join-Path $repo 'catalog\compliance\UE1-O06-TEST-PLAN.json') -Raw |ConvertFrom-Json
foreach($step in $steps){
 if(!$planData.contracts.PSObject.Properties[$step.name]){throw "Missing contract $($step.name)"}
 if(!(Test-Path (Join-Path $PSScriptRoot $step.test))){throw "Missing verifier $($step.test)"}
}
$run=Join-Path $PSScriptRoot 'run-ue1-o06-scoped.ps1'
$checkpoint=Join-Path $PSScriptRoot 'update-ue1-o06-checkpoint.py'
foreach($step in $steps){
 $contract=[string]$step.name
 AtomicState $contract 'RUNNING_OR_REUSING_JOURNALED_RESULT'
 Write-Host "G5_BATCH_START contract=$contract"
 & python $checkpoint --activate $contract --stage batch-started --action 'Resumable G5 v432 native compiled oracle evidence batch; matching PASS may be reused' --next "Run or reuse focused $contract and journal its true result before next case."
 if($LASTEXITCODE -ne 0){throw "Checkpoint failed $contract"}
 try {
  & $run -Contract $contract -Tier focus -Force:$Force
  if($LASTEXITCODE -ne 0){throw "Scoped runner failed $contract"}
  AtomicState $contract 'PASS_IN_O06_JOURNAL'
  Write-Host "G5_BATCH_STEP_PASS contract=$contract"
 }catch{
  AtomicState $contract 'FAIL_OR_INTERRUPTED_INSPECT_O06_JOURNAL'
  throw
 }
}
AtomicState 'ALL' 'ALL_CONTRACTS_PASS_OR_HASH_REUSED'
& python $checkpoint --activate 'g5-v432-native-resumable-batch' --stage batch-pass-three-scoped-contracts --action 'FMeshBone, AnimSeq/Notify and MotionChunk compiled oracle focused contracts each passed or valid current hash PASS reused' --next 'Continue with inherited ULodMesh and animation MotionChunk nested arrays; negative byte/error parity and full G5 remain OPEN.'
if($LASTEXITCODE -ne 0){throw 'Final batch checkpoint failed'}
Write-Host 'G5_BATCH_COMPLETE contracts=3 O06_full_acceptance=OPEN'
