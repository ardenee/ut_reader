param(
    [ValidateRange(1,500)][int]$BatchSize = 250,
    [ValidateRange(1,100000)][int]$MaxBatches = 7,
    [string]$Php = 'C:\php8.5\php.exe',
    [string]$Catalog = 'C:\Apache24\htdocs\unrealdb\catalog',
    [string]$Log = 'C:\Temp\uedb5-compact-migration-progress.jsonl'
)
$ErrorActionPreference = 'Stop'
$runner = Join-Path $Catalog 'bin\backfill-uedb5-compact-candidates.php'
if (-not (Test-Path $runner)) { throw "Backfill executor missing: $runner" }
if (-not (Test-Path $Php)) { throw "PHP executable missing: $Php" }

# Fail closed if another migration executor process is currently active.
# This orchestration is intended to be invoked AFTER the earlier bounded
# migration launcher terminates, never while it is running.
$self = $PID
$existing = @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
    Where-Object { $_.CommandLine -match 'backfill-uedb5-compact-candidates[.]php' })
if ($existing.Count -gt 0) {
    throw ('An existing compact backfill PHP process is active: ' +
        (($existing | ForEach-Object ProcessId) -join ', ') +
        '. Do not overlap migration runs.')
}
$logDir = Split-Path -Parent $Log
if ($logDir -and -not (Test-Path $logDir)) { New-Item -ItemType Directory -Force $logDir | Out-Null }
$games = @(2,3,4,5,6,7,12)
$batchNumber=0
$completedGames=@{}
$global:LASTEXITCODE=0
while ($batchNumber -lt $MaxBatches) {
    $anyWork = $false
    foreach ($game in $games) {
        if ($completedGames.ContainsKey($game)) { continue }
        if ($batchNumber -ge $MaxBatches) { break }
        $started = Get-Date
        $stdout = & $Php $runner "--game-id=$game" "--limit=$BatchSize" '--apply' '--maintenance-frozen' 2>&1
        $exitCode = $LASTEXITCODE
        try {
            $response = ($stdout -join "`n") | ConvertFrom-Json -ErrorAction Stop
        }
        catch {
            $text = ($stdout -join "`n")
            throw "Game $game emitted invalid JSON (exit $exitCode): $text"
        }
        $batchNumber++
        $item = [ordered]@{
            timestamp=(Get-Date).ToString('o')
            game_id=$game
            ok=($exitCode -eq 0 -and $response.ok -eq $true)
            files=[int]$response.verified_files
            start_after_id=$response.start_after_id
            next_after_id=$response.next_after_id
            has_more=$response.has_more
            seconds=[math]::Round(((Get-Date)-$started).TotalSeconds,2)
            failure=$response.failure
        }
        $json = $item | ConvertTo-Json -Compress -Depth 5
        Add-Content -Path $Log -Value $json -Encoding UTF8
        Write-Output $json
        if (-not $item.ok) { throw "Migration stopped at game $game; see checkpoint and $Log" }
        if ($item.files -gt 0) { $anyWork=$true }
        if (-not $item.has_more) { $completedGames[$game]=$true }
    }
    if ($completedGames.Count -eq $games.Count -or -not $anyWork) { break }
}
Write-Output ("Driver finished this invocation: $batchNumber batches, $($completedGames.Count) games at EOF. Progress: $Log")
