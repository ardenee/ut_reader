<?php
declare(strict_types=1);

function bucket_timeout_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$stream = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php');
$processor = file_get_contents($root . '/src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php');
$queue = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketUploadQueue.php');
$client = file_get_contents($root . '/assets/upload-bucket.js');
$controls = file_get_contents($root . '/assets/background-jobs-running-controls.js');

bucket_timeout_expect(is_string($stream), 'Redirect stream decoder is missing.');
bucket_timeout_expect(is_string($processor), 'Redirect processor is missing.');
bucket_timeout_expect(is_string($queue), 'Redirect queue is missing.');
bucket_timeout_expect(is_string($client), 'Upload Bucket client is missing.');
bucket_timeout_expect(is_string($controls), 'Background Jobs running controls are missing.');

bucket_timeout_expect(!str_contains($stream, 'assertWithinDeadline'), 'UZ2 decoding still has an elapsed-time deadline.');
bucket_timeout_expect(!str_contains($stream, 'timeoutSeconds'), 'UZ2 decoding still accepts an automatic timeout.');
bucket_timeout_expect(!str_contains($processor, 'redirect_decompress_timeout_seconds'), 'Redirect processor still reads a timeout setting.');
bucket_timeout_expect(!str_contains($queue, 'recoverStalledRedirectWorker'), 'Redirect enqueue still auto-stops quiet jobs.');
bucket_timeout_expect(!str_contains($queue, 'bucket_redirect_stall_seconds'), 'Redirect queue still has a time-based stall threshold.');
bucket_timeout_expect(str_contains($stream, '($now - $lastProgressAt) >= 2.0'), 'Long decompression does not publish periodic progress.');
bucket_timeout_expect(!str_contains($client, 'return await waitForJob(jobId, name)'), 'Upload Bucket still waits for redirect processing.');
bucket_timeout_expect(str_contains($controls, 'Stop job'), 'Background Jobs has no explicit per-job stop action.');
bucket_timeout_expect(str_contains($controls, "mode: 'drain'"), 'Stopping one job does not continue the queue.');
bucket_timeout_expect(str_contains($controls, 'Running for') || str_contains($controls, 'durationText'), 'Running duration is not shown.');

echo "Upload Bucket no-timeout contract tests passed.\n";
