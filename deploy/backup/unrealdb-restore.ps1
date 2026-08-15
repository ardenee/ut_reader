[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$BackupDirectory,
    [string]$DatabaseHost = $env:UNREALDB_DB_HOST,
    [int]$DatabasePort = $(if ($env:UNREALDB_DB_PORT) { [int]$env:UNREALDB_DB_PORT } else { 3306 }),
    [string]$DatabaseName = $env:UNREALDB_DB_NAME,
    [string]$DatabaseUser = $env:UNREALDB_DB_USER,
    [string]$DatabasePassword = $env:UNREALDB_DB_PASSWORD,
    [string]$StoragePath = $env:UNREALDB_STORAGE_PATH,
    [string]$MySqlBin = $env:UNREALDB_MYSQL_BIN,
    [string]$PhpExecutable = $env:UNREALDB_PHP,
    [string]$TarExecutable = $env:UNREALDB_TAR,
    [string]$ConfirmDatabase = $env:UNREALDB_RESTORE_CONFIRM,
    [switch]$RestoreStorage,
    [switch]$MaintenanceConfirmed
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-Executable {
    param([string]$ConfiguredDirectory, [string]$ConfiguredFile, [string[]]$Names)
    if ($ConfiguredFile) { return (Resolve-Path -LiteralPath $ConfiguredFile -ErrorAction Stop).Path }
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

if (-not $MaintenanceConfirmed) {
    throw 'Restore requires -MaintenanceConfirmed after Apache write traffic and UnrealDB worker processes have been stopped.'
}
if (-not $DatabaseHost) { throw 'DatabaseHost or UNREALDB_DB_HOST is required.' }
if (-not $DatabaseName) { throw 'DatabaseName or UNREALDB_DB_NAME is required.' }
if (-not $DatabaseUser) { throw 'DatabaseUser or UNREALDB_DB_USER is required.' }
if (-not $DatabasePassword) { throw 'DatabasePassword or UNREALDB_DB_PASSWORD is required.' }
if ($ConfirmDatabase -ne $DatabaseName) {
    throw 'Restore confirmation must exactly match the target database name.'
}

$backup = (Resolve-Path -LiteralPath $BackupDirectory -ErrorAction Stop).Path
$verifyScript = Join-Path $PSScriptRoot 'verify-unrealdb-backup.ps1'
& $verifyScript -BackupDirectory $backup -TarExecutable $TarExecutable | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Backup verification failed; restore was not started.' }

$metadata = Get-Content -LiteralPath (Join-Path $backup 'metadata.json') -Raw | ConvertFrom-Json
if ([string]$metadata.database -ne $DatabaseName) {
    throw "Backup belongs to database '$($metadata.database)', not '$DatabaseName'."
}
if ($RestoreStorage -and -not [bool]$metadata.storage_included) {
    throw 'This backup does not contain package storage.'
}
if ($RestoreStorage -and -not $StoragePath) {
    throw 'StoragePath or UNREALDB_STORAGE_PATH is required when -RestoreStorage is used.'
}

$mysql = Resolve-Executable -ConfiguredDirectory $MySqlBin -Names @('mysql.exe', 'mysql')
$previousPassword = $env:MYSQL_PWD
try {
    $env:MYSQL_PWD = $DatabasePassword
    $psi = [System.Diagnostics.ProcessStartInfo]::new()
    $psi.FileName = $mysql
    $psi.Arguments = @(
        '--host=' + (Quote-Argument $DatabaseHost),
        '--port=' + $DatabasePort,
        '--user=' + (Quote-Argument $DatabaseUser),
        '--default-character-set=utf8mb4',
        (Quote-Argument $DatabaseName)
    ) -join ' '
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardError = $true

    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $psi
    if (-not $process.Start()) { throw 'Could not start mysql client.' }
    $stderrTask = $process.StandardError.ReadToEndAsync()

    $input = [System.IO.File]::OpenRead((Join-Path $backup 'database.sql.gz'))
    try {
        $gzip = [System.IO.Compression.GZipStream]::new($input, [System.IO.Compression.CompressionMode]::Decompress)
        try {
            $gzip.CopyTo($process.StandardInput.BaseStream)
            $process.StandardInput.BaseStream.Flush()
        }
        finally { $gzip.Dispose() }
    }
    finally { $input.Dispose() }
    $process.StandardInput.Close()
    $process.WaitForExit()
    $stderr = $stderrTask.GetAwaiter().GetResult()
    if ($process.ExitCode -ne 0) { throw "mysql restore failed with exit code $($process.ExitCode): $stderr" }
}
finally {
    if ($null -eq $previousPassword) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
    else { $env:MYSQL_PWD = $previousPassword }
}

if ($RestoreStorage) {
    $storageFull = [System.IO.Path]::GetFullPath($StoragePath)
    [System.IO.Directory]::CreateDirectory($storageFull) | Out-Null
    Get-ChildItem -LiteralPath $storageFull -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
    $tar = Resolve-Executable -ConfiguredFile $TarExecutable -Names @('tar.exe', 'tar')
    $storageArchive = Join-Path $backup 'storage.tar.gz'
    & $tar '-C' $storageFull '-xzf' $storageArchive
    if ($LASTEXITCODE -ne 0) { throw "Storage restore failed with exit code $LASTEXITCODE." }
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$php = Resolve-Executable -ConfiguredFile $PhpExecutable -Names @('php.exe', 'php')

Push-Location $repoRoot
try {
    & $php 'catalog/bin/migrate.php' 'verify'
    if ($LASTEXITCODE -ne 0) { throw 'Schema verification failed after restore.' }
    & $php 'catalog/bin/verify-compact-only-metadata-runtime.php' '--database'
    if ($LASTEXITCODE -ne 0) { throw 'Compact metadata verification failed after restore.' }
}
finally { Pop-Location }

[ordered]@{
    ok = $true
    restored_database = $DatabaseName
    restored_storage = $RestoreStorage.IsPresent
    backup_directory = $backup
    post_restore_schema_verified = $true
    post_restore_compact_metadata_verified = $true
} | ConvertTo-Json -Depth 5
