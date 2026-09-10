#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for lightweight filename/location disaster-recovery backups.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $value = @file_get_contents($path);
    return is_string($value) ? $value : '';
};

$page = $read('game-backups.php');
$store = $read('src/Infrastructure/Storage/FileIdentityBackupStore.php');
$download = $read('file-identity-backup-download.php');

$checks = [
    'backup UI exposes create, download and delete actions' =>
        str_contains($page, 'identity_export')
        && str_contains($page, 'identity_delete')
        && str_contains($page, 'Create filename/location backup')
        && str_contains($page, 'file-identity-backup-download.php?file='),
    'manifest records original and hash-named storage identity' =>
        str_contains($store, "'original_name'")
        && str_contains($store, "'stored_name'")
        && str_contains($store, "'relative_path'")
        && str_contains($store, "'storage_relative_path'")
        && str_contains($store, "'physical_path'"),
    'manifest includes integrity fields but not package metadata' =>
        str_contains($store, "'file_size'")
        && str_contains($store, "'md5'")
        && str_contains($store, "'sha1'")
        && !str_contains($store, 'ue_file_metadata')
        && !str_contains($store, 'ue_dependencies')
        && !str_contains($store, 'ue_exports')
        && !str_contains($store, 'ue_imports'),
    'export is paged rather than loading the whole file table into memory' =>
        str_contains($store, 'WHERE f.id>? ORDER BY f.id ASC LIMIT ')
        && str_contains($store, 'private const PAGE_SIZE = 5000;')
        && str_contains($store, '$statement->fetch(PDO::FETCH_ASSOC)'),
    'recovery backups default to catalog storage and are independently checksummed' =>
        str_contains($store, "'file-identity-backups'")
        && str_contains($store, "hash_file('sha256', $path)")
        && str_contains($store, "'unrealdb-file-identity-v1'"),
    'download remains admin-only and streams instead of buffering the CSV' =>
        str_contains($download, "catalog_require_admin_page('File Identity Backup')")
        && str_contains($download, "fopen($path, 'rb')")
        && str_contains($download, 'fpassthru($handle)'),
    'page describes recovery manifest as non-database non-package-copy backup' =>
        str_contains($page, 'This is not a database dump and it does not copy package files.')
        && str_contains($page, 'hash-named storage location'),
];

$failures = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failures[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'game-backups.php',
    'src/Infrastructure/Storage/FileIdentityBackupStore.php',
    'file-identity-backup-download.php',
    __FILE__,
] as $relative) {
    $path = $relative === __FILE__
        ? __FILE__
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
if ($syntaxFailures !== []) {
    $failures[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

$result = [
    'ok' => $failures === [],
    'checks' => count($checks) + 1,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
