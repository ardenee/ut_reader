<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies profiled upload large file behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function profiled_large_upload_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$paths = [
    'context' => 'src/Application/Jobs/JobExecutionContext.php',
    'worker' => 'src/Application/Jobs/JobWorker.php',
    'handler' => 'src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    'non_blocking' => 'src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php',
    'stream' => 'src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php',
    'factory' => 'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'queue' => 'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'detached' => 'src/Infrastructure/Jobs/CatalogDetachedWorker.php',
    'detached_script' => 'bin/catalog-worker-detached.php',
    'stopper' => 'src/Infrastructure/Jobs/CatalogDetachedWorkerStop.php',
    'job_action' => 'api/v1/job-action.php',
    'job_run' => 'api/v1/job-run.php',
    'worker_action' => 'api/v1/job-worker-action.php',
    'worker_status' => 'api/v1/job-worker-status.php',
    'upload_page' => 'profiled-upload.php',
    'upload_diagnostics' => 'assets/profiled-upload-diagnostics.js',
];

$content = [];
foreach ($paths as $name => $relativePath) {
    $value = file_get_contents($root . '/' . $relativePath);
    profiled_large_upload_expect(is_string($value), 'Required profiled upload file is missing: ' . $relativePath);
    $content[$name] = $value;
}

profiled_large_upload_expect(
    str_contains($content['context'], '[JobType::IMPORT_STAGED_PACKAGE, JobType::IMPORT_STAGED_PAK]'),
    'Ordinary staged package imports do not receive the extended lease.'
);
profiled_large_upload_expect(
    str_contains($content['context'], 'max($leaseSeconds, 3600)'),
    'Long staged imports are not protected by the one-hour lease.'
);
profiled_large_upload_expect(
    str_contains($content['context'], 'emitProgressEvent')
        && str_contains($content['context'], '$eventAppender'),
    'Worker progress checkpoints are not written to the visible event stream.'
);
profiled_large_upload_expect(
    str_contains($content['worker'], "'stage' => 'worker_start'")
        && str_contains($content['worker'], 'heartbeat_start')
        && str_contains($content['worker'], 'handler_start'),
    'A claimed job still appears as an unexplained zero-percent running row.'
);
profiled_large_upload_expect(
    str_contains($content['non_blocking'], 'CatalogRedirectArchiveStream::decompressUz2')
        && str_contains($content['non_blocking'], "'stage' => 'redirect_decompress'"),
    'The outer redirect preparation layer still performs hidden in-memory UZ2 decompression.'
);
profiled_large_upload_expect(
    str_contains($content['non_blocking'], 'prepareRedirectPayload($job, $context)'),
    'Redirect preparation cannot report progress or observe cancellation.'
);
profiled_large_upload_expect(
    str_contains($content['handler'], "'prepared_source_path'")
        && str_contains($content['handler'], "'stage' => 'scan'"),
    'The package scanner does not consume the already-streamed redirect payload directly.'
);
profiled_large_upload_expect(
    str_contains($content['handler'], "'stage' => 'copy'")
        && str_contains($content['handler'], 'Creating parser working copy'),
    'Large ordinary package copies still have no visible progress checkpoints.'
);
profiled_large_upload_expect(
    str_contains($content['stream'], 'CATALOG_EPIC_UZ2_BLOCK_BYTES')
        && str_contains($content['stream'], '$requirePackageTag = true'),
    'The streaming UZ2 decoder does not validate records or support non-package redirect payloads.'
);
profiled_large_upload_expect(
    str_contains($content['stream'], 'fread($stream') && str_contains($content['stream'], 'fwrite($stream'),
    'The UZ2 decoder is no longer stream based.'
);
profiled_large_upload_expect(
    str_contains($content['factory'], 'CatalogJobEventLog')
        && str_contains($content['factory'], "'max_redirect_output_bytes'"),
    'Workers are missing visible progress logs or a finite redirect expansion limit.'
);
profiled_large_upload_expect(
    str_contains($content['queue'], "'profiled-chunk:'"),
    'Repeated resumable package uploads are not deduplicated.'
);
profiled_large_upload_expect(
    str_contains($content['queue'], "'deduplicated' => \$deduplicated"),
    'Profiled upload queue does not report an existing active job.'
);
profiled_large_upload_expect(
    str_contains($content['queue'], '->delete($stagedPath)')
        && !str_contains($content['queue'], '->remove($stagedPath)'),
    'An unused deduplicated incoming upload is retained as an orphaned file.'
);
profiled_large_upload_expect(
    str_contains($content['detached'], 'codeVersion')
        && str_contains($content['detached'], "'stale_code'"),
    'Detached workers cannot be identified as stale after a code update.'
);
profiled_large_upload_expect(
    str_contains($content['detached_script'], "'code_version' => \$codeVersion"),
    'Detached worker state does not record the loaded code revision.'
);
profiled_large_upload_expect(
    str_contains($content['stopper'], 'restartStaleQueue')
        && str_contains($content['stopper'], 'requeueWorkerJobs'),
    'A stale detached worker cannot be replaced without discarding its active import.'
);
profiled_large_upload_expect(
    str_contains($content['stopper'], 'terminateExpectedWorker'),
    'Detached worker stop cannot terminate an unresponsive import.'
);
profiled_large_upload_expect(
    str_contains($content['stopper'], 'forceCancelJob'),
    'Forced worker stop does not clear the database lease.'
);
profiled_large_upload_expect(
    str_contains($content['job_action'], 'CatalogDetachedWorkerStop'),
    'Per-job Stop does not use detached worker termination.'
);
profiled_large_upload_expect(
    str_contains($content['job_run'], 'restartStaleQueue')
        && str_contains($content['job_run'], 'stale_worker_restart_failed'),
    'Starting queued work does not replace a worker that loaded old PHP code.'
);
profiled_large_upload_expect(
    str_contains($content['worker_action'], 'CatalogDetachedWorkerStop'),
    'Stop worker does not use detached worker termination.'
);
profiled_large_upload_expect(
    str_contains($content['worker_status'], 'status($queueName, true)'),
    'Worker diagnostics do not expose the detached log tail.'
);
profiled_large_upload_expect(
    str_contains($content['upload_page'], 'profiled-upload-diagnostics.js')
        && str_contains($content['upload_page'], 'data-worker-status-url'),
    'Profiled Upload does not load live worker diagnostics.'
);
profiled_large_upload_expect(
    str_contains($content['upload_diagnostics'], 'STALE CODE')
        && str_contains($content['upload_diagnostics'], 'Worker log:'),
    'Profiled Upload diagnostics do not explain stale or frozen workers.'
);

echo "Large profiled upload contract tests passed.\n";
