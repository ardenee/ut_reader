[CmdletBinding()]
param(
    [string]$BackupRoot = $env:UNREALDB_BACKUP_PATH,
    [string]$StoragePath = $env:UNREALDB_STORAGE_PATH,
    [string]$DatabaseHost = $env:UNREALDB_DB_HOST,
    [int]$DatabasePort = $(if ($env:UNREALDB_DB_PORT) { [int]$env:UNREALDB_DB_PORT } else { 3306 }),
    [string]$DatabaseName = $env:UNREALDB_DB_NAME,
    [string]$DatabaseUser = $env:UNREALDB_DB_USER,
    [string]$DatabasePassword = $env:UNREALDB_DB_PASSWORD,
    [string]$MySqlBin = $env:UNREALDB_MYSQL_BIN,
    [string]$TarExecutable = $env:UNREALDB_TAR,
    [int]$RetentionDays = $(if ($env:UNREALDB_BACKUP_RETENTION_DAYS) { [int]$env:UNREALDB_BACKUP_RETENTION_DAYS } else { 30 }),
    [switch]$DatabaseOnly,
    [switch]$MaintenanceConfirmed
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-Executable {
    param([string]$ConfiguredDirectory, [string]$ConfiguredFile, [string[]]$Names)
    if ($ConfiguredFile) {
        return (Resolve-Path -LiteralPath $ConfiguredFile -ErrorAction Stop).Path
    }
    if ($ConfiguredDirectory) {
        foreach ($name in $Names) {
            $candidate = Join-Path $ConfiguredDirectory $name
            if (Test-Path -LiteralPath $candidate -PathType Leaf) { return (Resolve-Path -LiteralPath $candidate).Path }
        }
    }
    foreach ($name in $Names) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command) { return $command.Source }
    }
    throw "Required executable was not found: $($Names -join ', ')"
}

function Quote-Argument {
    param([string]$Value)
    return '"' + ($Value -replace '([\\"]+)', '\$1') + '"'
}

if (-not $BackupRoot) { throw 'BackupRoot or UNREALDB_BACKUP_PATH is required.' }
if (-not $DatabaseHost) { throw 'DatabaseHost or UNREALDB_DB_HOST is required.' }
if (-not $DatabaseName) { throw 'DatabaseName or UNREALDB_DB_NAME is required.' }
if (-not $DatabaseUser) { throw 'DatabaseUser or UNREALDB_DB_USER is required.' }
if (-not $DatabasePassword) { throw 'DatabasePassword or UNREALDB_DB_PASSWORD is required.' }
if (-not $DatabaseOnly) {
    if (-not $StoragePath) { throw 'StoragePath or UNREALDB_STORAGE_PATH is required for a full backup.' }
    if (-not $MaintenanceConfirmed) {
        throw 'A full database+storage backup requires -MaintenanceConfirmed after write-producing workers/uploads have been stopped. Use -DatabaseOnly for an online database-only backup.'
    }
}

$RetentionDays = [Math]::Max(1, [Math]::Min(3650, $RetentionDays))
$backupRootFull = [System.IO.Path]::GetFullPath($BackupRoot)
[System.IO.Directory]::CreateDirectory($backupRootFull) | Out-Null
$timestamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')
$incomplete = Join-Path $backupRootFull ('.incomplete-' + $timestamp + '-' + [Guid]::NewGuid().ToString('N'))
$destination = Join-Path $backupRootFull $timestamp
[System.IO.Directory]::CreateDirectory($incomplete) | Out-Null

