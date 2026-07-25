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
    'batch_endpoint' => 'api/v1/upload-bucket-batch.php',
    'javascript' => 'assets/upload-bucket.js',
    'hash_javascript' => 'assets/upload-file-hash.js',
    'library' => 'lib/CatalogRedirectArchive.php',
    'redirect_payload' => 'lib/CatalogRedirectArchivePayload.php',
    'redirect_processor' => 'src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php',
    'batch_processor' => 'src/Infrastructure/Import/CatalogBucketUploadProcessor.php',
    'identity_processor' => 'src/Infrastructure/Import/CatalogBucketIdentityProcessor.php',
    'identity_store' => 'src/Infrastructure/Import/CatalogBucketUploadIdentityStore.php',
    'duplicate_detector' => 'src/Infrastructure/Import/CatalogUploadDuplicateDetector.php',
    'handler' => 'src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php',
    'legacy_handler' => 'src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php',
    'import_handler' => 'src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php',
    'stream' => 'src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php',
    'batch_queue' => 'src/Infrastructure/Import/CatalogBucketBatchQueue.php',
    'factory' => 'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'job_type' => 'src/Domain/Jobs/JobType.php',
];
$content = [];
foreach ($paths as $key => $path) {
    $content[$key] = file_get_contents($root . '/' . $path);
    bucket_policy_expect(is_string($content[$key]), 'Required bucket architecture file is missing: ' . $path);
}

bucket_policy_expect(
    !str_contains($content['page'], 'CatalogBucketUploadQueue')
        && !str_contains($content['page'], 'LegacyUnverifiedFileStager')
        && !str_contains($content['page'], 'CatalogDetachedWorker'),
    'Upload Bucket page still performs package processing inside web PHP.'
);
bucket_policy_expect(
    str_contains($content['page'], 'calculates MD5 and SHA-1 in the browser before transfer')
        && str_contains($content['page'], 'cooperative pause')
        && str_contains($content['page'], 'catalog:bucket-processing')
        && str_contains($content['page'], 'whole-file fallback processing has been disabled')
        && str_contains($content['page'], 'Official base-game metadata without a stored source file remains uploadable'),
    'Upload Bucket page does not explain the paused transfer-first physical duplicate policy.'
);

bucket_policy_expect(str_contains($content['javascript'], 'async function chunkedUpload'), 'Browser client lacks chunked uploads.');
bucket_policy_expect(str_contains($content['javascript'], 'file.slice(start, end)'), 'Browser client does not send bounded chunks.');
bucket_policy_expect(str_contains($content['javascript'], 'received_chunks'), 'Browser client cannot resume chunks.');
bucket_policy_expect(!str_contains($content['javascript'], 'wholeFileUpload('), 'Browser client still routes files through whole-file POST.');
bucket_policy_expect(
    str_contains($content['javascript'], 'await beginBatch()')
        && str_contains($content['javascript'], "processingState('batch_status')")
        && str_contains($content['javascript'], 'completedUploads.push')
        && str_contains($content['javascript'], 'await finalizeBatch('),
    'Browser client does not pause existing processing and finish the complete transfer phase before finalisation.'
);
bucket_policy_expect(
    str_contains($content['hash_javascript'], 'class Md5')
        && str_contains($content['hash_javascript'], 'class Sha1')
        && str_contains($content['javascript'], "action', 'preflight'")
        && str_contains($content['javascript'], 'if (checked.duplicate)'),
    'Browser client does not hash and preflight ordinary files before upload.'
);
bucket_policy_expect(
    str_contains($content['javascript'], 'xhr.timeout')
        && str_contains($content['javascript'], 'xhr.ontimeout')
        && str_contains($content['javascript'], 'requestReference'),
    'Browser upload reporting can still wait forever or omit request references.'
);

