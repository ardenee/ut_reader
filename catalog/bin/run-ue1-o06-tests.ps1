#requires -Version 5.1
<#
 UE1 O06 durable test journal. START is flushed BEFORE each invocation;
 FINISH after exit, with output path/hash and input fingerprint.
 START without FINISH identifies an interrupted test.
 -Tests foo.php,bar.php [-Force] to rerun passed tests.
#>
[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][string[]]$Tests,
 [switch]$Force,
 [string]$TargetRoot = ''
)
$ErrorActionPreference='Stop'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$target=if($TargetRoot){'PRD'}else{'DEV'}
$testRoot=if($TargetRoot){(Resolve-Path -LiteralPath $TargetRoot).Path}else{$repo}
$compliance=Join-Path $repo 'catalog\compliance'
$events=Join-Path $compliance 'UE1-O06-TEST-EVENTS.jsonl'
$statusFile=Join-Path $compliance 'UE1-O06-TEST-STATUS.json'
$logs=Join-Path $compliance 'evidence\o06'
New-Item -ItemType Directory -Path $logs -Force | Out-Null
$sha=[System.Security.Cryptography.SHA256]::Create()
function Get-TextSha([string]$value){
 $data=[System.Text.Encoding]::UTF8.GetBytes($value)
 return [BitConverter]::ToString($sha.ComputeHash($data)).Replace('-','').ToLowerInvariant()
}
$inputs=@(Get-ChildItem -LiteralPath (Join-Path $testRoot 'catalog\src\Infrastructure\Readers') -Filter 'CatalogUE1*.php' -File |
 Select-Object -ExpandProperty FullName)
$inputs+=@(Join-Path $testRoot 'catalog\src\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata.php')
$inputs+=@(Join-Path $testRoot 'catalog\bin\inspect-unreal-file.php')
# Compiler fixture is executable acceptance input; changing it must invalidate
# previous ABI passes even when the PHP verifier is unchanged.
$inputs+=@(Join-Path $testRoot 'catalog\compliance\evidence\o06\ue1-1996-msvc-abi.cpp')
$sourceSig=($inputs|Sort-Object|ForEach-Object{if(Test-Path -LiteralPath $_){$_+':'+(Get-FileHash -LiteralPath $_ -Algorithm SHA256).Hash}})-join "`n"
$sourceSha=Get-TextSha $sourceSig
$commit=(&git -C $repo rev-parse --short=8 HEAD).Trim()
$history=@()
if(Test-Path -LiteralPath $events){
 foreach($line in [IO.File]::ReadLines($events)){
   if(!$line.Trim()){continue}
   try{$history+=($line|ConvertFrom-Json)}catch{throw "Invalid O06 journal JSON event: $line"}
 }
}
function Update-TestStatus {
 $latest=@{}
 foreach($event in $history){
   $eventTarget=if($event.target){[string]$event.target}else{'DEV'}
   $latest[$eventTarget+':'+$event.test]=$event
 }
 $items=@($latest.Keys|Sort-Object|ForEach-Object{
  $entry=$latest[$_]
  [ordered]@{
   key=$_;test=$entry.test;target=$(if($entry.target){$entry.target}else{'DEV'});state=$entry.state;event_at_utc=$entry.event_at_utc
   source_sha256=$entry.source_sha256;test_sha256=$entry.test_sha256
   run_id=$entry.run_id;exit_code=$entry.exit_code;log=$entry.log
  }
 })
 $doc=[ordered]@{
  updated_at_utc=[DateTime]::UtcNow.ToString('o')
  head_commit=$commit;current_target=$target;current_source_sha256=$sourceSha
  tests=$items
 }
 $tmp=$statusFile+'.tmp'
 [IO.File]::WriteAllText($tmp,($doc|ConvertTo-Json -Depth 7),[Text.Encoding]::UTF8)
 Move-Item -LiteralPath $tmp -Destination $statusFile -Force
}
$failed=0;$passed=0;$skipped=0
foreach($t in $Tests){
 $name=[IO.Path]::GetFileName($t)
 if($name -notmatch '^verify-ue1-.*\.php$'){throw "Only named UE1 verification PHP scripts allowed: $name"}
 $test=Join-Path $testRoot ('catalog\bin\'+$name)
 if(!(Test-Path -LiteralPath $test)){throw "Missing test: $name"}
 $testSha=(Get-FileHash -LiteralPath $test -Algorithm SHA256).Hash.ToLowerInvariant()
 $previous=@($history | Where-Object {$_.test -eq $name -and $(if($_.target){$_.target}else{'DEV'}) -eq $target -and $_.source_sha256 -eq $sourceSha -and $_.test_sha256 -eq $testSha} | Select-Object -Last 1)
 # Only skip passing tests for *identical* source and test bytes.
 if(!$Force -and $previous.Count -gt 0 -and $previous[0].state -eq 'PASS'){
   $skipped++;Write-Output ("UNCHANGED_PASS {0} (journaled; skip)" -f $name);continue
 }
 $stamp=[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssfffZ')
 $runId=$stamp+'-'+([guid]::NewGuid().ToString('N').Substring(0,8))
 $logFile=Join-Path $logs ($runId+'-'+$target+'-'+$name+'.txt')
 $begin=[ordered]@{
   event_at_utc=[DateTime]::UtcNow.ToString('o');run_id=$runId
   target=$target;test=$name;state='STARTED';commit=$commit
   source_sha256=$sourceSha;test_sha256=$testSha;log=$logFile.Substring($repo.Length+1)
 }
 [IO.File]::AppendAllText($events, (($begin|ConvertTo-Json -Compress -Depth 6)+"`n"), [Text.Encoding]::UTF8)
 $history+=([pscustomobject]$begin)
 Update-TestStatus
 Write-Output ("START {0} {1}" -f $name,$runId)
 $clock=[Diagnostics.Stopwatch]::StartNew()
 $exit=255
 try {
    $out=@(&php $test 2>&1 | Out-String)
    $exit=$LASTEXITCODE
    [IO.File]::WriteAllText($logFile,($out -join "`n"),[Text.Encoding]::UTF8)
 } catch {
    [IO.File]::WriteAllText($logFile,($_|Out-String),[Text.Encoding]::UTF8)
 }
 $clock.Stop()
 $state=if($exit -eq 0){'PASS'}else{'FAIL'}
 $complete=[ordered]@{
   event_at_utc=[DateTime]::UtcNow.ToString('o');run_id=$runId
   target=$target;test=$name;state=$state;exit_code=$exit;duration_ms=$clock.ElapsedMilliseconds
   commit=$commit;source_sha256=$sourceSha;test_sha256=$testSha
   log=$logFile.Substring($repo.Length+1)
   output_sha256=(Get-FileHash -LiteralPath $logFile -Algorithm SHA256).Hash.ToLowerInvariant()
 }
 [IO.File]::AppendAllText($events, (($complete|ConvertTo-Json -Compress -Depth 6)+"`n"),[Text.Encoding]::UTF8)
 $history+=([pscustomobject]$complete)
 Update-TestStatus
 Write-Output ("{0} {1} exit={2} duration_ms={3}" -f $state,$name,$exit,$clock.ElapsedMilliseconds)
 if($exit -eq 0){$passed++}else{
   $failed++;Get-Content -LiteralPath $logFile |Select-Object -Last 18
 }
}
Write-Output ("O06_BATCH target=$target passed={0} skipped_verified={1} failed={2} source_sha256={3} commit={4}" -f $passed,$skipped,$failed,$sourceSha,$commit)
if($failed){exit 1}else{exit 0}
