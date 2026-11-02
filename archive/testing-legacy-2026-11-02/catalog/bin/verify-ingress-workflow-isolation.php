#!/usr/bin/env php
<?php
/**
 * Cross-ingress workflow isolation regression.
 *
 * Admin Upload Bucket and Public Upload may enqueue/process their own files and
 * wake worker processes. They must not repair, requeue, migrate or backfill
 * unrelated historical durable rows as an ingress side effect.
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
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$starter = $read('src/Infrastructure/Jobs/CatalogQueueWorkerStarter.php');
$reconciler = $read('src/Infrastructure/Jobs/CatalogWorkerPoolReconciler.php');
$bucketFinalizer = $read('src/Infrastructure/Import/CatalogBucketBatchFinalizer.php');
$bucketQueue = $read('src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$bucketChunkApi = $read('api/v1/upload-bucket-chunk.php');
$bucketState = $read('src/Infrastructure/Import/CatalogBucketProcessingStateService.php');
$publicApi = $read('api/v1/public-upload.php');
$publicHandler = $read('src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php');
$maintenance = $read('bin/repair-background-job-compatibility.php');
$backfill = $read('bin/backfill-invalid-ue-system-errors.php');

$record(
    'worker_construction_is_history_side_effect_free',
    !str_contains($factory, 'PdoInvalidUeSystemErrorBackfill')
        && !str_contains($factory, 'PdoArchiveProfileMismatchOutcomeRepair')
        && !str_contains($factory, 'PdoArchiveParentLifecycleRepair')
        && !str_contains($factory, 'synchronizeQueuedPolicies()'),
    'A worker created for a new upload must not mutate historical jobs/errors.'
);

$record(
    'automatic_wake_starts_processes_only',
    str_contains($starter, 'new CatalogDetachedWorker($this->config)')
        && str_contains($starter, '$launcher->start($queueName, 10000)')
        && !str_contains($starter, 'new CatalogOrphanedJobRecovery(')
        && !str_contains($starter, 'new CatalogWorkerPoolReconciler(')
        && !str_contains($starter, 'new PdoJobQueue('),
    'Feature wake may start processes but may not recover/reconcile/rewrite durable queue rows.'
);

$record(
    'operator_start_retains_explicit_recovery',
    str_contains($reconciler, 'CatalogOrphanedJobRecovery')
        && str_contains($reconciler, 'recoverInactiveQueue($queueName)'),
    'Proven-dead-worker recovery remains available behind explicit operator Start/Resume.'
);

$record(
    'admin_bucket_finalization_is_append_only',
    str_contains($bucketFinalizer, '$queue->enqueueCompletedUpload($uploadId, $userId)')
        && str_contains($bucketFinalizer, 'CatalogQueueWorkerStarter')
        && !str_contains($bucketFinalizer, 'CatalogOrphanedJobRecovery')
        && !str_contains($bucketFinalizer, 'CatalogBucketProcessingActive')
        && !str_contains($bucketFinalizer, 'migrateLegacyQueuedJobs()')
        && !str_contains($bucketFinalizer, 'activeQueues')
        && !str_contains($bucketFinalizer, 'legacyQueueName()'),
    'A new admin batch must append while older jobs run; it must not prepare or migrate the whole queue.'
);

$record(
    'legacy_bucket_queue_migration_is_maintenance_only',
    str_contains($bucketQueue, 'public function migrateLegacyQueuedJobs()')
        && str_contains($maintenance, 'migrateLegacyQueuedJobs()')
        && !str_contains($bucketFinalizer, 'migrateLegacyQueuedJobs()'),
    'Legacy queue migration remains available explicitly but is never triggered by a new upload.'
);

$record(
    'legacy_admin_begin_batch_cannot_pause_workers',
    str_contains($bucketChunkApi, "'pause_supported' => false")
        && !str_contains($bucketChunkApi, '$processingState->status(true)')
        && !str_contains($bucketState, 'requestStop('),
    'Even an old cached admin client must not be able to pause a long-running queue as part of upload handoff.'
);

$record(
    'public_upload_wake_uses_isolated_starter',
    str_contains($publicApi, 'if ($action === \'wake\')')
        && str_contains($publicApi, 'CatalogQueueWorkerStarter')
        && !str_contains($publicApi, 'CatalogOrphanedJobRecovery')
        && !str_contains($publicApi, 'CatalogWorkerPoolReconciler'),
    'Public contribution wake must obey the same no-history-mutation boundary.'
);

$record(
    'public_upload_followup_is_file_scoped',
    str_contains($publicHandler, 'JobType::REFRESH_UNVERIFIED_GAME_MATCHES')
        && str_contains($publicHandler, '[\'file_id\' => $fileId, \'scope\' => \'file\']')
        && !str_contains($publicHandler, "['scope' => 'bucket']"),
    'A public contribution may refresh evidence only for the file it just staged.'
);

$record(
    'historical_maintenance_is_explicit',
    str_contains($maintenance, 'array_key_exists(\'execute\', $options)')
        && str_contains($maintenance, "'changed' => false")
        && str_contains($backfill, "'mode' => 'ledger_only'")
        && str_contains($backfill, "'workers_started' => false"),
    'Historical compatibility repair/backfill must remain deliberate operator maintenance.'
);

$syntaxTargets = [
    'bin/verify-ingress-workflow-isolation.php',
    'bin/repair-background-job-compatibility.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogQueueWorkerStarter.php',
    'src/Infrastructure/Import/CatalogBucketBatchFinalizer.php',
    'src/Infrastructure/Import/CatalogBucketProcessingStateService.php',
    'api/v1/upload-bucket-chunk.php',
    'api/v1/public-upload.php',
    'src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php',
];
$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ': could not run php -l';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
