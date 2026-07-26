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
$hashClient = file_get_contents($root . '/assets/upload-file-hash.js');
$chunkApi = file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$batchApi = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$batchQueue = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$handler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$redirectHandler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php');
$processor = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketUploadProcessor.php');
$identityProcessor = file_get_contents($root . '/src/Infrastructure/Import/CatalogBucketIdentityProcessor.php');
$duplicateDetector = file_get_contents($root . '/src/Infrastructure/Import/CatalogUploadDuplicateDetector.php');
$redirectStream = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php');
$redirectPayload = file_get_contents($root . '/lib/CatalogRedirectArchivePayload.php');
$manager = file_get_contents($root . '/assets/background-jobs.js');
$bulkApi = file_get_contents($root . '/api/v1/job-bulk.php');
$statusApi = file_get_contents($root . '/api/v1/job-status.php');
$workerStatusApi = file_get_contents($root . '/api/v1/job-worker-status.php');
$page = file_get_contents($root . '/background-jobs.php');

foreach (compact('client', 'hashClient', 'chunkApi', 'batchApi', 'batchQueue', 'handler', 'redirectHandler', 'processor', 'identityProcessor', 'duplicateDetector', 'redirectStream', 'redirectPayload', 'manager', 'bulkApi', 'statusApi', 'workerStatusApi', 'page') as $name => $source) {
    upload_bucket_throughput_expect(is_string($source), $name . ' is missing.');
}

