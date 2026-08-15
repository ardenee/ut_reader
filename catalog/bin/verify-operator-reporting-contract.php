#!/usr/bin/env php
<?php
/**
 * Read-only source + optional live parity verification for operator job reporting.
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

$browser = $read('src/Infrastructure/Persistence/PdoBackgroundJobBrowserQuery.php');
$displayCounts = $read('src/Infrastructure/Persistence/PdoBackgroundJobDisplayCountQuery.php');
$scope = $read('src/Infrastructure/Persistence/PdoBackgroundJobSearchScope.php');
$snapshot = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php');
$systemQuery = $read('src/Infrastructure/Persistence/PdoSystemOperationsQuery.php');
$operational = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php');
$workerEndpoint = $read('api/v1/job-worker-status.php');
$operationsPage = $read('system-operations.php');
$backgroundPage = $read('background-jobs.php');
$backgroundUi = $read('assets/background-jobs-async-cleanup.js');

$record(
    'one_operator_visibility_policy',
    str_contains($browser, 'PdoBackgroundJobSearchScope')
        && str_contains($browser, 'PdoBackgroundJobDisplayCountQuery')
        && str_contains($snapshot, 'PdoBackgroundJobSearchScope')
        && str_contains($snapshot, 'PdoBackgroundJobDisplayCountQuery')
        && str_contains($scope, 'j.parent_job_id IS NULL OR ')
        && str_contains($displayCounts, 'BackgroundJobDisplaySql::operatorStatus('),
    'Background Jobs and System Operations must derive job visibility/counts from the same persistence policy.'
);
$record(
    'system_operations_does_not_count_raw_rows',
    str_contains($systemQuery, 'PdoBackgroundJobOperatorSnapshotQuery')
        && !str_contains($systemQuery, 'SUM(status="queued")')
        && !str_contains($systemQuery, 'SUM(status="running")'),
    'Operator diagnostics must not recreate durable execution-row totals as job totals.'
);
$record(
    'worker_health_keeps_durable_counts',
    str_contains($operational, 'SUM(status="queued") queued')
        && str_contains($operational, 'SUM(status="running") running')
        && str_contains($workerEndpoint, '$counts = $operational->queueCounts($queueName);')
        && str_contains($workerEndpoint, 'CatalogWorkerStatusPolicy::evaluate('),
    'Worker-health/admission decisions still need exact durable queue state even though those counts are not operator headlines.'
);
$record(
    'operator_pages_are_job_centric',
    !preg_match('/\bwork[ -]?units?\b/i', $operationsPage)
        && !preg_match('/\bwork[ -]?units?\b/i', $backgroundPage)
        && !preg_match('/\bwork[ -]?units?\b/i', $backgroundUi),
    'Operator-facing pages must consistently report jobs, not internal workflow/execution-row totals.'
);
$record(
    'runtime_age_is_not_a_timeout',
    str_contains($operationsPage, 'never an automatic timeout')
        && str_contains($operationsPage, 'jobs are not failed by age')
        && str_contains($backgroundPage, 'A long-running live job is never recovered because of elapsed time'),
    'Age is diagnostic information only; it must never imply lease/timeout recovery semantics.'
);
$record(
    'operator_snapshot_is_current_not_history_scan',
    str_contains($snapshot, 'j.status IN ("queued","running","failed","dead_letter")')
        && str_contains($systemQuery, 'status IN ("queued","running","failed","dead_letter")'),
    'System Operations must stay bounded to current/actionable rows rather than aggregate retained completed/cancelled history.'
);

$syntaxTargets = [
    'bin/verify-operator-reporting-contract.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php',
    'src/Infrastructure/Persistence/PdoSystemOperationsQuery.php',
    'system-operations.php',
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

        // Rebuild the exact current/actionable Background Jobs display scope
        // without invoking the full browser query, whose All/Completed tabs may
        // legitimately aggregate retained terminal history.
        $scopeQuery = new \UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobSearchScope($application->db);
        $currentScope = $scopeQuery->build($queue, '');
        $currentWhere = '(' . $currentScope['where'] . ') AND j.status IN ("queued","running","failed","dead_letter")';
        $expectedCounts = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobDisplayCountQuery(
            $application->db
        ))->counts($currentScope['from'], $currentWhere, $currentScope['params']);

        $operatorResult = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperatorSnapshotQuery(
            $application->db
        ))->current($queue);
        $durableResult = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery(
            $application->db,
            $application->config
        ))->queueCounts($queue);

        $mismatches = [];
        foreach (['queued', 'running', 'failed', 'dead_letter'] as $status) {
            $backgroundCount = max(0, (int)($expectedCounts[$status] ?? 0));
            $operatorCount = max(0, (int)($operatorResult[$status] ?? 0));
            if ($backgroundCount !== $operatorCount) {
                $mismatches[$status] = ['background_jobs_policy' => $backgroundCount, 'system_operations' => $operatorCount];
            }
        }
        $record(
            'runtime_operator_count_parity',
            $mismatches === [],
            json_encode([
                'queue' => $queue,
                'operator_counts' => [
                    'queued' => $operatorResult['queued'],
                    'running' => $operatorResult['running'],
                    'failed' => $operatorResult['failed'],
                    'dead_letter' => $operatorResult['dead_letter'],
                ],
                'durable_health_counts' => [
                    'queued' => $durableResult['queued'],
                    'ready' => $durableResult['ready'],
                    'running' => $durableResult['running'],
                ],
                'mismatches' => $mismatches,
            ], JSON_UNESCAPED_SLASHES) ?: ''
        );
    } catch (Throwable $error) {
        $record('runtime_operator_count_parity', false, get_class($error) . ': ' . $error->getMessage());
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
