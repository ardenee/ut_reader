#!/usr/bin/env php
<?php
/**
 * Source + optional PowerShell parser contract for Windows backup/recovery tooling.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$repoRoot = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$read = static function (string $relative) use ($repoRoot): string {
    $value = @file_get_contents($repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$backup = $read('deploy/backup/unrealdb-backup.ps1');
$verify = $read('deploy/backup/verify-unrealdb-backup.ps1');
$restore = $read('deploy/backup/unrealdb-restore.ps1');

$record(
    'full_backup_requires_maintenance_confirmation',
    str_contains($backup, '-not $DatabaseOnly')
        && str_contains($backup, '-not $MaintenanceConfirmed')
        && str_contains($backup, 'write-producing workers/uploads have been stopped'),
    'A database+filesystem snapshot must not pretend to be coherent while imports are mutating storage.'
);
$record(
    'backup_is_staged_then_verified',
    str_contains($backup, '.incomplete-')
        && str_contains($backup, 'verify-unrealdb-backup.ps1')
        && str_contains($backup, 'Move-Item -LiteralPath $incomplete -Destination $destination'),
    'Incomplete backups must never look like completed recovery points.'
);
$record(
    'backup_streams_database_dump',
    str_contains($backup, 'RedirectStandardOutput = $true')
        && str_contains($backup, 'GZipStream')
        && str_contains($backup, 'CopyTo($gzip)')
        && str_contains($backup, '--single-transaction')
        && str_contains($backup, '--quick'),
    'Large database dumps must be streamed instead of materialised in PowerShell memory.'
);
$record(
    'backup_has_checksums_and_retention',
    str_contains($backup, 'Get-FileHash')
        && str_contains($backup, 'SHA256SUMS')
        && str_contains($backup, '$RetentionDays'),
    'Recovery points require integrity metadata and bounded retention.'
);
$record(
    'verification_streams_complete_database_archive',
    str_contains($verify, 'GZipStream')
        && str_contains($verify, 'while (($read = $gzip.Read(')
        && str_contains($verify, 'Checksum mismatch'),
    'Verification must read the complete compressed database dump and validate every published artifact checksum.'
);
$record(
    'restore_requires_exact_confirmation',
    str_contains($restore, '$ConfirmDatabase -ne $DatabaseName')
        && str_contains($restore, '-not $MaintenanceConfirmed')
        && str_contains($restore, "metadata.database -ne \$DatabaseName"),
    'Destructive restore must require maintenance acknowledgement, target confirmation and backup/database identity match.'
);
$record(
    'restore_runs_post_restore_integrity_checks',
    str_contains($restore, "'catalog/bin/migrate.php' 'verify'")
        && str_contains($restore, "'catalog/bin/verify-compact-only-metadata-runtime.php' '--database'"),
    'A restore is not complete until schema and compact-metadata integrity are proven.'
);

$scriptPaths = [
    $repoRoot . '/deploy/backup/unrealdb-backup.ps1',
    $repoRoot . '/deploy/backup/verify-unrealdb-backup.ps1',
    $repoRoot . '/deploy/backup/unrealdb-restore.ps1',
];
$parser = null;
foreach (['powershell.exe', 'pwsh.exe', 'pwsh'] as $candidate) {
    $path = trim((string)@shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . escapeshellarg($candidate) . ' 2>NUL'));
    if ($path !== '') {
        $parser = preg_split('/\R/', $path)[0] ?? null;
        if ($parser) break;
    }
}
if ($parser !== null && function_exists('proc_open')) {
    $parseFailures = [];
    foreach ($scriptPaths as $path) {
        $command = '$tokens=$null;$errors=$null;[System.Management.Automation.Language.Parser]::ParseFile('
            . var_export($path, true)
            . ',[ref]$tokens,[ref]$errors)|Out-Null;if($errors.Count){$errors|ForEach-Object{$_.Message};exit 2}';
        $pipes = [];
        $process = proc_open([$parser, '-NoProfile', '-NonInteractive', '-Command', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $parseFailures[] = basename($path) . ' parser could not start';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $parseFailures[] = basename($path) . ': ' . trim((string)$stdout . ' ' . (string)$stderr);
        }
    }
    $record('powershell_syntax', $parseFailures === [], implode(' | ', $parseFailures));
} else {
    $record('powershell_syntax', true, 'PowerShell parser unavailable on this host; source contracts still checked.');
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
