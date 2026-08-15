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
    [string]$PhpExecutable = $env:UNREALDB_PHP,
    [string]$TarExecutable = $env:UNREALDB_TAR,
    [switch]$DatabaseOnly
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
            if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                return (Resolve-Path -LiteralPath $candidate).Path
            }
        }
    }
    foreach ($name in $Names) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command) { return $command.Source }
    }
    throw "Required executable was not found: $($Names -join ', ')"
}

function Add-Check {
    param(
        [System.Collections.Generic.List[object]]$Checks,
        [string]$Name,
        [bool]$Ok,
        [string]$Detail
    )
    $Checks.Add([ordered]@{ check = $Name; ok = $Ok; detail = $Detail })
}

function Drive-Space {
    param([string]$Path)
    $full = [System.IO.Path]::GetFullPath($Path)
    $root = [System.IO.Path]::GetPathRoot($full)
    if (-not $root) { return $null }
    $drive = [System.IO.DriveInfo]::new($root)
    if (-not $drive.IsReady) { return $null }
    return [ordered]@{
        root = $root
        total_bytes = [int64]$drive.TotalSize
        free_bytes = [int64]$drive.AvailableFreeSpace
    }
}

$checks = [System.Collections.Generic.List[object]]::new()
$failures = [System.Collections.Generic.List[string]]::new()
$warnings = [System.Collections.Generic.List[string]]::new()

foreach ($required in @(
    @{ name = 'backup_root_configured'; value = $BackupRoot; label = 'BackupRoot or UNREALDB_BACKUP_PATH' },
    @{ name = 'database_host_configured'; value = $DatabaseHost; label = 'DatabaseHost or UNREALDB_DB_HOST' },
    @{ name = 'database_name_configured'; value = $DatabaseName; label = 'DatabaseName or UNREALDB_DB_NAME' },
    @{ name = 'database_user_configured'; value = $DatabaseUser; label = 'DatabaseUser or UNREALDB_DB_USER' },
    @{ name = 'database_password_configured'; value = $DatabasePassword; label = 'DatabasePassword or UNREALDB_DB_PASSWORD' }
)) {
    $ok = -not [string]::IsNullOrWhiteSpace([string]$required.value)
    Add-Check $checks $required.name $ok $(if ($ok) { 'configured' } else { $required.label + ' is required' })
    if (-not $ok) { $failures.Add([string]$required.label + ' is required.') }
}

if (-not $DatabaseOnly) {
    $storageConfigured = -not [string]::IsNullOrWhiteSpace($StoragePath)
    Add-Check $checks 'storage_path_configured' $storageConfigured $(if ($storageConfigured) { 'configured' } else { 'StoragePath or UNREALDB_STORAGE_PATH is required for a full backup' })
    if (-not $storageConfigured) { $failures.Add('StoragePath or UNREALDB_STORAGE_PATH is required for a full backup.') }
}

$mysqldump = $null
$mysql = $null
$php = $null
$tar = $null
try {
    $mysqldump = Resolve-Executable -ConfiguredDirectory $MySqlBin -Names @('mysqldump.exe', 'mysqldump')
    Add-Check $checks 'mysqldump_available' $true $mysqldump
} catch {
    Add-Check $checks 'mysqldump_available' $false $_.Exception.Message
    $failures.Add($_.Exception.Message)
}
try {
    $mysql = Resolve-Executable -ConfiguredDirectory $MySqlBin -Names @('mysql.exe', 'mysql')
    Add-Check $checks 'mysql_client_available' $true $mysql
} catch {
    Add-Check $checks 'mysql_client_available' $false $_.Exception.Message
    $failures.Add($_.Exception.Message)
}
try {
    $php = Resolve-Executable -ConfiguredFile $PhpExecutable -Names @('php.exe', 'php')
    Add-Check $checks 'php_available' $true $php
} catch {
    Add-Check $checks 'php_available' $false $_.Exception.Message
    $failures.Add($_.Exception.Message)
}
if (-not $DatabaseOnly) {
    try {
        $tar = Resolve-Executable -ConfiguredFile $TarExecutable -Names @('tar.exe', 'tar')
        Add-Check $checks 'tar_available' $true $tar
    } catch {
        Add-Check $checks 'tar_available' $false $_.Exception.Message
        $failures.Add($_.Exception.Message)
    }
}

