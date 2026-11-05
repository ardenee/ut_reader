# Game-scoped UE2 v15 dependency republication; does not reimport original
# package files or generate permanent .uedb5 backup copies.
# Run on PRD (Lelly-pc) after deploying the matching source commit.
[CmdletBinding()]
param(
    [int[]]$GameIds = @(2),
    [ValidateRange(1, 8)][int]$Workers = 2,
    [switch]$Apply,
    [switch]$Help
)
$ErrorActionPreference = 'Stop'
$php = 'C:\php8.5\php.exe'
$catalog = Split-Path -Parent $PSScriptRoot
$dependencyCli = Join-Path $PSScriptRoot 'migrate-uedb5-dependencies.php'
$statsCli = Join-Path $PSScriptRoot 'rebuild-game-catalog-stats.php'

if ($Help) {
    Write-Output 'Preview: .\publish-ue2-no-common-v15.ps1 -GameIds 2,4,5'
    Write-Output 'Apply:   .\publish-ue2-no-common-v15.ps1 -GameIds 2,4,5 -Workers 2 -Apply'
    Write-Output 'Runs source UEDB5 dependency Pass 2 and rebuilds cached Games statistics for each requested UE2 game.'
    exit 0
}
if (-not (Test-Path -LiteralPath $php)) { throw 'PRD PHP not found at C:\php8.5\php.exe' }
if ($env:COMPUTERNAME -ine 'Lelly-pc') {
    throw 'Run this publisher locally on the PRD Lelly-pc host, not the DEV workstation.'
}
$gameIds = @($GameIds | Sort-Object -Unique)
if (@($gameIds | Where-Object { $_ -notin @(2, 4, 5) }).Count -gt 0) {
    throw 'This scoped publisher accepts only UE2 game IDs 2, 4 or 5.'
}
Push-Location -LiteralPath $catalog
try {
    foreach ($id in $gameIds) {
        Write-Output ('UE2_V15_PREFLIGHT_GAME ' + $id)
        & $php $dependencyCli "--game-id=$id" '--preflight'
        if ($LASTEXITCODE -ne 0) { throw ('UE2 preflight failed for game ' + $id) }
        if (-not $Apply) {
            Write-Output ('UE2_V15_PREVIEW_ONLY_GAME ' + $id)
            continue
        }
        Write-Output ('UE2_V15_APPLY_GAME ' + $id)
        & $php $dependencyCli "--game-id=$id" '--apply' '--continuous' "--workers=$Workers" '--limit=150' '--progress-every=50'
        if ($LASTEXITCODE -ne 0) { throw ('UE2 Pass 2 failed for game ' + $id + '; resolve and rerun; prior completed files are resumable.') }
        & $php $statsCli "--game-id=$id"
        if ($LASTEXITCODE -ne 0) { throw ('UE2 cached statistics refresh failed for game ' + $id) }
        Write-Output ('UE2_V15_COMPLETE_GAME ' + $id)
    }
} finally {
    Pop-Location
}
