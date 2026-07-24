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
$controls = file_get_contents($root . '/assets/background-jobs-running-controls.js');
$retryApi = file_get_contents($root . '/api/v1/job-retry.php');
$page = file_get_contents($root . '/background-jobs.php');

upload_bucket_throughput_expect(is_string($client), 'Upload Bucket client is missing.');
upload_bucket_throughput_expect(is_string($queue), 'Upload Bucket redirect queue is missing.');
upload_bucket_throughput_expect(is_string($controls), 'Background Jobs running controls are missing.');
upload_bucket_throughput_expect(is_string($retryApi), 'Cancelled job restart API is missing.');
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
    str_contains($client, 'queued for background decompression'),
    'Upload summary does not distinguish queued redirect work.'
);
upload_bucket_throughput_expect(
    !str_contains($queue, 'recoverStalledRedirectWorker'),
    'Redirect enqueue still auto-cancels jobs based on elapsed time.'
);
upload_bucket_throughput_expect(
    !str_contains($queue, 'CatalogDetachedWorkerStop'),
    'Redirect enqueue still terminates running workers automatically.'
);
upload_bucket_throughput_expect(
    str_contains($controls, "action: 'cancel'") && str_contains($controls, "mode: 'drain'"),
    'Manual Stop job does not stop the selected job and continue the queue.'
);
upload_bucket_throughput_expect(
    str_contains($controls, 'durationText') && str_contains($page, 'Running for'),
    'Background Jobs does not display the live job runtime.'
);
upload_bucket_throughput_expect(
    str_contains($page, 'Running jobs are never stopped automatically'),
    'Background Jobs does not document manual-only stopping.'
);
upload_bucket_throughput_expect(
    str_contains($controls, 'Restart selected (') && str_contains($controls, 'data-restart-job'),
    'Cancelled jobs do not expose individual and bulk restart controls.'
);
upload_bucket_throughput_expect(
    str_contains($controls, "api/v1/job-retry.php") && str_contains($controls, 'job_ids: jobIds'),
    'Cancelled job restart controls do not call the restart API.'
);
upload_bucket_throughput_expect(
    str_contains($retryApi, 'status IN ("cancelled","failed","dead_letter")'),
    'Cancelled jobs are not accepted by the restart API.'
);
upload_bucket_throughput_expect(
    str_contains($retryApi, 'CatalogDetachedWorker') && str_contains($retryApi, 'start($queueName, 10000)'),
    'Restarting cancelled jobs does not start the queue automatically.'
);

echo "Upload Bucket throughput contract tests passed.\n";
