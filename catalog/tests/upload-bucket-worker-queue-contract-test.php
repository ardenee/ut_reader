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
$endpoint = file_get_contents($root . '/api/v1/upload-bucket-chunk.php');

bucket_worker_queue_expect(is_string($page), 'Background Jobs page is missing.');
bucket_worker_queue_expect(is_string($endpoint), 'Upload Bucket chunk endpoint is missing.');

bucket_worker_queue_expect(str_contains($page, 'Job queue'), 'Background Jobs has no queue selector.');
bucket_worker_queue_expect(str_contains($page, 'queued_total'), 'Background Jobs does not discover active queues.');
bucket_worker_queue_expect(str_contains($page, 'Upload Bucket redirect decompression uses its own queue.'), 'Background Jobs does not explain the redirect queue.');
bucket_worker_queue_expect(str_contains($endpoint, 'CatalogDetachedWorkerStop'), 'Upload Bucket cannot restart a stale worker.');
bucket_worker_queue_expect(str_contains($endpoint, 'restartStaleQueue'), 'Upload Bucket does not invoke stale-worker recovery.');
bucket_worker_queue_expect(str_contains($endpoint, 'stale Upload Bucket worker was restarted automatically'), 'Upload Bucket does not report automatic worker recovery.');

echo "Upload Bucket worker queue contract tests passed.\n";
