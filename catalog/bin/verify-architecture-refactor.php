#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the August 2026 job/upload architecture refactor before functional testing.
 * Why: Large behavior-preserving structural changes need repeatable source/schema guards even without a broad test suite.
 * Role: CLI verification only; never mutates application data or schema.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checkDatabase = in_array('--database', array_slice($argv, 1), true);
$failures = [];
$checks = [];

$record = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$read = static function (string $relative) use ($catalogRoot): string {
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$criticalPhp = [
    'bin/verify-architecture-refactor.php',
    'background-jobs.php',
    'api/v1/job-worker-status.php',
    'api/v1/job-status-cursor.php',
    'api/v1/job-status.php',
    'api/v1/job-bulk.php',
    'api/v1/upload-bucket-batch.php',
    'api/v1/upload-bucket-chunk.php',
    'src/Application/Jobs/CatalogWorkerStatusPolicy.php',
    'src/Application/Maintenance/LegacyMetadataRuntimeAudit.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobResultHydrator.php',
    'src/Infrastructure/Jobs/CatalogJobDisplayStatus.php',
    'src/Infrastructure/Jobs/CatalogJobSearchProjectionRuntime.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php',
    'src/Infrastructure/Persistence/PdoJobQueue.php',
    'src/Infrastructure/Persistence/PdoJobQueueSupport.php',
    'src/Infrastructure/Persistence/PdoJobEnqueuer.php',
    'src/Infrastructure/Persistence/PdoJobClaimer.php',
    'src/Infrastructure/Persistence/PdoJobLeaseStore.php',
    'src/Infrastructure/Persistence/PdoJobRecovery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobDisplayCountQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBrowserQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOffsetQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobQueueSummaryQuery.php',
    'src/Infrastructure/Import/CatalogBucketIdentityProcessor.php',
    'src/Infrastructure/Import/CatalogBucketPackageOperations.php',
    'src/Infrastructure/Import/CatalogBucketPackageOperationsService.php',
    'src/Infrastructure/Import/CatalogPackageIdentityHasher.php',
    'src/Infrastructure/Import/CatalogUploadBucketStorage.php',
    'src/Infrastructure/Import/CatalogUnverifiedPackageRuntime.php',
    'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
    'src/Infrastructure/Import/CatalogUnverifiedMetadataRepairProcessor.php',
    'src/Infrastructure/Import/CatalogImportPathPolicy.php',
    'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'src/Infrastructure/Import/CatalogBucketBatchQueue.php',
    'src/Infrastructure/Import/CatalogBucketBatchFinalizer.php',
    'src/Infrastructure/Import/CatalogBucketProcessingActive.php',
    'src/Infrastructure/Import/CatalogBucketProcessingStateService.php',
    'src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php',
    'src/Infrastructure/Import/CatalogBucketUploadTransferStoreFactory.php',
    'migrations/202608080001_background_job_display_status.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually on changed files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

foreach ([
    'src/Infrastructure/Persistence/WorkerJobQueue.php',
    'src/Infrastructure/Import/CatalogBucketUploadProcessor.php',
    'src/Infrastructure/Import/LegacyCatalogBucketPackageOperations.php',
] as $relative) {
    $record(
        'removed:' . $relative,
        !file_exists($catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        'superseded implementation must stay deleted'
    );
}

$reflectionMatches = [];
$importRoot = $catalogRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Import';
if (is_dir($importRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($importRoot, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile() || strtolower($item->getExtension()) !== 'php') {
            continue;
        }
        $content = (string)@file_get_contents($item->getPathname());
        if (str_contains($content, 'ReflectionMethod') || str_contains($content, 'setAccessible(true)')) {
            $reflectionMatches[] = str_replace('\\', '/', substr($item->getPathname(), strlen($catalogRoot) + 1));
        }
    }
}
$record('upload_import_no_reflection', $reflectionMatches === [], implode(', ', $reflectionMatches));

$queueFacade = $read('src/Infrastructure/Persistence/PdoJobQueue.php');
$record(
    'queue_single_facade',
    $queueFacade !== ''
        && str_contains($queueFacade, 'PdoJobEnqueuer')
        && str_contains($queueFacade, 'PdoJobClaimer')
        && str_contains($queueFacade, 'PdoJobLeaseStore')
        && str_contains($queueFacade, 'PdoJobRecovery')
        && !str_contains($queueFacade, 'ue_background_jobs'),
    'PdoJobQueue must delegate instead of owning SQL'
);

$claimer = $read('src/Infrastructure/Persistence/PdoJobClaimer.php');
$record(
    'parallel_claim_strategy',
    str_contains($claimer, 'FOR UPDATE SKIP LOCKED')
        && !str_contains($claimer, 'unrealdb:job-claim:')
        && !str_contains($claimer, 'active.resource_class=candidate.resource_class'),
    'claim must use row-level SKIP LOCKED rather than a queue-wide mutex/correlated candidate count'
);

foreach ([
    'background-jobs.php',
    'api/v1/job-worker-status.php',
    'api/v1/job-bulk.php',
    'api/v1/job-status-cursor.php',
    'api/v1/job-status.php',
    'api/v1/upload-bucket-batch.php',
    'api/v1/upload-bucket-chunk.php',
] as $relative) {
    $content = $read($relative);
    $record(
        'thin_controller:' . $relative,
        $content !== ''
            && !str_contains($content, 'ue_background_jobs')
            && !str_contains($content, 'JSON_EXTRACT(result_json'),
        'job/upload presentation entry point must delegate durable-job persistence and display-status derivation'
    );
}

$statusHelper = $read('src/Infrastructure/Jobs/CatalogJobDisplayStatus.php');
$migration = $read('migrations/202608080001_background_job_display_status.php');
$record(
    'indexed_display_status_source',
    str_contains($statusHelper, 'display_status')
        && str_contains($migration, 'GENERATED ALWAYS AS')
        && str_contains($migration, 'idx_ue_background_jobs_queue_display_id'),
    'display status must remain generated and indexed'
);

$pathPolicy = $read('src/Infrastructure/Import/CatalogImportPathPolicy.php');
$profiledQueue = $read('src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
$bucketQueue = $read('src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$bucketHandler = $read('src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$redirectHandler = $read('src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php');
$record(
    'shared_import_path_policy',
    $pathPolicy !== ''
        && str_contains($profiledQueue, 'CatalogImportPathPolicy::relative')
        && str_contains($bucketQueue, 'CatalogImportPathPolicy::relative')
        && str_contains($bucketHandler, 'CatalogImportPathPolicy::relative')
        && str_contains($redirectHandler, 'CatalogImportPathPolicy::relative'),
    'primary upload queues and worker handlers must share canonical relative-path normalization'
);

$batchEndpoint = $read('api/v1/upload-bucket-batch.php');
$batchFinalizer = $read('src/Infrastructure/Import/CatalogBucketBatchFinalizer.php');
$record(
    'bucket_batch_orchestration_boundary',
    str_contains($batchEndpoint, 'CatalogBucketBatchFinalizer')
        && !str_contains($batchEndpoint, 'CatalogDetachedWorker')
        && !str_contains($batchEndpoint, 'CatalogOrphanedJobRecovery')
        && str_contains($batchFinalizer, 'CatalogDetachedWorker')
        && str_contains($batchFinalizer, 'CatalogOrphanedJobRecovery'),
    'Upload Bucket batch API must delegate queue/worker orchestration'
);

$chunkEndpoint = $read('api/v1/upload-bucket-chunk.php');
$processingState = $read('src/Infrastructure/Import/CatalogBucketProcessingStateService.php');
$filePolicy = $read('src/Infrastructure/Import/CatalogUploadBucketFilePolicy.php');
$transferFactory = $read('src/Infrastructure/Import/CatalogBucketUploadTransferStoreFactory.php');
$record(
    'bucket_chunk_orchestration_boundary',
    str_contains($chunkEndpoint, 'CatalogBucketProcessingStateService')
        && str_contains($chunkEndpoint, 'CatalogUploadBucketFilePolicy')
        && str_contains($chunkEndpoint, 'CatalogBucketUploadTransferStoreFactory')
        && !str_contains($chunkEndpoint, 'CatalogDetachedWorker')
        && !str_contains($chunkEndpoint, 'CatalogBucketBatchQueue')
        && str_contains($processingState, 'CatalogDetachedWorker')
        && str_contains($filePolicy, 'allowedExtensions')
        && str_contains($transferFactory, 'effectiveChunkBytes'),
    'Upload Bucket chunk API must delegate worker, file/profile and transfer-store policy'
);

if ($checkDatabase) {
    try {
        require_once $catalogRoot . '/bootstrap.php';
        $application = catalog_bootstrap();
        $schema = new SchemaInspector($application->db);
        $record('db:display_status_column', $schema->columnExists('ue_background_jobs', 'display_status'));
        $record('db:display_status_queue_index', $schema->indexExists('ue_background_jobs', 'idx_ue_background_jobs_queue_display_id'));
        $record('db:display_status_index', $schema->indexExists('ue_background_jobs', 'idx_ue_background_jobs_display_id'));
        $record('db:resource_limits_table', $schema->tableExists('ue_job_resource_limits'));
        $record('db:resource_index', $schema->indexExists('ue_background_jobs', 'idx_ue_background_jobs_resource'));
        $record('db:concurrency_index', $schema->indexExists('ue_background_jobs', 'idx_ue_background_jobs_concurrency'));

        if ($schema->columnExists('ue_background_jobs', 'display_status')) {
            $statement = $application->db->query(
                'SELECT id,status,result_json,display_status FROM ue_background_jobs ORDER BY id DESC LIMIT 1000'
            );
            $mismatches = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $resultStatus = null;
                if (!empty($row['result_json'])) {
                    $decoded = json_decode((string)$row['result_json'], true);
                    if (is_array($decoded)) {
                        $resultStatus = isset($decoded['status']) ? (string)$decoded['status'] : null;
                    }
                }
                $expected = CatalogJobDisplayStatus::normalize((string)$row['status'], $resultStatus);
                $actual = strtolower(trim((string)$row['display_status']));
                if ($expected !== $actual) {
                    $mismatches[] = '#' . (int)$row['id'] . ' expected=' . $expected . ' actual=' . $actual;
                    if (count($mismatches) >= 20) {
                        break;
                    }
                }
            }
            $record('db:display_status_parity', $mismatches === [], implode(' | ', $mismatches));
        }
    } catch (Throwable $error) {
        $record('database_checks', false, $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'database_checked' => $checkDatabase,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
