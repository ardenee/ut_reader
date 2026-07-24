<?php
declare(strict_types=1);

function upload_bucket_throughput_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$client = file_get_contents($root . '/assets/upload-bucket.js');
$queue = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketUploadQueue.php');

upload_bucket_throughput_expect(is_string($client), 'Upload Bucket client is missing.');
upload_bucket_throughput_expect(is_string($queue), 'Upload Bucket redirect queue is missing.');

upload_bucket_throughput_expect(
    !str_contains($client, 'return await waitForJob(jobId, name)'),
    'Upload Bucket still waits for every redirect decompression job before uploading the next file.'
);
upload_bucket_throughput_expect(
    str_contains($client, "if (jobId > 0) {") && str_contains($client, "return 'queued';"),
    'Redirect uploads are not handed off immediately after enqueue.'
);
upload_bucket_throughput_expect(
    str_contains($client, 'queued for background decompression'),
    'Upload summary does not distinguish queued redirect work.'
);
upload_bucket_throughput_expect(
    str_contains($queue, "bucket_redirect_stall_seconds'] ?? 90"),
    'Redirect queue has no stalled-worker threshold.'
);
upload_bucket_throughput_expect(
    str_contains($queue, 'recoverStalledRedirectWorker'),
    'Redirect enqueue does not check for a worker that stopped heartbeating.'
);
upload_bucket_throughput_expect(
    str_contains($queue, 'CatalogDetachedWorkerStop'),
    'Stalled redirect workers cannot be terminated automatically.'
);

echo "Upload Bucket throughput contract tests passed.\n";
