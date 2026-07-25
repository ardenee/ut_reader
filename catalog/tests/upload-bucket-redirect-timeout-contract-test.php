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
$redirectProcessor = file_get_contents($root . '/src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php');
$legacyQueue = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketUploadQueue.php');
$batchHandler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$legacyHandler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php');
$client = file_get_contents($root . '/assets/upload-bucket.js');
$manager = file_get_contents($root . '/assets/background-jobs.js');

foreach (compact('stream', 'redirectProcessor', 'legacyQueue', 'batchHandler', 'legacyHandler', 'client', 'manager') as $name => $source) {
    bucket_timeout_expect(is_string($source), $name . ' is missing.');
}

bucket_timeout_expect(!str_contains($stream, 'assertWithinDeadline'), 'UZ2 decoding still has an elapsed-time deadline.');
bucket_timeout_expect(!str_contains($stream, 'timeoutSeconds'), 'UZ2 decoding still accepts an automatic timeout.');
bucket_timeout_expect(!str_contains($redirectProcessor, 'redirect_decompress_timeout_seconds'), 'Redirect processor still reads a timeout setting.');
bucket_timeout_expect(!str_contains($legacyQueue, 'recoverStalledRedirectWorker'), 'Legacy redirect enqueue still auto-stops quiet jobs.');
bucket_timeout_expect(!str_contains($legacyQueue, 'bucket_redirect_stall_seconds'), 'Legacy redirect queue still has a time-based stall threshold.');
bucket_timeout_expect(str_contains($stream, '($now - $lastProgressAt) >= 2.0'), 'Long UZ2 decompression does not publish periodic progress.');
bucket_timeout_expect(!str_contains($client, 'waitForJob'), 'Upload Bucket still waits for processing while transferring files.');
bucket_timeout_expect(str_contains($client, 'xhr.timeout') && str_contains($client, 'xhr.ontimeout'), 'Browser requests still have no network timeout.');
bucket_timeout_expect(!str_contains($batchHandler, 'recoverStalled') && !str_contains($legacyHandler, 'recoverStalled'), 'A job handler still auto-stops work based on elapsed time.');
bucket_timeout_expect(str_contains($manager, 'Stop job'), 'Background Jobs has no explicit per-job stop action.');
bucket_timeout_expect(str_contains($manager, 'launchAfterStop'), 'Stopping one job does not continue the queue.');
bucket_timeout_expect(str_contains($manager, 'formatDuration'), 'Running duration is not shown.');

echo "Deferred Upload Bucket no-timeout contract tests passed.\n";
