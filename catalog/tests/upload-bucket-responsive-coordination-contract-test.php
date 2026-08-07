<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies upload bucket responsive coordination behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function ub_responsive_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$chunk = file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$batch = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$coordinator = file_get_contents($root . '/assets/upload-bucket-v2-coordinator.js');
$page = file_get_contents($root . '/upload-bucket-v2.php');
$maintenance = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php');

foreach (compact('chunk', 'batch', 'coordinator', 'page', 'maintenance') as $name => $source) {
    ub_responsive_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

ub_responsive_expect(
    !str_contains($chunk, "if (\$action === 'begin_batch') {\n        \$pruned = (new CatalogChunkedUploadCleanup")
        && str_contains($chunk, "'cleanup_deferred' => true")
        && str_contains($chunk, "'running_job' => \$runningJob ? ["),
    'Interactive upload start still performs stale-directory cleanup or omits current-job identity.'
);

ub_responsive_expect(
    str_contains($coordinator, "initialProcessing = await processingState('begin_batch')")
        && str_contains($coordinator, "await uploadFile(file")
        && str_contains($coordinator, 'await waitUntilPaused(initialProcessing, lineId)')
        && str_contains($coordinator, 'upload_ids: [item.uploadId]')
        && str_contains($coordinator, 'start_worker: false')
        && str_contains($coordinator, "upload_ids: [],")
        && str_contains($coordinator, 'start_worker: true')
        && !str_contains($coordinator, 'FINALIZE_GROUP_SIZE')
        && !str_contains($coordinator, 'elapsedText()'),
    'Upload Bucket is not using actual file states and one-file finalisation.'
);

ub_responsive_expect(
    str_contains($batch, "\$prepareQueue = true")
        && str_contains($batch, "'prepare_queue'")
        && str_contains($batch, 'if ($startWorker && $pendingJobs > 0)')
        && str_contains($batch, 'A failed uploaded file is a file result')
        && str_contains($batch, '], 200);'),
    'Per-file validation failure can still abort the finalisation operation.'
);

ub_responsive_expect(
    str_contains($maintenance, 'CatalogChunkedUploadCleanup')
        && str_contains($maintenance, "'chunked_uploads' => \$chunkedUploads")
        && str_contains($page, 'Validate and queue that file before moving to the next file')
        && str_contains($page, 'upload-bucket-v2-coordinator.js')
        && !str_contains($page, 'upload-bucket-follow.js?v=')
        && !str_contains($page, 'assets/upload-bucket.js?v='),
    'The page still loads the batch-wide coordinator or stale cleanup was not moved to maintenance.'
);

fwrite(STDOUT, "Upload Bucket file-by-file coordination contract tests passed.\n");