bucket_policy_expect(
    !str_contains($content['endpoint'], 'CatalogBucketUploadQueue')
        && !str_contains($content['endpoint'], 'LegacyUnverifiedFileStager')
        && !str_contains($content['endpoint'], 'CatalogBucketUploadProcessor')
        && !str_contains($content['endpoint'], '->start('),
    'Per-file chunk completion still starts or performs processing before the batch is complete.'
);
bucket_policy_expect(
    str_contains($content['endpoint'], "if (\$action === 'begin_batch')")
        && str_contains($content['endpoint'], "if (\$action === 'batch_status')")
        && str_contains($content['endpoint'], 'requestStop($queueName)')
        && str_contains($content['endpoint'], "if (\$action === 'preflight')")
        && str_contains($content['endpoint'], "if (\$action === 'complete')")
        && str_contains($content['endpoint'], 'retained in durable staging'),
    'Chunk endpoint does not pause processing, preflight identities and provide transfer-only completion.'
);
bucket_policy_expect(
    str_contains($content['endpoint'], 'if ($redirect)')
        && str_contains($content['endpoint'], 'compressed wrapper hashes are not package hashes')
        && str_contains($content['endpoint'], "'duplicate' => false"),
    'Compressed redirect wrappers are incorrectly compared to package MD5/SHA-1 records before decompression.'
);

bucket_policy_expect(
    str_contains($content['batch_endpoint'], 'CatalogBucketBatchQueue')
        && str_contains($content['batch_endpoint'], 'foreach ($uploadIds as $uploadId)')
        && str_contains($content['batch_endpoint'], 'migrateLegacyQueuedJobs()')
        && str_contains($content['batch_endpoint'], 'CatalogDetachedWorker')
        && str_contains($content['batch_endpoint'], 'start($queue->queueName(), 10000)'),
    'Batch endpoint does not consolidate and queue all completed sources before starting one worker.'
);
bucket_policy_expect(
    str_contains($content['batch_queue'], "':bucket-processing'")
        && str_contains($content['batch_queue'], "':bucket-redirects'")
        && str_contains($content['batch_queue'], 'migrateLegacyQueuedJobs')
        && str_contains($content['batch_queue'], 'bucket-upload-source:')
        && str_contains($content['batch_queue'], 'source_fingerprint')
        && str_contains($content['batch_queue'], 'if (!$redirect)')
        && str_contains($content['batch_queue'], 'package_md5'),
    'Completed uploads do not separate ordinary package identity from compressed redirect source identity.'
);

bucket_policy_expect(
    str_contains($content['redirect_processor'], 'CatalogRedirectArchiveStream::decompressUz2(')
        && str_contains($content['redirect_processor'], 'catalog_redirect_archive_decompress_payload_to_temp('),
    'Shared redirect processor does not own all format dispatch.'
);
bucket_policy_expect(
    str_contains($content['library'], 'new \\UnrealDb\\Catalog\\Infrastructure\\Redirect\\CatalogRedirectArchiveProcessor(')
        && !str_contains($content['library'], '$data = @file_get_contents($sourcePath);'),
    'Legacy redirect helper still owns a separate decompression implementation.'
);
bucket_policy_expect(
    str_contains($content['handler'], 'new CatalogRedirectArchiveProcessor(')
        && str_contains($content['legacy_handler'], 'new CatalogRedirectArchiveProcessor(')
        && str_contains($content['import_handler'], 'new CatalogRedirectArchiveProcessor('),
    'Bucket and profiled import jobs do not use the same redirect processor.'
);

bucket_policy_expect(
    str_contains($content['stream'], "hash_init('md5')")
        && str_contains($content['stream'], 'hash_update($md5Context, $block)')
        && str_contains($content['redirect_payload'], '$md5 = md5($output)')
        && str_contains($content['handler'], "(string)(\$decoded['md5']")
        && str_contains($content['legacy_handler'], "(string)(\$decoded['md5']"),
    'Redirect package identity is not calculated from decompressed bytes.'
);
bucket_policy_expect(
    str_contains($content['identity_processor'], 'Using MD5 and SHA-1 calculated while the package bytes were produced')
        && str_contains($content['identity_processor'], 'CatalogUploadDuplicateDetector')
        && !str_contains($content['identity_processor'], 'hash_file('),
    'Identity-aware processing still performs a second standalone hash read.'
);
bucket_policy_expect(
    str_contains($content['duplicate_detector'], 'physicalPath')
        && str_contains($content['duplicate_detector'], 'missing_base_game_matches')
        && str_contains($content['duplicate_detector'], 'ue_base_game_files')
        && str_contains($content['duplicate_detector'], 'LOWER(f.sha1)=?'),
    'Duplicate detection does not require a physical size/MD5/SHA-1 match or protect base-game metadata-only rows.'
);
bucket_policy_expect(
    str_contains($content['identity_store'], 'identity.json')
        && str_contains($content['identity_store'], "preg_match('/^[a-f0-9]{32}$/', \$md5)"),
    'Browser-calculated upload identities are not retained with resumable staging.'
);

