[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$BackupDirectory,
    [string]$TarExecutable = $env:UNREALDB_TAR
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-Executable {
    param([string]$Configured, [string[]]$Names)
    if ($Configured) {
        $resolved = Resolve-Path -LiteralPath $Configured -ErrorAction Stop
        return $resolved.Path
    }
    foreach ($name in $Names) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command) { return $command.Source }
    }
    throw "Required executable was not found: $($Names -join ', ')"
}

$backup = (Resolve-Path -LiteralPath $BackupDirectory -ErrorAction Stop).Path
$checksumsPath = Join-Path $backup 'SHA256SUMS'
$metadataPath = Join-Path $backup 'metadata.json'
$databasePath = Join-Path $backup 'database.sql.gz'

if (-not (Test-Path -LiteralPath $checksumsPath -PathType Leaf)) { throw 'SHA256SUMS is missing.' }
if (-not (Test-Path -LiteralPath $metadataPath -PathType Leaf)) { throw 'metadata.json is missing.' }
if (-not (Test-Path -LiteralPath $databasePath -PathType Leaf)) { throw 'database.sql.gz is missing.' }

$metadata = Get-Content -LiteralPath $metadataPath -Raw | ConvertFrom-Json
$verified = @()
foreach ($line in Get-Content -LiteralPath $checksumsPath) {
    if ([string]::IsNullOrWhiteSpace($line)) { continue }
    if ($line -notmatch '^([0-9a-fA-F]{64})\s+\*(.+)$') {
        throw "Invalid SHA256SUMS line: $line"
    }
    $expected = $Matches[1].ToUpperInvariant()
    $name = $Matches[2]
    if ($name.Contains('/') -or $name.Contains('\')) { throw "Checksum entry must be a local file name: $name" }
    $path = Join-Path $backup $name
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Backup file is missing: $name" }
    $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToUpperInvariant()
    if ($actual -ne $expected) { throw "Checksum mismatch for $name" }
    $verified += $name
}

# Stream the complete compressed dump through GZipStream. This validates that
# the archive can be decompressed without materialising the SQL in memory.
$input = [System.IO.File]::OpenRead($databasePath)
try {
    $gzip = [System.IO.Compression.GZipStream]::new($input, [System.IO.Compression.CompressionMode]::Decompress)
    try {
        $buffer = New-Object byte[] 1048576
        [long]$decompressedBytes = 0
        while (($read = $gzip.Read($buffer, 0, $buffer.Length)) -gt 0) {
            $decompressedBytes += $read
        }
    }
    finally { $gzip.Dispose() }
}
finally { $input.Dispose() }
if ($decompressedBytes -le 0) { throw 'Database dump decompresses to zero bytes.' }

$storageIncluded = [bool]$metadata.storage_included
$storageArchive = Join-Path $backup 'storage.tar.gz'
if ($storageIncluded) {
    if (-not (Test-Path -LiteralPath $storageArchive -PathType Leaf)) { throw 'metadata.json expects storage.tar.gz, but it is missing.' }
    $tar = Resolve-Executable -Configured $TarExecutable -Names @('tar.exe', 'tar')
    $process = Start-Process -FilePath $tar -ArgumentList @('-tzf', $storageArchive) -Wait -NoNewWindow -PassThru
    if ($process.ExitCode -ne 0) { throw "Storage archive listing failed with exit code $($process.ExitCode)." }
}

$result = [ordered]@{
    ok = $true
    backup_directory = $backup
    created_at = [string]$metadata.created_at
    database = [string]$metadata.database
    storage_included = $storageIncluded
    database_decompressed_bytes = $decompressedBytes
    checksum_files = $verified
}
$result | ConvertTo-Json -Depth 5
