#!/usr/bin/env php
<?php
/**
 * Bounded, read-only production smoke for the admin/query surfaces most useful
 * immediately after a deployment. Use --run to execute against the configured DB/storage.
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
    $source = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($source) ? $source : '';
};

$bootstrap = $read('bootstrap/operational.php');
$readinessFactory = $read('src/Infrastructure/Composition/CatalogSystemReadinessFactory.php');
$gameQuery = $read('src/Infrastructure/Persistence/PdoGameCatalogListQuery.php');
$jobBrowser = $read('src/Infrastructure/Persistence/PdoBackgroundJobBrowserQuery.php');
$operatorJobs = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php');
$operations = $read('src/Infrastructure/Persistence/PdoSystemOperationsQuery.php');
$storage = $read('src/Infrastructure/Storage/LocalFilesystemPackageStorage.php');

$record(
    'smoke_uses_session_free_operational_bootstrap',
    str_contains($bootstrap, 'catalog_operational_application')
        && str_contains($bootstrap, 'boot(false)'),
    'Deployment smoke must not acquire browser session state or page middleware.'
);
$record(
    'smoke_targets_are_existing_read_models',
    str_contains($readinessFactory, 'SystemReadinessService')
        && str_contains($gameQuery, 'public function all(): array')
        && str_contains($jobBrowser, 'public function fetch(')
        && str_contains($operatorJobs, 'public function current(')
        && str_contains($operations, 'public function database(): array')
        && str_contains($storage, 'public function health(): array'),
    'Smoke coverage should exercise existing production read/composition surfaces rather than duplicate their SQL.'
);
$record(
    'smoke_runtime_job_query_is_current_scope',
    str_contains($operatorJobs, 'j.status IN ("queued","running","failed","dead_letter")')
        && !str_contains($operatorJobs, 'status="completed"'),
    'The live post-deploy smoke must not aggregate retained Background Jobs terminal history.'
);
$record(
    'smoke_has_no_mutation_path',
    !preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP)\b/i', $gameQuery)
        && !preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP)\b/i', $operations)
        && !preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP)\b/i', $operatorJobs),
    'The bounded post-deploy smoke must remain read-only.'
);

$syntaxTargets = [
    'bin/verify-admin-runtime-smoke.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php',
    'src/Infrastructure/Persistence/PdoSystemOperationsQuery.php',
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
        $queue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';

        $readiness = \UnrealDb\Catalog\Infrastructure\Composition\CatalogSystemReadinessFactory::create(
            $application->db,
            $application->config
        )->check();
        $record(
            'runtime_readiness',
            $readiness->ready,
            json_encode($readiness->checkData(), JSON_UNESCAPED_SLASHES) ?: ''
        );

        $games = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogListQuery($application->db))->all();
        $record('runtime_game_catalog', is_array($games), 'games=' . count($games));

        $jobSnapshot = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperatorSnapshotQuery(
            $application->db
        ))->current($queue);
        $record(
            'runtime_background_jobs_current_scope',
            isset($jobSnapshot['queued'], $jobSnapshot['running'], $jobSnapshot['failed'], $jobSnapshot['dead_letter']),
            json_encode(['queue' => $queue, 'current_jobs' => $jobSnapshot], JSON_UNESCAPED_SLASHES) ?: ''
        );

        $operationsQuery = new \UnrealDb\Catalog\Infrastructure\Persistence\PdoSystemOperationsQuery($application->db);
        $database = $operationsQuery->database();
        $queues = $operationsQuery->queues();
        $record(
            'runtime_system_operations',
            ($database['database'] ?? '') !== '' && ($database['version'] ?? '') !== '',
            json_encode(['database' => $database, 'queues' => $queues], JSON_UNESCAPED_SLASHES) ?: ''
        );

        $storageHealth = (new \UnrealDb\Catalog\Infrastructure\Storage\LocalFilesystemPackageStorage(
            (string)$application->config['storage_path'],
            $root
        ))->health();
        $record(
            'runtime_package_storage',
            !empty($storageHealth['available']) && !empty($storageHealth['readable']) && !empty($storageHealth['writable']),
            json_encode($storageHealth, JSON_UNESCAPED_SLASHES) ?: ''
        );
    } catch (Throwable $error) {
        $record('runtime_admin_smoke', false, get_class($error) . ': ' . $error->getMessage());
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
