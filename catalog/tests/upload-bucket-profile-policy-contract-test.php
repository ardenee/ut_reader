<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function bucket_policy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$paths = [
    'page' => 'upload-bucket.php',
    'endpoint' => 'api/v1/upload-bucket-chunk.php',
    'javascript' => 'assets/upload-bucket.js',
    'library' => 'lib/CatalogRedirectArchive.php',
    'processor' => 'src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php',
    'handler' => 'src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php',
    'import_handler' => 'src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php',
    'stream' => 'src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php',
    'queue' => 'src/Infrastructure/Import/CatalogBucketUploadQueue.php',
    'factory' => 'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'job_type' => 'src/Domain/Jobs/JobType.php',
];
$content = [];
foreach ($paths as $key => $path) {
    $content[$key] = file_get_contents($root . '/' . $path);
    bucket_policy_expect(is_string($content[$key]), 'Required bucket architecture file is missing: ' . $path);
}

bucket_policy_expect(
    !str_contains($content['page'], 'catalog_redirect_archive_decompress_to_temp(')
        && !str_contains($content['page'], 'catalog_epic_redirect_decompress_to_temp('),
    'Upload Bucket page still decompresses redirect archives inside web PHP.'
);
bucket_policy_expect(
    str_contains($content['page'], 'enqueueStagedRedirect(')
        && str_contains($content['page'], 'CatalogDetachedWorker')
        && str_contains($content['page'], '$queue->queueName()'),
    'Whole-file fallback does not stage and queue redirects on the dedicated CLI worker queue.'
);
bucket_policy_expect(
    str_contains($content['page'], 'Every file uses resumable chunks')
        && str_contains($content['page'], 'shared detached CLI process'),
    'Upload Bucket does not describe the single worker-side redirect process.'
);

bucket_policy_expect(str_contains($content['javascript'], 'async function chunkedUpload'), 'Browser client lacks chunked uploads.');
bucket_policy_expect(str_contains($content['javascript'], 'file.slice(start, end)'), 'Browser client does not send bounded chunks.');
bucket_policy_expect(str_contains($content['javascript'], 'received_chunks'), 'Browser client cannot resume chunks.');
bucket_policy_expect(!str_contains($content['javascript'], 'wholeFileUpload('), 'Browser client still routes redirect wrappers through whole-file POST.');
bucket_policy_expect(!str_contains($content['javascript'], 'isRedirectArchive('), 'Browser client still selects an upload transport by redirect extension.');
bucket_policy_expect(
    str_contains($content['javascript'], 'async function waitForJob')
        && str_contains($content['javascript'], "'api/v1/job-status.php'")
        && str_contains($content['javascript'], 'return await waitForJob(jobId, name);'),
    'Upload Bucket does not report the final detached-worker redirect result.'
);

bucket_policy_expect(
    !str_contains($content['endpoint'], 'catalog_epic_redirect_decompress_to_temp(')
        && !str_contains($content['endpoint'], 'catalog_redirect_archive_decompress_to_temp('),
    'Chunk endpoint still decompresses redirect archives inside web PHP.'
);
bucket_policy_expect(
    str_contains($content['endpoint'], 'CatalogBucketUploadQueue')
        && str_contains($content['endpoint'], 'enqueueRedirect(')
        && str_contains($content['endpoint'], '$bucketQueue->queueName()')
        && str_contains($content['endpoint'], 'CatalogDetachedWorker'),
    'Chunk endpoint does not queue redirect finalization on the dedicated detached worker queue.'
);
bucket_policy_expect(str_contains($content['endpoint'], "'status' => 'queued'"), 'Redirect upload does not report its queued state.');

bucket_policy_expect(
    str_contains($content['processor'], 'final class CatalogRedirectArchiveProcessor')
        && str_contains($content['processor'], 'CatalogRedirectArchiveStream::decompressUz2(')
        && str_contains($content['processor'], 'catalog_redirect_archive_decompress_payload_to_temp('),
    'Shared redirect processor does not own all format dispatch.'
);
bucket_policy_expect(
    str_contains($content['library'], 'new \\UnrealDb\\Catalog\\Infrastructure\\Redirect\\CatalogRedirectArchiveProcessor(')
        && !str_contains($content['library'], '$data = @file_get_contents($sourcePath);'),
    'Legacy redirect helper still owns a separate decompression implementation.'
);
bucket_policy_expect(
    str_contains($content['handler'], 'new CatalogRedirectArchiveProcessor(')
        && str_contains($content['import_handler'], 'new CatalogRedirectArchiveProcessor('),
    'Bucket and profiled import jobs do not use the same redirect processor.'
);
bucket_policy_expect(
    !str_contains($content['handler'], '->cancel($userId, $uploadId)')
        && !str_contains($content['handler'], '->delete($stagedPath)'),
    'Bucket redirect handler removes its durable source before job completion is persisted.'
);
bucket_policy_expect(
    str_contains($content['stream'], 'catalog_redirect_archive_inflate_epic_zlib(')
        && str_contains($content['stream'], 'CATALOG_EPIC_UZ2_BLOCK_BYTES')
        && !str_contains($content['stream'], 'availableDecoders')
        && !str_contains($content['stream'], 'decodePayload('),
    'Known-good July 23 UZ2 stream decoder was not restored exactly.'
);

bucket_policy_expect(
    str_contains($content['job_type'], 'PREPARE_BUCKET_REDIRECT')
        && str_contains($content['queue'], 'JobType::PREPARE_BUCKET_REDIRECT')
        && str_contains($content['factory'], 'new CatalogBucketRedirectJobHandler('),
    'Bucket redirect job type is not fully registered with the worker.'
);
bucket_policy_expect(
    str_contains($content['queue'], "return \$base . ':bucket-redirects';")
        && str_contains($content['queue'], "'source_kind' => 'chunk-upload'")
        && str_contains($content['queue'], "'source_kind' => 'incoming-file'"),
    'Chunked and fallback bucket uploads do not share the dedicated worker queue and job.'
);

bucket_policy_expect(str_contains($content['page'], 'function upload_bucket_stats'), 'Upload bucket physical-folder statistics are missing.');
bucket_policy_expect(!str_contains($content['page'], 'uvf_list($db, $config, 0)'), 'Upload bucket hashes every queued file while rendering totals.');
bucket_policy_expect(str_contains($content['endpoint'], "catalog_api_require_csrf('upload_bucket_chunk')"), 'Chunk endpoint lacks CSRF protection.');
bucket_policy_expect(str_contains($content['endpoint'], "['max_upload_bytes'] = PHP_INT_MAX"), 'Bucket chunk endpoint applies the ordinary upload limit.');

echo "Upload bucket shared CLI redirect contract tests passed.\n";
