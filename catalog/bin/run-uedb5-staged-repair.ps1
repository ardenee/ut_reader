# Resumable V5-only Step 8 migration worker. No V4 fallback or table deletion.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('unreal2','ut99','ut2003','ut2004','ut3','unrealgold')]
    [string]$Game,
    [ValidateRange(1,500)]
    [int]$BatchSize=500,
    [ValidateRange(0,1000000)]
    [int]$MaxBatches=0,
    [ValidateRange(0,2147483647)]
    [int]$StartAfter=0,
    [ValidateRange(1,500)]
    [int]$ProgressEvery=100,
    [ValidateRange(1,1000)]
    [int]$MinFreeGB=15,
    [ValidateRange(1,4)]
    [int]$Workers=1,
    [ValidateRange(0,3)]
    [int]$WorkerIndex=0,
    [switch]$ResyncStaged,
    [string]$PhpPath='C:\php8.5\php.exe'
)
$ErrorActionPreference='Stop'
$worker=Join-Path $PSScriptRoot 'repair-uedb5-staged.php'
if(!(Test-Path $PhpPath)){throw ('PHP not found: '+$PhpPath)}
if(!(Test-Path $worker)){throw ('Repair worker not found: '+$worker)}
if($WorkerIndex -ge $Workers){throw 'WorkerIndex must be less than Workers.'}
$cursor=$StartAfter
$batches=0
$totalValidated=0
$totalRepaired=0
$totalNotReady=0
do {
    $dataDrive=Get-PSDrive -Name D -ErrorAction SilentlyContinue
    if($null -eq $dataDrive){throw 'MySQL D: data volume could not be inspected.'}
    if([long]$dataDrive.Free -lt [long]$MinFreeGB*1GB){
        throw ('Stopping V5 migration: D: has less than '+$MinFreeGB+' GiB free.')
    }
    $completed=$null
    $workerArgs=@($worker,"--game=$Game","--limit=$BatchSize","--after=$cursor",'--apply',"--progress-every=$ProgressEvery","--workers=$Workers","--worker-index=$WorkerIndex")
    if($ResyncStaged){$workerArgs+='--resync-staged'}
    & $PhpPath @workerArgs 2>&1 | ForEach-Object {
        $line=[string]$_
        Write-Output $line
        if($line.StartsWith('{')) {
            try {
                $record=ConvertFrom-Json -InputObject $line
                if($record.status -eq 'complete'){$completed=$record.summary}
            } catch {
                # Errors and progress text are preserved verbatim.
            }
        }
    }
    $exit=$LASTEXITCODE
    if($exit -ne 0){throw ('V5 batch failed at cursor '+$cursor+'. Exit '+$exit)}
    if($null -eq $completed){throw ('V5 batch did not report completion at cursor '+$cursor)}
    if($completed.selected -eq 0){break}
    if([int]$completed.last_file_id -le $cursor){throw 'V5 batch cursor did not advance.'}
    $totalValidated += [int]$completed.validated_existing
    $totalRepaired += [int]$completed.repaired_validated
    $totalNotReady += [int]$completed.not_ready
    $cursor=[int]$completed.last_file_id
    $batches++
    Write-Output ('V5_CHECKPOINT game='+$Game+' batches='+$batches+' last_file_id='+$cursor+
        ' validated_existing='+$totalValidated+' repaired_validated='+$totalRepaired+
        ' not_ready='+$totalNotReady)
} while($MaxBatches -eq 0 -or $batches -lt $MaxBatches)
Write-Output ('V5_RUN_COMPLETE game='+$Game+' batches='+$batches+' last_file_id='+$cursor+
    ' validated_existing='+$totalValidated+' repaired_validated='+$totalRepaired+
    ' not_ready='+$totalNotReady)
if($totalNotReady -gt 0){
    Write-Warning 'Some files remain staged and require a source-backed investigation; cutover is not ready.'
}