$backupSpace = $null
if ($BackupRoot) {
    $backupExists = Test-Path -LiteralPath $BackupRoot -PathType Container
    Add-Check $checks 'backup_root_exists' $backupExists $(if ($backupExists) { [System.IO.Path]::GetFullPath($BackupRoot) } else { 'Backup destination directory does not exist.' })
    if (-not $backupExists) {
        $failures.Add('Backup destination directory does not exist: ' + $BackupRoot)
    } else {
        try {
            $backupSpace = Drive-Space $BackupRoot
            Add-Check $checks 'backup_destination_space_readable' ($null -ne $backupSpace) $(if ($backupSpace) { 'free_bytes=' + $backupSpace.free_bytes } else { 'Drive capacity is unavailable.' })
            if ($null -eq $backupSpace) { $warnings.Add('Backup destination free space could not be determined.') }
        } catch {
            Add-Check $checks 'backup_destination_space_readable' $false $_.Exception.Message
            $warnings.Add('Backup destination free space could not be determined: ' + $_.Exception.Message)
        }
    }
}

$storageSpace = $null
if (-not $DatabaseOnly -and $StoragePath) {
    $storageExists = Test-Path -LiteralPath $StoragePath -PathType Container
    Add-Check $checks 'storage_path_exists' $storageExists $(if ($storageExists) { [System.IO.Path]::GetFullPath($StoragePath) } else { 'Package storage directory does not exist.' })
    if (-not $storageExists) {
        $failures.Add('Package storage directory does not exist: ' + $StoragePath)
    } else {
        try {
            $storageSpace = Drive-Space $StoragePath
            Add-Check $checks 'storage_space_readable' ($null -ne $storageSpace) $(if ($storageSpace) { 'free_bytes=' + $storageSpace.free_bytes } else { 'Drive capacity is unavailable.' })
        } catch {
            Add-Check $checks 'storage_space_readable' $false $_.Exception.Message
            $warnings.Add('Package storage drive capacity could not be determined: ' + $_.Exception.Message)
        }
    }
}

if ($mysql -and $DatabaseHost -and $DatabaseName -and $DatabaseUser -and $DatabasePassword) {
    $previousPassword = $env:MYSQL_PWD
    try {
        $env:MYSQL_PWD = $DatabasePassword
        $arguments = @(
            '--host=' + $DatabaseHost,
            '--port=' + $DatabasePort,
            '--user=' + $DatabaseUser,
            '--batch',
            '--skip-column-names',
            '--database=' + $DatabaseName,
            '--execute=SELECT 1'
        )
        $output = & $mysql @arguments 2>&1
        $connected = $LASTEXITCODE -eq 0 -and (($output | Out-String).Trim() -eq '1')
        Add-Check $checks 'database_connectivity' $connected $(if ($connected) { 'SELECT 1 succeeded' } else { (($output | Out-String).Trim()) })
        if (-not $connected) { $failures.Add('Database connectivity check failed.') }
    } finally {
        if ($null -eq $previousPassword) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
        else { $env:MYSQL_PWD = $previousPassword }
    }
}

$result = [ordered]@{
    ok = $failures.Count -eq 0
    mode = $(if ($DatabaseOnly) { 'database-only' } else { 'database-and-storage' })
    checks = $checks
    backup_destination = $backupSpace
    storage_drive = $storageSpace
    warnings = $warnings
    failures = $failures
}
$result | ConvertTo-Json -Depth 8
exit $(if ($failures.Count -eq 0) { 0 } else { 2 })
