#!/usr/bin/env php
<?php
/**
 * Read-only contract for selectively extracted high-value read models.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
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

$games = $read('games.php');
$gameQuery = $read('src/Infrastructure/Persistence/PdoGameCatalogListQuery.php');
$record(
    'games_page_uses_read_model',
    str_contains($games, 'PdoGameCatalogListQuery')
        && !str_contains($games, 'SELECT g.id')
        && str_contains($gameQuery, 'ue_game_catalog_stats')
        && str_contains($gameQuery, 'PdoGameCatalogStats'),
    'The public controller should render the game-list projection rather than own its JOIN/fallback SQL.'
);

$metrics = $read('api/v1/metrics.php');
$metricsQuery = $read('src/Infrastructure/Persistence/PdoMetricsSnapshotQuery.php');
$storageMetrics = $read('src/Infrastructure/Storage/CatalogOperationalStorageMetrics.php');
$record(
    'metrics_endpoint_uses_read_models',
    str_contains($metrics, 'PdoMetricsSnapshotQuery')
        && str_contains($metrics, 'CatalogOperationalStorageMetrics')
        && !str_contains($metrics, 'FROM ue_background_jobs')
        && !str_contains($metrics, 'RecursiveDirectoryIterator')
        && str_contains($metricsQuery, 'FROM ue_background_jobs')
        && str_contains($storageMetrics, 'RecursiveDirectoryIterator'),
    'Database projection and filesystem traversal belong outside the HTTP endpoint.'
);

$operations = $read('system-operations.php');
$operationsQuery = $read('src/Infrastructure/Persistence/PdoSystemOperationsQuery.php');
$operatorSnapshot = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php');
$record(
    'operations_console_uses_bounded_read_models',
    str_contains($operations, 'PdoSystemOperationsQuery')
        && !str_contains($operations, 'information_schema.tables')
        && str_contains($operationsQuery, 'information_schema.tables')
        && str_contains($operationsQuery, 'PdoBackgroundJobOperatorSnapshotQuery')
        && str_contains($operatorSnapshot, 'COUNT(DISTINCT COALESCE(q.parent_job_id,q.id))')
        && str_contains($operatorSnapshot, 'j.status IN ("queued","running","failed","dead_letter")'),
    'Operational database/file summary and operator job pressure must remain isolated, bounded read models.'
);

$syntaxTargets = [
    'games.php',
    'api/v1/metrics.php',
    'system-operations.php',
    'src/Infrastructure/Persistence/PdoGameCatalogListQuery.php',
    'src/Infrastructure/Persistence/PdoMetricsSnapshotQuery.php',
    'src/Infrastructure/Persistence/PdoSystemOperationsQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php',
    'src/Infrastructure/Storage/CatalogOperationalStorageMetrics.php',
    'bin/verify-read-model-boundaries.php',
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
