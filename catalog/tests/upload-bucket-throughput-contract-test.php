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
$chunkApi = file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$batchApi = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$batchQueue = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$handler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$processor = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketUploadProcessor.php');
$manager = file_get_contents($root . '/assets/background-jobs.js');
$authority = file_get_contents($root . '/assets/background-jobs-authority.js');
$bulkApi = file_get_contents($root . '/api/v1/job-bulk.php');
$statusApi = file_get_contents($root . '/api/v1/job-status.php');
$workerStatusApi = file_get_contents($root . '/api/v1/job-worker-status.php');
$page = file_get_contents($root . '/background-jobs.php');

foreach (compact('client', 'chunkApi', 'batchApi', 'batchQueue', 'handler', 'processor', 'manager', 'authority', 'bulkApi', 'statusApi', 'workerStatusApi', 'page') as $name => $source) {
    upload_bucket_throughput_expect(is_string($source), $name . ' is missing.');
}

upload_bucket_throughput_expect(
    str_contains($client, "action', 'begin_batch'")
        && str_contains($client, 'completedUploads.push')
        && str_contains($client, 'finalizeBatch(completedUploads.map'),
    'The browser does not transfer the complete batch before finalisation.'
);
upload_bucket_throughput_expect(
    str_contains($chunkApi, "if (\$action === 'complete')")
        && str_contains($chunkApi, 'Transfer completed and retained in durable staging')
        && !str_contains($chunkApi, 'CatalogDetachedWorker'),
    'Per-file completion still starts processing or a worker.'
);
upload_bucket_throughput_expect(
    str_contains($batchApi, 'foreach ($uploadIds as $uploadId)')
        && str_contains($batchApi, 'CatalogDetachedWorker')
        && str_contains($batchApi, 'start($queue->queueName(), 10000)'),
    'Batch finalisation does not create all jobs before starting one worker.'
);
upload_bucket_throughput_expect(
    str_contains($batchQueue, "return \$base . ':bucket-processing'")
        && str_contains($batchQueue, 'bucket-upload-source:'),
    'Deferred Upload Bucket jobs do not use the dedicated processing queue and source fingerprint.'
);
upload_bucket_throughput_expect(
    str_contains($handler, 'CatalogBucketUploadProcessor')
        && str_contains($handler, 'CatalogChunkedUploadCleanup')
        && str_contains($handler, 'throw $error;'),
    'Deferred processing does not retain sources for retries and remove them only after success.'
);
upload_bucket_throughput_expect(
    str_contains($processor, "'hash_identity'")
        && str_contains($processor, "'duplicate_check'")
        && str_contains($processor, "'read_names'")
        && str_contains($processor, "'database_imports'")
        && str_contains($processor, "'database_exports'")
        && str_contains($processor, "'database_commit'"),
    'The former opaque 75 percent stage has not been split into useful progress phases.'
);
upload_bucket_throughput_expect(
    !str_contains($handler, "'percent' => 75")
        && !str_contains($handler, 'Duplicate-checking and indexing'),
    'Deferred Upload Bucket processing still contains the opaque 75 percent stage.'
);
upload_bucket_throughput_expect(
    str_contains($client, 'xhr.timeout')
        && str_contains($client, 'xhr.ontimeout')
        && str_contains($client, 'requestReference'),
    'Upload requests can still wait forever or hide their server reference.'
);
upload_bucket_throughput_expect(
    str_contains($manager, 'Stop job') && str_contains($manager, 'launchAfterStop'),
    'Manual Stop job does not continue the queue.'
);
upload_bucket_throughput_expect(
    str_contains($authority, 'authoritative_status')
        && str_contains($workerStatusApi, "'orphaned'")
        && str_contains($workerStatusApi, 'queue_counts'),
    'Background Jobs still reports competing worker states.'
);
upload_bucket_throughput_expect(
    str_contains($manager, 'Select all ') && str_contains($manager, 'scope: scope')
        && str_contains($authority, 'Restart matching retryable jobs'),
    'Background Jobs cannot manage all matching or mixed eligible jobs.'
);
upload_bucket_throughput_expect(
    str_contains($bulkApi, "'matching'") && str_contains($bulkApi, 'COUNT(*) c'),
    'Bulk actions are still limited to the visible page.'
);
upload_bucket_throughput_expect(
    str_contains($statusApi, "'per_page'") && str_contains($statusApi, 'OFFSET ')
        && str_contains($statusApi, '1000'),
    'Background Jobs does not use real pagination up to 1,000 rows.'
);
upload_bucket_throughput_expect(
    substr_count($page, 'assets/background-jobs.js') === 1
        && substr_count($page, 'assets/background-jobs-authority.js') === 1
        && !str_contains($page, 'background-jobs-running-controls.js')
        && !str_contains($page, 'background-jobs-stale-worker.js'),
    'Background Jobs still loads obsolete competing table-control scripts.'
);

echo "Deferred Upload Bucket throughput contract tests passed.\n";
