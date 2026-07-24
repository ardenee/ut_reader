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
$manager = file_get_contents($root . '/assets/background-jobs.js');
$bulkApi = file_get_contents($root . '/api/v1/job-bulk.php');
$statusApi = file_get_contents($root . '/api/v1/job-status.php');
$page = file_get_contents($root . '/background-jobs.php');

upload_bucket_throughput_expect(is_string($client), 'Upload Bucket client is missing.');
upload_bucket_throughput_expect(is_string($queue), 'Upload Bucket redirect queue is missing.');
upload_bucket_throughput_expect(is_string($manager), 'Background Jobs manager is missing.');
upload_bucket_throughput_expect(is_string($bulkApi), 'Background Jobs bulk API is missing.');
upload_bucket_throughput_expect(is_string($statusApi), 'Background Jobs status API is missing.');
upload_bucket_throughput_expect(is_string($page), 'Background Jobs page is missing.');

upload_bucket_throughput_expect(
    !str_contains($client, 'return await waitForJob(jobId, name)'),
    'Upload Bucket still waits for every redirect decompression job before uploading the next file.'
);
upload_bucket_throughput_expect(
    str_contains($client, "if (jobId > 0) {") && str_contains($client, "return 'queued';"),
    'Redirect uploads are not handed off immediately after enqueue.'
);
upload_bucket_throughput_expect(
    !str_contains($queue, 'recoverStalledRedirectWorker') && !str_contains($queue, 'CatalogDetachedWorkerStop'),
    'Redirect enqueue still stops running work automatically.'
);
upload_bucket_throughput_expect(
    str_contains($manager, 'Stop job') && str_contains($manager, 'launchAfterStop'),
    'Manual Stop job does not continue the queue.'
);
upload_bucket_throughput_expect(
    str_contains($manager, 'formatDuration') && str_contains($page, 'Running for'),
    'Background Jobs does not display live execution time.'
);
upload_bucket_throughput_expect(
    str_contains($manager, 'Select all ') && str_contains($manager, "scope: scope"),
    'Background Jobs cannot select and act on all matching jobs.'
);
upload_bucket_throughput_expect(
    str_contains($bulkApi, "'matching'") && str_contains($bulkApi, 'COUNT(*) c'),
    'Bulk actions are still limited to the visible page.'
);
upload_bucket_throughput_expect(
    str_contains($statusApi, "'per_page'") && str_contains($statusApi, 'OFFSET '),
    'Background Jobs does not use real pagination.'
);
upload_bucket_throughput_expect(
    str_contains($statusApi, '1000') && !str_contains($statusApi, 'min((int)($_GET[\'limit\'] ?? 50), 200)'),
    'Background Jobs is still hard-limited to 200 rows.'
);
upload_bucket_throughput_expect(
    substr_count($page, 'assets/background-jobs.js') === 1
        && !str_contains($page, 'background-jobs-running-controls.js')
        && !str_contains($page, 'background-jobs-stale-worker.js'),
    'Background Jobs still loads competing table-control scripts.'
);

echo "Upload Bucket throughput contract tests passed.\n";
