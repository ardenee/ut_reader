#!/usr/bin/env php
<?php
/**
 * Regression gate for append-only Upload Bucket finalization.
 *
 * Historical versions paused the whole bucket queue before a new batch could be
 * finalized. Durable queueing no longer requires that global pause.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$coordinatorPath = $root . '/assets/upload-bucket-v2-coordinator.js';
$finalizerPath = $root . '/src/Infrastructure/Import/CatalogBucketBatchFinalizer.php';
$endpointPath = $root . '/api/v1/upload-bucket-batch.php';
$coordinator = $read('assets/upload-bucket-v2-coordinator.js');
$finalizer = $read('src/Infrastructure/Import/CatalogBucketBatchFinalizer.php');
$endpoint = $read('api/v1/upload-bucket-batch.php');
$chunkEndpoint = $read('api/v1/upload-bucket-chunk.php');
$processingState = $read('src/Infrastructure/Import/CatalogBucketProcessingStateService.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'browser_no_longer_pauses_existing_processing',
    !str_contains($coordinator, "processingState('begin_batch')")
        && !str_contains($coordinator, 'waitUntilPaused(')
        && !str_contains($coordinator, 'Requesting a safe Upload Bucket processing pause')
        && str_contains($coordinator, 'prepare_queue: false')
        && str_contains($coordinator, 'a second batch can be queued while an'),
    'Browser handoff must append durable jobs immediately instead of waiting for an existing long-running batch.'
);

$record(
    'server_finalizer_is_append_only',
    !str_contains($finalizer, 'CatalogBucketProcessingActive')
        && !str_contains($finalizer, 'CatalogOrphanedJobRecovery')
        && !str_contains($finalizer, 'migrateLegacyQueuedJobs()')
        && !str_contains($finalizer, 'activeQueues')
        && str_contains($finalizer, '$queue->enqueueCompletedUpload($uploadId, $userId)')
        && str_contains($finalizer, 'CatalogQueueWorkerStarter'),
    'Server finalization may enqueue the supplied uploads and wake workers only; it must not prepare/recover the whole queue.'
);

$record(
    'batch_endpoint_has_no_pause_conflict',
    !str_contains($endpoint, 'CatalogBucketProcessingActive')
        && !str_contains($endpoint, 'bucket_processing_not_paused')
        && str_contains($endpoint, '$prepareQueue = false;'),
    'The Upload Bucket API must not reject a new batch merely because another batch is still processing.'
);

$record(
    'legacy_begin_batch_is_read_only',
    str_contains($chunkEndpoint, "'pause_supported' => false")
        && str_contains($chunkEndpoint, '\'processing\' => $processingState->status(false)')
        && !str_contains($chunkEndpoint, '$processingState->status(true)')
        && !str_contains($processingState, 'requestStop('),
    'An old cached browser may still call begin_batch, but that compatibility action must never pause or stop live workers.'
);

$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ([
        $finalizerPath,
        $endpointPath,
        $root . '/api/v1/upload-bucket-chunk.php',
        $root . '/src/Infrastructure/Import/CatalogBucketProcessingStateService.php',
    ] as $path) {
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = basename($path) . ': could not run php -l';
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
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
