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
$context = file_get_contents($root . '/src/Application/Jobs/JobExecutionContext.php');
$client = file_get_contents($root . '/assets/upload-bucket.js');

bucket_timeout_expect(is_string($stream), 'Redirect stream decoder is missing.');
bucket_timeout_expect(is_string($processor), 'Redirect processor is missing.');
bucket_timeout_expect(is_string($context), 'Job execution context is missing.');
bucket_timeout_expect(is_string($client), 'Upload Bucket client is missing.');

bucket_timeout_expect(str_contains($stream, 'int $timeoutSeconds = 900'), 'UZ2 decoder has no default execution deadline.');
bucket_timeout_expect(str_contains($stream, 'assertWithinDeadline'), 'UZ2 decoder does not check its deadline while processing blocks.');
bucket_timeout_expect(str_contains($stream, 'Redirect decompression exceeded the '), 'Timeout failure is not reported clearly.');
bucket_timeout_expect(str_contains($stream, '($now - $lastProgressAt) >= 2.0'), 'Long-running redirect jobs do not emit time-based progress heartbeats.');
bucket_timeout_expect(str_contains($processor, "redirect_decompress_timeout_seconds'] ?? 900"), 'Redirect timeout is not configurable.');
bucket_timeout_expect(str_contains($context, 'JobType::PREPARE_BUCKET_REDIRECT'), 'Bucket redirect jobs do not receive the long-running lease.');
bucket_timeout_expect(str_contains($client, 'queuedForegroundWaitMs = 15000'), 'Queued redirect jobs can still block the foreground batch indefinitely.');
bucket_timeout_expect(str_contains($client, 'stalledForegroundWaitMs = 60000'), 'Stalled redirect jobs do not leave foreground polling.');
bucket_timeout_expect(str_contains($client, "return 'queued';"), 'The Upload Bucket client cannot continue after handing a redirect to Background Jobs.');
bucket_timeout_expect(str_contains($client, 'while this upload batch continues.'), 'The Upload Bucket client does not explain background continuation.');

echo "Upload Bucket redirect timeout contract tests passed.\n";
