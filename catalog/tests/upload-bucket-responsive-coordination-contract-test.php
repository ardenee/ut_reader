<?php
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
$follow = file_get_contents($root . '/assets/upload-bucket-follow.js');
$page = file_get_contents($root . '/upload-bucket.php');
$maintenance = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php');

foreach (compact('chunk', 'batch', 'follow', 'page', 'maintenance') as $name => $source) {
    ub_responsive_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

ub_responsive_expect(
    !str_contains($chunk, "if (\$action === 'begin_batch') {\n        \$pruned = (new CatalogChunkedUploadCleanup")
        && str_contains($chunk, "'cleanup_deferred' => true")
        && str_contains($chunk, "'running_job' => \$runningJob ? ["),
    'Upload start still performs blocking cleanup or does not expose the running job.'
);

ub_responsive_expect(
    str_contains($batch, "\$prepareQueue = true")
        && str_contains($batch, "'prepare_queue'")
        && str_contains($batch, 'if ($prepareQueue && empty($workerStatus[\'active\']))')
        && str_contains($batch, '$legacyMigrated = $prepareQueue ?')
        && str_contains($batch, 'if ($startWorker && $pendingJobs > 0)'),
    'Batch finalisation does not separate one-time queue preparation from worker start.'
);

ub_responsive_expect(
    str_contains($follow, 'FINALIZE_GROUP_SIZE = 50')
        && str_contains($follow, 'Phase 3 of 3 — Finalising uploaded files')
        && str_contains($follow, 'queued so far')
        && str_contains($follow, 'prepare_queue: groupIndex === 0')
        && str_contains($follow, 'start_worker: isFinalGroup')
        && str_contains($follow, 'await sleep(25)')
        && str_contains($follow, 'Retrying finalisation group')
        && str_contains($follow, 'operationActive')
        && str_contains($follow, 'beforeunload'),
    'Browser finalisation is not split into short, visible, retryable groups.'
);

ub_responsive_expect(
    str_contains($maintenance, 'CatalogChunkedUploadCleanup')
        && str_contains($maintenance, "'chunked_uploads' => \$chunkedUploads")
        && str_contains($page, 'finalise uploaded sources in groups of 50')
        && str_contains($page, 'stale staging cleanup is handled separately by background maintenance'),
    'Stale chunk cleanup was not moved to maintenance or the page does not explain the new phases.'
);

fwrite(STDOUT, "Upload Bucket responsive coordination contract tests passed.\n");
