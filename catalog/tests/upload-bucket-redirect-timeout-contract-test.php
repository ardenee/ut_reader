<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies upload bucket redirect timeout behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
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
$client = file_get_contents($root . '/assets/upload-bucket-coordinator.js');
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
bucket_timeout_expect(!str_contains($client, 'waitForJob'), 'Upload Bucket still waits for package processing while transferring files.');
bucket_timeout_expect(!str_contains($client, 'elapsedText()') && !str_contains($client, 'setInterval('), 'Upload Bucket UI still fabricates progress from elapsed timers.');
bucket_timeout_expect(str_contains($client, 'xhr.timeout') && str_contains($client, 'xhr.ontimeout'), 'Individual network requests still have no failure boundary.');
bucket_timeout_expect(!str_contains($batchHandler, 'recoverStalled') && !str_contains($legacyHandler, 'recoverStalled'), 'A job handler still auto-stops work based on elapsed time.');
bucket_timeout_expect(str_contains($manager, 'Stop job'), 'Background Jobs has no explicit per-job stop action.');
bucket_timeout_expect(str_contains($manager, 'launchAfterStop'), 'Stopping one job does not continue the queue.');
bucket_timeout_expect(str_contains($manager, 'formatDuration'), 'Running duration is not shown.');

echo "File-state Upload Bucket and redirect no-deadline contract tests passed.\n";
