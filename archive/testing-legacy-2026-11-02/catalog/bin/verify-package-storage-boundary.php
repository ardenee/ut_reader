#!/usr/bin/env php
<?php
/**
 * Read-only/source + isolated-temp runtime contract for package storage.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Storage\LocalFilesystemPackageStorage;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$port = $read('src/Application/Storage/Contract/PackageStoragePort.php');
$adapter = $read('src/Infrastructure/Storage/LocalFilesystemPackageStorage.php');
$verified = $read('src/Infrastructure/Storage/CatalogVerifiedPackageStorage.php');
$download = $read('download.php');
$record(
    'storage_port_is_pure',
    str_contains($port, 'interface PackageStoragePort')
        && !str_contains($port, 'Infrastructure\\')
        && !str_contains($port, 'PDO')
        && !str_contains($port, 'file_get_contents(')
        && !str_contains($port, 'rename('),
    'Application owns only the contract, never local filesystem implementation details.'
);
$record(
    'local_storage_binds_port',
    str_contains($adapter, 'implements PackageStoragePort')
        && str_contains($adapter, 'LocalStoragePathGuard::resolveFile(')
        && str_contains($adapter, 'storeVerified(')
        && str_contains($adapter, 'rollbackVerified('),
    'Local disk remains the production implementation behind one boundary.'
);
$record(
    'verified_import_delegates_storage_policy',
    str_contains($verified, 'PackageStoragePort')
        && str_contains($verified, 'LocalFilesystemPackageStorage')
        && !str_contains($verified, 'mkdir(')
        && !str_contains($verified, 'rename('),
    'The compatibility collaborator must no longer own physical move/path policy.'
);
$record(
    'public_download_uses_storage_boundary',
    str_contains($download, 'LocalFilesystemPackageStorage')
        && !str_contains($download, 'LocalStoragePathGuard::resolveFile('),
    'Public downloads and verified import must share the same path-safety boundary.'
);

$temp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'unrealdb-storage-contract-' . bin2hex(random_bytes(6));
$app = $temp . DIRECTORY_SEPARATOR . 'catalog';
$storageRoot = $app . DIRECTORY_SEPARATOR . 'storage';
$source = $temp . DIRECTORY_SEPARATOR . 'source.utx';
try {
    @mkdir($storageRoot, 0775, true);
    file_put_contents($source, 'unrealdb-storage-contract');
    $storage = new LocalFilesystemPackageStorage($storageRoot, $app);
    $md5 = md5_file($source) ?: '';
    $stored = $storage->storeVerified($source, 'UT99', $md5, 'utx', false);
    $resolved = $storage->resolveExisting((string)$stored['relative_path']);
    $record(
        'runtime_store_and_resolve',
        !is_file($source)
            && is_file((string)$stored['destination'])
            && $resolved === realpath((string)$stored['destination'])
            && str_ends_with(str_replace('\\', '/', (string)$stored['relative_path']), '/games/ut99/verified/' . $md5 . '.utx'),
        'Canonical verified placement and guarded resolution must remain unchanged.'
    );
    $storage->rollbackVerified($stored);
    $record(
        'runtime_rollback_restores_source',
        is_file($source) && !is_file((string)$stored['destination']),
        'Downstream persistence failure must restore caller-owned bytes when possible.'
    );
    $health = $storage->health();
    $record(
        'runtime_storage_health',
        !empty($health['available']) && !empty($health['readable']) && !empty($health['writable']),
        'Operations readiness must inspect the same configured package-storage root.'
    );
} catch (Throwable $error) {
    $record('runtime_storage_boundary', false, $error->getMessage());
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $remove($path . DIRECTORY_SEPARATOR . $name);
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    };
    $remove($temp);
}

$syntaxTargets = [
    'src/Application/Storage/Contract/PackageStoragePort.php',
    'src/Infrastructure/Storage/LocalFilesystemPackageStorage.php',
    'src/Infrastructure/Storage/CatalogVerifiedPackageStorage.php',
    'download.php',
    'bin/verify-package-storage-boundary.php',
];
$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