upload_bucket_throughput_expect(
    str_contains($client, "action', 'begin_batch'")
        && str_contains($client, "processingState('batch_status')")
        && str_contains($client, 'completedUploads.push')
        && str_contains($client, 'finalizeBatch(completedUploads.map'),
    'The browser does not pause processing and transfer the complete batch before finalisation.'
);
upload_bucket_throughput_expect(
    str_contains($hashClient, 'class Md5')
        && str_contains($hashClient, 'class Sha1')
        && str_contains($hashClient, 'hashFile'),
    'The browser does not calculate MD5 and SHA-1 before an ordinary file upload.'
);
upload_bucket_throughput_expect(
    str_contains($client, "action', 'preflight'")
        && str_contains($client, 'if (checked.duplicate)')
        && str_contains($client, "initData.append('md5'")
        && str_contains($client, "initData.append('sha1'")
        && str_contains($client, 'isRedirectWrapper(file)'),
    'The browser does not separate ordinary physical preflight from redirect wrapper transfer.'
);
upload_bucket_throughput_expect(
    str_contains($chunkApi, "if (\$action === 'complete')")
        && str_contains($chunkApi, 'retained in durable staging')
        && str_contains($chunkApi, 'requestStop($queueName)')
        && !str_contains($chunkApi, '->start(')
        && !str_contains($chunkApi, 'CatalogBucketUploadProcessor')
        && !str_contains($chunkApi, 'CatalogBucketUploadQueue'),
    'Per-file completion still starts or performs package processing.'
);
upload_bucket_throughput_expect(
    str_contains($chunkApi, "if (\$action === 'preflight')")
        && str_contains($chunkApi, 'if ($redirect)')
        && str_contains($chunkApi, "'identity' => null")
        && str_contains($chunkApi, 'real package identity will be calculated from the decompressed output'),
    'Redirect wrappers are incorrectly browser-hashed or compared to package identities.'
);
upload_bucket_throughput_expect(
    str_contains($batchApi, 'foreach ($uploadIds as $uploadId)')
        && str_contains($batchApi, 'migrateLegacyQueuedJobs()')
        && str_contains($batchApi, 'CatalogDetachedWorker')
        && str_contains($batchApi, 'start($queue->queueName(), 10000)'),
    'Batch finalisation does not consolidate and create all jobs before starting one worker.'
);
upload_bucket_throughput_expect(
    str_contains($batchQueue, "':bucket-processing'")
        && str_contains($batchQueue, "':bucket-redirects'")
        && str_contains($batchQueue, 'migrateLegacyQueuedJobs')
        && str_contains($batchQueue, 'bucket-upload-source:')
        && str_contains($batchQueue, 'bucket-redirect-upload:')
        && str_contains($batchQueue, 'if (!$redirect)')
        && str_contains($batchQueue, 'CatalogUploadDuplicateDetector')
        && str_contains($batchQueue, 'every wrapper must')
        && str_contains($batchQueue, 'reach decompression'),
    'Deferred Upload Bucket work does not force redirect wrappers through decompression before package dedupe.'
);
upload_bucket_throughput_expect(
    str_contains($handler, 'CatalogBucketIdentityProcessor')
        && str_contains($handler, 'CatalogChunkedUploadCleanup')
        && str_contains($handler, 'package_md5')
        && str_contains($handler, 'hash_update($md5Context, $buffer)')
        && str_contains($handler, 'throw $error;'),
    'Ordinary deferred processing does not reuse and verify hashes while making its existing working copy.'
);
upload_bucket_throughput_expect(
    str_contains($redirectHandler, 'CatalogBucketIdentityProcessor')
        && str_contains($redirectHandler, "(string)(\$decoded['md5']")
        && str_contains($redirectHandler, "(string)(\$decoded['sha1']"),
    'Redirect jobs do not use decompressed package identities.'
);
upload_bucket_throughput_expect(
    str_contains($redirectStream, "hash_init('md5')")
        && str_contains($redirectStream, 'hash_update($md5Context, $block)')
        && str_contains($redirectStream, "'md5' => hash_final(\$md5Context)")
        && str_contains($redirectPayload, '$md5 = md5($output)')
        && str_contains($redirectPayload, '$sha1 = sha1($output)'),
    'Redirect package hashes are not calculated while decompressed bytes are produced.'
);
upload_bucket_throughput_expect(
    str_contains($identityProcessor, 'Using MD5 and SHA-1 calculated while the package bytes were produced')
        && str_contains($identityProcessor, 'CatalogUploadDuplicateDetector')
        && !str_contains($identityProcessor, 'hash_file(')
        && !str_contains($identityProcessor, 'md5_file(')
        && !str_contains($identityProcessor, 'sha1_file('),
    'Identity-aware package processing still performs a separate full-file hashing pass.'
);
$sampleIdentityLock = 'udb-bi-' . substr(hash('sha256', str_repeat('a', 32) . ':' . str_repeat('b', 40)), 0, 56);
upload_bucket_throughput_expect(
    strlen($sampleIdentityLock) <= 64
        && str_contains($identityProcessor, 'identityLockName')
        && str_contains($identityProcessor, "'udb-bi-' . substr(hash('sha256', \$md5 . ':' . \$sha1), 0, 56)")
        && !str_contains($identityProcessor, "'unrealdb-bucket-identity-' . \$md5 . '-' . \$sha1"),
    'Upload Bucket identity locks can exceed the MariaDB/MySQL 64-character user-level lock limit.'
);
upload_bucket_throughput_expect(
    str_contains($duplicateDetector, 'missing_base_game_matches')
        && str_contains($duplicateDetector, 'physical_identity_mismatches')
        && str_contains($duplicateDetector, 'FROM ue_files f WHERE LOWER(f.md5)=?')
        && !str_contains($duplicateDetector, 'FROM ue_files f WHERE f.file_size=? AND LOWER(f.md5)=?')
        && str_contains($duplicateDetector, 'filesize($physicalPath)')
        && str_contains($duplicateDetector, 'physicalPath')
        && str_contains($duplicateDetector, 'physicalIdentity')
        && str_contains($duplicateDetector, "hash_init('md5')")
        && str_contains($duplicateDetector, "hash_init('sha1')")
        && str_contains($duplicateDetector, "hash_equals(\$md5, \$physicalIdentity['md5'])")
        && str_contains($duplicateDetector, "hash_equals(\$sha1, \$physicalIdentity['sha1'])")
        && str_contains($duplicateDetector, 'ue_base_game_files'),
    'Duplicate detection trusts stale database size/hash metadata instead of verifying the physical package identity.'
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
    str_contains($manager, 'authoritative_status')
        && str_contains($manager, 'Recover and resume')
        && str_contains($workerStatusApi, "'orphaned'")
        && str_contains($workerStatusApi, 'queue_counts'),
    'Background Jobs does not use one authoritative worker state.'
);
upload_bucket_throughput_expect(
    str_contains($manager, 'Select all ')
        && str_contains($manager, 'scope: scope')
        && str_contains($manager, 'Restart matching retryable jobs'),
    'Background Jobs cannot manage all matching or mixed eligible jobs.'
);
upload_bucket_throughput_expect(
    str_contains($bulkApi, "'matching'")
        && str_contains($bulkApi, 'COUNT(*) c')
        && str_contains($bulkApi, 'status="completed" AND'),
    'Bulk actions are still page-limited or cannot restart legacy failed-result jobs.'
);
upload_bucket_throughput_expect(
    str_contains($statusApi, "'per_page'") && str_contains($statusApi, 'OFFSET ')
        && str_contains($statusApi, '1000'),
    'Background Jobs does not use real pagination up to 1,000 rows.'
);
upload_bucket_throughput_expect(
    substr_count($page, 'assets/background-jobs.js') === 1
        && !str_contains($page, 'background-jobs-authority.js')
        && !str_contains($page, 'background-jobs-running-controls.js')
        && !str_contains($page, 'background-jobs-stale-worker.js'),
    'Background Jobs still loads competing control scripts.'
);

echo "Paused deferred Upload Bucket throughput contract tests passed.\n";
