<?php
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
    'handler' => 'src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    'stream' => 'src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php',
    'queue' => 'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'stopper' => 'src/Infrastructure/Jobs/CatalogDetachedWorkerStop.php',
    'job_action' => 'api/v1/job-action.php',
    'worker_action' => 'api/v1/job-worker-action.php',
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
    str_contains($content['handler'], 'CatalogRedirectArchiveStream::decompressUz2'),
    'Large UZ2 imports do not use the streaming decoder.'
);
profiled_large_upload_expect(
    str_contains($content['handler'], "'stage' => 'decompress'"),
    'UZ2 streaming does not expose decompression progress.'
);
profiled_large_upload_expect(
    str_contains($content['stream'], 'CATALOG_EPIC_UZ2_BLOCK_BYTES'),
    'The streaming decoder does not validate Epic UZ2 block sizes.'
);
profiled_large_upload_expect(
    str_contains($content['stream'], 'fread($stream') && str_contains($content['stream'], 'fwrite($stream'),
    'The UZ2 decoder is no longer stream based.'
);
profiled_large_upload_expect(
    str_contains($content['queue'], "'profiled-chunk:'"),
    'Repeated resumable package uploads are not deduplicated.'
);
profiled_large_upload_expect(
    str_contains($content['queue'], "'deduplicated' => $deduplicated"),
    'Profiled upload queue does not report an existing active job.'
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
    str_contains($content['worker_action'], 'CatalogDetachedWorkerStop'),
    'Stop worker does not use detached worker termination.'
);

echo "Large profiled upload contract tests passed.\n";
