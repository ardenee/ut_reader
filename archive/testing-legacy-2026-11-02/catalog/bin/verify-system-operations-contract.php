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
$operatorSnapshot = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php');
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
    'worker_status_matches_background_jobs_policy',
    str_contains($page, 'CatalogWorkerStatusPolicy::evaluate(')
        && str_contains($page, 'PdoBackgroundJobOperationalQuery')
        && str_contains($page, 'queueCounts($queueName)'),
    'Worker running/orphaned/stopped-with-queue status must still use exact durable queue state and the shared policy.'
);
$record(
    'operator_counts_match_background_jobs_policy',
    str_contains($query, 'PdoBackgroundJobOperatorSnapshotQuery')
        && str_contains($operatorSnapshot, 'PdoBackgroundJobSearchScope')
        && str_contains($operatorSnapshot, 'PdoBackgroundJobDisplayCountQuery')
        && str_contains($page, 'same rolled-up operator scope as Background Jobs'),
    'Headline queued/running/failed counts must report operator-visible jobs rather than internal workflow rows.'
);
$record(
    'queue_ages_are_visibility_not_timeouts',
    str_contains($page, 'Age only; never an automatic timeout')
        && str_contains($page, 'jobs are not failed by age')
        && str_contains($page, 'Runtime ages are diagnostic only and never trigger automatic failure or stealing.'),
    'Runtime age is diagnostic only and must not reintroduce lease/timeout semantics.'
);
$record(
    'queue_blockers_are_explained_as_jobs_and_capacity',
    str_contains($operatorSnapshot, 'COUNT(DISTINCT COALESCE(q.parent_job_id,q.id))')
        && str_contains($page, 'Distinct jobs waiting on an identical target')
        && str_contains($page, 'Execution capacity by workload class')
        && str_contains($page, 'CatalogJobResourceLimitStore'),
    'Concurrency blocking should count affected jobs; resource pressure should be shown as capacity rather than another competing job total.'
);
$record(
    'operations_queue_history_is_bounded',
    str_contains($query, 'WHERE status IN ("queued","running","failed","dead_letter")')
        && str_contains($operatorSnapshot, 'j.status IN ("queued","running","failed","dead_letter")')
        && !str_contains($query, 'SUM(status="completed")'),
    'Opening diagnostics must not aggregate the complete terminal job archive.'
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
    'src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php',
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
