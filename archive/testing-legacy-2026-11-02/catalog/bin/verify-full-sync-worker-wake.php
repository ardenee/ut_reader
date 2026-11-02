#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies that queuing Full Sync also wakes the durable worker pool.
 * Role: Read-only source regression check preventing queued Full Sync jobs from waiting for a second manual Start click.
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
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$phpFiles = ['api/v1/full-sync-job.php'];
$syntaxFailures = [];
foreach ($phpFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exit);
    if ($exit !== 0) {
        $syntaxFailures[] = $relative . ': ' . implode(' ', $output);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$api = $read('api/v1/full-sync-job.php');
$client = $read('full-sync.js');

$enqueuePosition = strpos($api, '->enqueue(');
$wakePosition = strpos($api, 'new CatalogWorkerPoolReconciler');
$record(
    'full_sync_enqueue_wakes_worker_pool',
    $enqueuePosition !== false
        && $wakePosition !== false
        && $enqueuePosition < $wakePosition
        && str_contains($api, "->run(\$queueName, 'drain', null, \$userId)")
        && str_contains($api, 'session_write_close()')
        && str_contains($api, "'worker_ready' => \$workerReady")
        && str_contains($api, "'worker_warning' => \$workerWarning"),
    'The durable job must be committed first, then the queue endpoint must release the session lock and wake/reconcile workers before reporting success.'
);

$record(
    'worker_wake_failure_does_not_lose_job',
    str_contains($api, 'catch (Throwable $workerError)')
        && str_contains($api, 'Full Sync is queued, but the worker pool could not be started automatically')
        && str_contains($api, "'job_id' => \$jobId")
        && str_contains($api, 'CatalogSystemErrorRecorder::record')
        && str_contains($api, "'source_kind' => 'full-sync-worker-wake'"),
    'A worker-launch problem must leave the Full Sync row durably queued and persist a visible System Error.'
);

$record(
    'full_sync_ui_reports_worker_readiness',
    str_contains($client, 'waking the worker pool')
        && str_contains($client, 'worker_warning')
        && str_contains($client, 'worker_active_count')
        && str_contains($client, 'Worker pool ready'),
    'The Full Sync page must distinguish a ready worker pool from a safely queued job whose worker wake failed.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
