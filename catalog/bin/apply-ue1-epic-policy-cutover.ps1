# UE1-only Epic-source policy cutover. Run locally on Lelly-pc in an elevated PowerShell.
# By default this performs preflight only. -Apply modifies only UE1 games 3 and 12.
[CmdletBinding()]
param(
    [switch]$Apply,
    [switch]$CanaryOnly,
    [ValidateRange(1,4)][int]$Workers = 2,
    [ValidateRange(1,1000)][int]$Limit = 100
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($env:COMPUTERNAME -ine 'Lelly-pc') {
    throw 'Run this on PRD Lelly-pc only; DEV MySQL access cannot safely publish PRD metadata.'
}
$php = 'C:\php8.5\php.exe'
if (!(Test-Path -LiteralPath $php)) { throw "PRD PHP CLI missing: $php" }
$runner = Join-Path $PSScriptRoot 'migrate-uedb5-dependencies.php'
$verifier = Join-Path $PSScriptRoot 'verify-ue1-epic-policy-cutover.php'
$statsRebuilder = Join-Path $PSScriptRoot 'rebuild-game-catalog-stats.php'
if (!(Test-Path -LiteralPath $runner) -or !(Test-Path -LiteralPath $verifier) -or
    !(Test-Path -LiteralPath $statsRebuilder)) {
    throw 'UE1 Pass 2 migration, stats refresh or verification scripts missing on PRD.'
}
$catalog = Split-Path -Parent $PSScriptRoot
$storage = Join-Path $catalog 'storage'
if (!(Test-Path -LiteralPath $storage)) { throw "PRD UEDB5 storage root missing: $storage" }
$backupRoot = 'C:\Temp\UnrealDB\UE1\epic-policy-cutover'
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
$log = Join-Path $backupRoot ('cutover-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
Start-Transcript -Path $log -ErrorAction Stop | Out-Null
try {
    Write-Output "UE1_SOURCE_POLICY_CUTOVER PRD=$env:COMPUTERNAME apply=$Apply workers=$Workers batch=$Limit"
    foreach ($id in @(3,12)) {
        $output = & $php $runner "--game-id=$id" '--preflight'
        if ($LASTEXITCODE -ne 0) { throw "UE1 game $id preflight runner failed." }
        $p = (($output -join [Environment]::NewLine) | ConvertFrom-Json).preflight
        Write-Output ("PREFLIGHT game={0} verified={1} staged={2} current={3} missing_primary={4} invalid={5}" -f
            $id, $p.verified_count, $p.staged_count, $p.dependency_complete_count,
            $p.missing_primary_provider_count, $p.invalid_staged_count)
        if (!$p.pass2_ready -or [int]$p.verified_count -ne [int]$p.staged_count -or
            [int]$p.missing_primary_provider_count -ne 0 -or [int]$p.invalid_staged_count -ne 0) {
            throw "UE1 game $id cannot safely publish: preflight failed."
        }
    }
    if (!$Apply) {
        Write-Output 'PREFLIGHT_ONLY_NO_PRD_WRITES. Run with -Apply to perform the UE1 cutover.'
        return
    }
    foreach ($case in @(@(3,113256),@(12,1246569))) {
        $id = [int]$case[0]; $file = [int]$case[1]
        $shard = [math]::Floor($file / 1000).ToString('000000')
        $src = Join-Path $storage ("metadata\{0}\{1}\{2}.uedb5" -f $id, $shard, $file)
        if (!(Test-Path -LiteralPath $src)) { throw "Original canary .uedb5 missing: $src" }
        $dest = Join-Path $backupRoot ("{0}-{1}-before.uedb5" -f $id, $file)
        if (!(Test-Path -LiteralPath $dest)) {
            Copy-Item -LiteralPath $src -Destination $dest -ErrorAction Stop
        }
        Write-Output ("BACKUP {0} SHA256={1}" -f $file, (Get-FileHash -LiteralPath $dest -Algorithm SHA256).Hash)
        & $php $runner "--game-id=$id" "--file-id=$file" '--apply' '--force'
        if ($LASTEXITCODE -ne 0) { throw "Production canary publication failed for file $file; further rollout stopped." }
        & $php $verifier "--game-id=$id" "--file-id=$file"
        if ($LASTEXITCODE -ne 0) { throw "Canary source-policy/hash/edge verification failed for file $file." }
        Write-Output "CANARY_EPIC_SOURCE_POLICY_CURRENT game=$id file=$file"
    }
    if ($CanaryOnly) {
        Write-Output 'TWO_CANARIES_PUBLISHED_AND_VERIFIED. Remaining files remain on their previous policy.'
        return
    }
    foreach ($id in @(3,12)) {
        Write-Output "START_RESUMABLE_UE1_PRODUCTION_PASS2 game=$id"
        & $php $runner "--game-id=$id" '--apply' '--continuous' "--workers=$Workers" "--limit=$Limit" '--progress-every=100'
        if ($LASTEXITCODE -ne 0) {
            throw "UE1 production Pass 2 game $id reported failures. Resume with the same command after fixing the error; already-current file IDs are skipped."
        }
        Write-Output "UE1_GAME_DEPENDENCY_REPUBLICATION_WORKERS_COMPLETED game=$id"
    }
    # Dependency republication invalidates the per-game statistics cache.
    # These two scoped aggregate rebuilds must complete *before* declaring
    # cutover done; otherwise catalog/games.php correctly shows Unavailable.
    foreach ($id in @(3,12)) {
        & $php $statsRebuilder "--game-id=$id"
        if ($LASTEXITCODE -ne 0) {
            throw "UE1 game $id dependency stats refresh failed; do not report cutover complete."
        }
        Write-Output "UE1_GAME_CATALOG_DEPENDENCY_STATS_REFRESHED game=$id"
    }
    & $php $verifier
    if ($LASTEXITCODE -ne 0) {
        throw 'UE1 source-policy, payload or website aggregate freshness verification failed. See status output.'
    }
    Write-Output 'UE1_BOTH_GAMES_SOURCE_POLICY_AND_WEBSITE_STATS_CURRENT_OLD_V13_ROWS_ZERO'
} finally {
    Stop-Transcript | Out-Null
    Write-Output "Migration log: $log"
}
