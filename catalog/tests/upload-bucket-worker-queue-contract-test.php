<?php
declare(strict_types=1);

function bucket_worker_queue_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/background-jobs.php');
$chunkEndpoint = file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$batchEndpoint = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$queue = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$workerStatus = file_get_contents($root . '/api/v1/job-worker-status.php');
$manager = file_get_contents($root . '/assets/background-jobs.js');

foreach (compact('page', 'chunkEndpoint', 'batchEndpoint', 'queue', 'workerStatus', 'manager') as $name => $source) {
    bucket_worker_queue_expect(is_string($source), $name . ' is missing.');
}

bucket_worker_queue_expect(str_contains($page, '<strong>Queue</strong>'), 'Background Jobs has no queue selector.');
bucket_worker_queue_expect(str_contains($page, 'queued_total'), 'Background Jobs does not discover active queues.');
bucket_worker_queue_expect(str_contains($page, 'one authoritative worker state'), 'Background Jobs does not explain authoritative worker reporting.');
bucket_worker_queue_expect(!str_contains($chunkEndpoint, 'CatalogDetachedWorker'), 'Per-file chunk completion still starts a worker.');
bucket_worker_queue_expect(str_contains($batchEndpoint, 'CatalogDetachedWorker'), 'Completed batches do not start the processing worker.');
bucket_worker_queue_expect(str_contains($batchEndpoint, 'foreach ($uploadIds as $uploadId)'), 'The worker can start before every completed upload has been queued.');
bucket_worker_queue_expect(str_contains($queue, "return \$base . ':bucket-processing'"), 'New Upload Bucket work does not use the dedicated batch-processing queue.');
bucket_worker_queue_expect(str_contains($workerStatus, "'orphaned'"), 'Worker status cannot distinguish stopped processes from orphaned running rows.');
bucket_worker_queue_expect(str_contains($manager, 'Recover and resume'), 'Background Jobs cannot recover and resume an orphaned queue.');
bucket_worker_queue_expect(str_contains($manager, 'Restart worker'), 'Background Jobs does not expose stale-code worker recovery.');

echo "Deferred Upload Bucket worker queue contract tests passed.\n";