$mysqldump = Resolve-Executable -ConfiguredDirectory $MySqlBin -Names @('mysqldump.exe', 'mysqldump')
$databaseArchive = Join-Path $incomplete 'database.sql.gz'
$previousPassword = $env:MYSQL_PWD
try {
    $env:MYSQL_PWD = $DatabasePassword
    $psi = [System.Diagnostics.ProcessStartInfo]::new()
    $psi.FileName = $mysqldump
    $psi.Arguments = @(
        '--host=' + (Quote-Argument $DatabaseHost),
        '--port=' + $DatabasePort,
        '--user=' + (Quote-Argument $DatabaseUser),
        '--single-transaction',
        '--quick',
        '--routines',
        '--events',
        '--triggers',
        '--hex-blob',
        '--set-gtid-purged=OFF',
        '--default-character-set=utf8mb4',
        (Quote-Argument $DatabaseName)
    ) -join ' '
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true

    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $psi
    if (-not $process.Start()) { throw 'Could not start mysqldump.' }
    $stderrTask = $process.StandardError.ReadToEndAsync()
    $fileStream = [System.IO.File]::Create($databaseArchive)
    try {
        $gzip = [System.IO.Compression.GZipStream]::new($fileStream, [System.IO.Compression.CompressionLevel]::Optimal)
        try { $process.StandardOutput.BaseStream.CopyTo($gzip) }
        finally { $gzip.Dispose() }
    }
    finally { $fileStream.Dispose() }
    $process.WaitForExit()
    $stderr = $stderrTask.GetAwaiter().GetResult()
    if ($process.ExitCode -ne 0) { throw "mysqldump failed with exit code $($process.ExitCode): $stderr" }
}
finally {
    if ($null -eq $previousPassword) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
    else { $env:MYSQL_PWD = $previousPassword }
}

$storageIncluded = -not $DatabaseOnly
if ($storageIncluded) {
    $storageFull = (Resolve-Path -LiteralPath $StoragePath -ErrorAction Stop).Path
    if (-not (Test-Path -LiteralPath $storageFull -PathType Container)) { throw "Storage path is unavailable: $storageFull" }
    $tar = Resolve-Executable -ConfiguredFile $TarExecutable -Names @('tar.exe', 'tar')
    $storageArchive = Join-Path $incomplete 'storage.tar.gz'
    $tarProcess = Start-Process -FilePath $tar -ArgumentList @('-C', $storageFull, '-czf', $storageArchive, '.') -Wait -NoNewWindow -PassThru
    if ($tarProcess.ExitCode -ne 0) { throw "Storage archive failed with exit code $($tarProcess.ExitCode)." }
}

$metadata = [ordered]@{
    format = 1
    created_at = [DateTime]::UtcNow.ToString('o')
    database = $DatabaseName
    database_host = $DatabaseHost
    storage_path = $(if ($StoragePath) { [System.IO.Path]::GetFullPath($StoragePath) } else { '' })
    storage_included = $storageIncluded
    app_version = $(if ($env:APP_VERSION) { $env:APP_VERSION } else { 'unknown' })
}
$metadata | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $incomplete 'metadata.json') -Encoding UTF8

$artifactNames = @('database.sql.gz', 'metadata.json')
if ($storageIncluded) { $artifactNames += 'storage.tar.gz' }
$checksumLines = foreach ($name in $artifactNames) {
    $hash = (Get-FileHash -LiteralPath (Join-Path $incomplete $name) -Algorithm SHA256).Hash.ToLowerInvariant()
    "$hash *$name"
}
$checksumLines | Set-Content -LiteralPath (Join-Path $incomplete 'SHA256SUMS') -Encoding ASCII

$verifyScript = Join-Path $PSScriptRoot 'verify-unrealdb-backup.ps1'
& $verifyScript -BackupDirectory $incomplete -TarExecutable $TarExecutable | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Backup verification failed.' }

if (Test-Path -LiteralPath $destination) { throw "Backup destination already exists: $destination" }
Move-Item -LiteralPath $incomplete -Destination $destination

$cutoff = [DateTime]::UtcNow.AddDays(-$RetentionDays)
Get-ChildItem -LiteralPath $backupRootFull -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match '^\d{8}T\d{6}Z$' -and $_.LastWriteTimeUtc -lt $cutoff } |
    Remove-Item -Recurse -Force

[ordered]@{
    ok = $true
    backup_directory = $destination
    database_only = $DatabaseOnly.IsPresent
    storage_included = $storageIncluded
    retention_days = $RetentionDays
} | ConvertTo-Json -Depth 5
