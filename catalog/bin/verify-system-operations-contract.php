#!/usr/bin/env php
<?php
/**
 * Read-only source/runtime contract for the in-product operations console.
 * Use --run on the production host to execute the bounded operational queries.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$run = in_array('--run', array_slice($argv, 1), true);
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

$page = $read('system-operations.php');
$query = $read('src/Infrastructure/Persistence/PdoSystemOperationsQuery.php');
$storage = $read('src/Infrastructure/Storage/LocalFilesystemPackageStorage.php');
$dashboard = $read('dashboard.php');

$record(
    'operations_console_is_admin_read_only',
    str_contains($page, "catalog_require_admin_page('System Operations')")
        && str_contains($page, 'session_write_close()')
        && !str_contains($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'")
        && !str_contains($page, 'catalog_check_csrf('),
    'The production console must not mutate queue/database/storage state.'
);
$record(
    'operations_console_is_discoverable',
    str_contains($dashboard, "'System Operations' => 'system-operations.php'")
        && str_contains($dashboard, "'7. System operations'"),
    'A solo maintainer must be able to reach diagnostics from the normal admin dashboard.'
);
$record(
    'queue_ages_are_visibility_not_timeouts',
    str_contains($page, 'Age, not a timeout')
        && str_contains($page, 'jobs are not failed by age')
        && str_contains($page, 'does not fail or steal a live job merely because it has been running for a long time.'),
    'Runtime age is diagnostic only and must not reintroduce lease/timeout semantics.'
);
$record(
    'queue_blockers_are_explained',
    str_contains($query, 'concurrency_key')
        && str_contains($page, 'Concurrency-key blocked')
        && str_contains($page, 'Resource-class blocked')
        && str_contains($page, 'CatalogJobResourceLimitStore'),
    'The UI must separate concurrency-key serialization from resource-class capacity.'
);
$record(
    'storage_health_uses_package_boundary',
    str_contains($page, 'LocalFilesystemPackageStorage')
        && str_contains($storage, 'function health()'),
    'Operations and package workflows must inspect the same configured storage root.'
);
$record(
    'database_summary_isolated',
    str_contains($query, 'information_schema.tables')
        && !str_contains($page, 'information_schema.tables'),
    'Database diagnostics belong in a focused Infrastructure read model.'
);

$syntaxTargets = [
    'system-operations.php',
    'dashboard.php',
    'src/Infrastructure/Persistence/PdoSystemOperationsQuery.php',
    'src/Infrastructure/Storage/LocalFilesystemPackageStorage.php',
    'bin/verify-system-operations-contract.php',
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

if ($run) {
    try {
        require_once $root . '/bootstrap/operational.php';
        $application = catalog_operational_application();
        $queryObject = new \UnrealDb\Catalog\Infrastructure\Persistence\PdoSystemOperationsQuery($application->db);
        $database = $queryObject->database();
        $queues = $queryObject->queues();
        $storageHealth = (new \UnrealDb\Catalog\Infrastructure\Storage\LocalFilesystemPackageStorage(
            (string)$application->config['storage_path'],
            $root
        ))->health();
        $record(
            'runtime_operations_snapshot',
            $database['database'] !== ''
                && $database['version'] !== ''
                && !empty($storageHealth['available']),
            json_encode([
                'database' => $database,
                'queues' => $queues,
                'storage' => $storageHealth,
            ], JSON_UNESCAPED_SLASHES) ?: ''
        );
    } catch (Throwable $error) {
        $record('runtime_operations_snapshot', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'runtime_checked' => $run,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
