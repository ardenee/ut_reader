# Re-source exactly two verified Unreal II legacy package files with
# December 2000 v69/min60 game-source policy. No full-game original reparse,
# cross-engine publication, or UEDB5 backup creation.
# Run locally on PRD Lelly-pc after DEV/GitHub/PRD source sync.
[CmdletBinding()]
param(
    [switch]$Apply,
    [switch]$Help
)
$ErrorActionPreference = 'Stop'
$php = 'C:\php8.5\php.exe'
$diagnoseCli = Join-Path $PSScriptRoot 'diagnose-ue2-unreal2-v69-legacy-source.php'
$sourceCli = Join-Path $PSScriptRoot 'migrate-uedb5-game.php'
$dependencyCli = Join-Path $PSScriptRoot 'migrate-uedb5-dependencies.php'
$statsCli = Join-Path $PSScriptRoot 'rebuild-game-catalog-stats.php'
$coverageCli = Join-Path $PSScriptRoot 'rebuild-package-coverage-cache.php'
$catalogRoot = Split-Path -Parent $PSScriptRoot
$ids = @(149492, 1295446)

if ($Help) {
    Write-Output 'PRD preview: & "C:\Apache24\htdocs\unrealdb\catalog\bin\publish-ue2-unreal2-legacy-v69-scoped.ps1"'
    Write-Output 'PRD apply:   & "C:\Apache24\htdocs\unrealdb\catalog\bin\publish-ue2-unreal2-legacy-v69-scoped.ps1" -Apply'
    Write-Output 'Only two source-pinned Unreal II files (#149492, #1295446). Run locally on Lelly-pc.'
    Write-Output 'Pass 1 from original bytes, exact-file Pass 2, game 2 stats and coverage rebuild; no UEDB5 backup.'
    exit 0
}

if ($env:COMPUTERNAME -ine 'Lelly-pc') {
    throw 'The scoped UE2 publisher must run locally on PRD Lelly-pc, never from DEV.'
}
foreach ($file in @($php,$diagnoseCli,$sourceCli,$dependencyCli,$statsCli,$coverageCli)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw ('Scoped UE2 publication prerequisite unavailable: '+$file)
    }
}

Push-Location -LiteralPath $catalogRoot
try {
    $raw = & $php $diagnoseCli
    if ($LASTEXITCODE -ne 0) { throw 'Read-only preflight failed; original bytes/source identity changed.' }
    $preflight = ($raw | Out-String) | ConvertFrom-Json
    if ($preflight.ok -ne $true -or @($preflight.files).Count -ne 2) {
        throw 'Preflight did not validate exactly the two pinned original files.'
    }
    Write-Output ('UE2_UNREAL2_V69_PREFLIGHT current='+$preflight.current+' needs_resnapshot='+$preflight.needs_resnapshot)
    foreach ($f in $preflight.files) {
        if ([int]$f.id -notin $ids) { throw ('Unexpected file in preflight: '+$f.id) }
        Write-Output ('FILE '+$f.id+' '+$f.name+' '+$f.status+' '+$f.stored_source_policy)
    }
    if (-not $Apply) {
        Write-Output 'READ_ONLY_PREVIEW_COMPLETE. Run with -Apply on PRD for only these two files.'
        return
    }
    foreach ($f in $preflight.files) {
        $fileId = [int]$f.id
        if ($f.status -eq 'current') {
            Write-Output ('PASS1_ALREADY_CURRENT file_id='+$fileId)
            continue
        }
        if ($f.status -ne 'needs_resnapshot') { throw ('Unexpected source status for file '+$fileId) }
        Write-Output ('PASS1_SOURCE_RESTAGE file_id='+$fileId)
        & $php $sourceCli '--game-id=2' "--file-id=$fileId" '--apply'
        if ($LASTEXITCODE -ne 0) {
            throw ('Scoped Pass1 failed for file '+$fileId+'. Stop, inspect and rerun safely.')
        }
    }
    $afterRaw = & $php $diagnoseCli
    if ($LASTEXITCODE -ne 0) { throw 'Post-Pass1 source preflight failed.' }
    $after = ($afterRaw | Out-String) | ConvertFrom-Json
    if ([int]$after.current -ne 2 -or [int]$after.needs_resnapshot -ne 0) {
        throw 'Pass1 did not establish December 2000 source policies for both files.'
    }
    foreach ($id in $ids) {
        Write-Output ('PASS2_FORCE_REPUBLISH file_id='+$id)
        & $php $dependencyCli '--game-id=2' "--file-id=$id" '--apply' '--force'
        if ($LASTEXITCODE -ne 0) {
            throw ('Scoped Pass2 failed for file '+$id+'. Stop and rerun after correction.')
        }
    }
    Write-Output 'REBUILD_GAME2_CATALOG_STATS'
    & $php $statsCli '--game-id=2'
    if ($LASTEXITCODE -ne 0) { throw 'Game 2 stats rebuild failed.' }
    Write-Output 'REBUILD_GAME2_PACKAGE_COVERAGE'
    & $php $coverageCli '--game-id=2'
    if ($LASTEXITCODE -ne 0) { throw 'Game 2 package coverage rebuild failed.' }
    & $php $diagnoseCli '--require-current'
    if ($LASTEXITCODE -ne 0) { throw 'Final source/publish/stats freshness verification failed.' }
    Write-Output 'UE2_UNREAL2_V69_TWO_FILE_RESTAGE_AND_STATS_CURRENT'
} finally {
    Pop-Location
}