bucket_policy_expect(
    str_contains($content['batch_processor'], "'hash_identity'")
        && str_contains($content['batch_processor'], "'duplicate_check'")
        && str_contains($content['batch_processor'], "'read_header'")
        && str_contains($content['batch_processor'], "'read_names'")
        && str_contains($content['batch_processor'], "'read_imports'")
        && str_contains($content['batch_processor'], "'read_exports'")
        && str_contains($content['batch_processor'], "'database_commit'"),
    'Package processing still collapses hashing, parsing and indexing into one opaque stage.'
);
bucket_policy_expect(
    str_contains($content['batch_processor'], 'INSERT_BATCH_SIZE')
        && str_contains($content['batch_processor'], 'array_chunk($names, self::INSERT_BATCH_SIZE')
        && str_contains($content['batch_processor'], 'array_chunk($rows, self::INSERT_BATCH_SIZE)')
        && str_contains($content['batch_processor'], 'Fall back to a bounded stream copy'),
    'Upload Bucket inventory still uses per-row SQL or assumes same-volume rename storage.'
);
bucket_policy_expect(
    !str_contains($content['handler'], "'percent' => 75")
        && !str_contains($content['legacy_handler'], "'percent' => 75")
        && !str_contains($content['handler'], 'Duplicate-checking and indexing')
        && !str_contains($content['legacy_handler'], 'Duplicate-checking and indexing'),
    'A bucket job handler still contains the broken 75 percent stage.'
);
bucket_policy_expect(
    str_contains($content['handler'], 'throw $error;')
        && str_contains($content['legacy_handler'], 'throw $error;'),
    'Processing errors are returned as completed results instead of using queue retries and dead-letter handling.'
);
bucket_policy_expect(
    str_contains($content['handler'], 'CatalogChunkedUploadCleanup')
        && str_contains($content['handler'], 'delete($uploadId)')
        && str_contains($content['legacy_handler'], 'CatalogChunkedUploadCleanup'),
    'Successful jobs do not remove their now-unneeded durable browser sources.'
);

bucket_policy_expect(
    str_contains($content['stream'], 'catalog_redirect_archive_inflate_epic_zlib(')
        && str_contains($content['stream'], 'CATALOG_EPIC_UZ2_BLOCK_BYTES')
        && !str_contains($content['stream'], 'availableDecoders')
        && !str_contains($content['stream'], 'decodePayload('),
    'Known-good UZ2 stream decoder was replaced.'
);

bucket_policy_expect(
    str_contains($content['job_type'], 'PROCESS_BUCKET_UPLOAD')
        && str_contains($content['factory'], 'new CatalogBucketUploadJobHandler(')
        && str_contains($content['factory'], 'new CatalogBucketRedirectJobHandler('),
    'New and legacy bucket job types are not both registered with the worker.'
);

bucket_policy_expect(str_contains($content['page'], 'function upload_bucket_stats'), 'Upload bucket physical-folder statistics are missing.');
bucket_policy_expect(!str_contains($content['page'], 'uvf_list($db, $config, 0)'), 'Upload bucket hashes every queued file while rendering totals.');
bucket_policy_expect(str_contains($content['endpoint'], "catalog_api_require_csrf('upload_bucket_chunk')"), 'Chunk endpoint lacks CSRF protection.');
bucket_policy_expect(str_contains($content['batch_endpoint'], "catalog_api_require_csrf('upload_bucket_chunk')"), 'Batch endpoint lacks CSRF protection.');
bucket_policy_expect(str_contains($content['endpoint'], "['max_upload_bytes'] = PHP_INT_MAX"), 'Bucket chunk endpoint applies the ordinary upload limit.');

echo "Paused transfer-first Upload Bucket architecture contract tests passed.\n";
