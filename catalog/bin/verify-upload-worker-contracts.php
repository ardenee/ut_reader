#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies Upload Bucket v2 retirement boundaries and detached-worker orchestration contracts.
 * Why: The legacy uploader and worker-pool regressions are easy to reintroduce through apparently local changes.
 * Role: Read-only CLI architecture/regression verification; never mutates schema or application data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($catalogRoot);
$checks = [];
$failures = [];

$read = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$legacyPage = $read('catalog/upload-bucket.php');
$record(
    'legacy_upload_route_redirect_only',
    str_contains($legacyPage, "header('Location: upload-bucket-v2.php', true, 302);")
        && str_contains($legacyPage, 'exit;')
        && !str_contains($legacyPage, '$_FILES')
        && !str_contains($legacyPage, 'hash_file(')
        && !str_contains($legacyPage, 'md5_file(')
        && !str_contains($legacyPage, 'move_uploaded_file(')
        && !str_contains($legacyPage, 'CatalogBucketBatchQueue')
        && !str_contains($legacyPage, 'ue_background_jobs'),
    'upload-bucket.php must remain a compatibility redirect with no upload implementation'
);

$legacyRouteCallers = [];
$catalogIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repoRoot . '/catalog', RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($catalogIterator as $item) {
    if (!$item instanceof SplFileInfo || !$item->isFile()) {
        continue;
    }
    $extension = strtolower($item->getExtension());
    if (!in_array($extension, ['php', 'js'], true)) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($repoRoot) + 1));
    if (in_array($relative, [
        'catalog/upload-bucket.php',
        'catalog/upload-bucket-v2.php',
        'catalog/bin/verify-upload-worker-contracts.php',
    ], true)) {
        continue;
    }
    $content = (string)@file_get_contents($item->getPathname());
    if (str_contains($content, "'upload-bucket.php'") || str_contains($content, '"upload-bucket.php"')) {
        $legacyRouteCallers[] = $relative;
    }
}
$record(
    'no_internal_legacy_upload_route_callers',
    $legacyRouteCallers === [],
    implode(', ', $legacyRouteCallers)
);

$navigation = $read('catalog/lib/CatalogNavigation.php');
$record(
    'navigation_uses_upload_v2',
    str_contains($navigation, "'Upload Bucket' => \$root . 'upload-bucket-v2.php'")
        && !str_contains($navigation, "'Upload Bucket' => \$root . 'upload-bucket.php'"),
    'administrator navigation must target the canonical v2 route'
);

$uploadPage = $read('catalog/upload-bucket-v2.php');
$record(
    'upload_v2_page_reuses_shared_policy',
    str_contains($uploadPage, 'CatalogUploadBucketFilePolicy')
        && str_contains($uploadPage, 'CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes')
        && !str_contains($uploadPage, 'function upload_bucket_v2_allowed_extensions(')
        && !str_contains($uploadPage, 'function upload_bucket_v2_chunk_bytes('),
    'page rendering must reuse server file/chunk policy instead of maintaining parallel copies'
);

$coordinator = $read('catalog/assets/upload-bucket-v2-coordinator.js');
$record(
    'upload_v2_browser_pipeline',
    str_contains($coordinator, 'function inspectFile(')
        && str_contains($coordinator, 'async function preflight(')
        && str_contains($coordinator, "initData.append('action', 'init')")
        && str_contains($coordinator, "data.append('action', 'chunk')")
        && str_contains($coordinator, "completeData.append('action', 'complete')")
        && str_contains($coordinator, 'async function finalizeOne(')
        && str_contains($coordinator, 'start_worker: false')
        && str_contains($coordinator, 'start_worker: true'),
    'v2 must inspect/hash/preflight, stage chunks, finalize durable jobs, then start processing'
);

$bucketHandler = $read('catalog/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php');
$identityProcessor = $read('catalog/src/Infrastructure/Import/CatalogBucketIdentityProcessor.php');
$unverifiedImportAction = $read('catalog/unverified-files-action.php');
$unverifiedImportService = $read('catalog/src/Infrastructure/Unverified/CatalogUnverifiedImportService.php');
$unverifiedPromotion = $read('catalog/src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php');
$unverifiedDependencyRecovery = $read('catalog/src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php');
$record(
    'upload_v2_unverified_import_dependency_chain',
    str_contains($bucketHandler, 'JobType::PROCESS_BUCKET_UPLOAD')
        && str_contains($bucketHandler, 'CatalogBucketIdentityProcessor')
        && str_contains($identityProcessor, 'CatalogUploadDuplicateDetector')
        && str_contains($identityProcessor, '$this->operations->store(')
        && str_contains($identityProcessor, '$this->operations->index(')
        && str_contains($unverifiedImportAction, 'CatalogUnverifiedImportService')
        && str_contains($unverifiedImportService, 'CatalogUnverifiedPromotion')
        && str_contains($unverifiedPromotion, '$this->dependencies->queueRefresh(')
        && str_contains($unverifiedDependencyRecovery, 'CatalogPostImportDependencyQueue::enqueue('),
    'queued upload must reach unverified indexing and later promotion must retain durable dependency scheduling'
);

$batchEndpoint = $read('catalog/api/v1/upload-bucket-batch.php');
$batchQueue = $read('catalog/src/Infrastructure/Import/CatalogBucketBatchQueue.php');
$batchFinalizer = $read('catalog/src/Infrastructure/Import/CatalogBucketBatchFinalizer.php');
$record(
    'upload_v2_server_pipeline_single_owner',
    str_contains($batchEndpoint, 'CatalogBucketBatchFinalizer')
        && !str_contains($batchEndpoint, 'ue_background_jobs')
        && str_contains($batchQueue, 'CatalogUploadDuplicateDetector')
        && str_contains($batchQueue, 'PdoJobQueue')
        && str_contains($batchQueue, 'JobType::PROCESS_BUCKET_UPLOAD')
        && str_contains($batchFinalizer, '$launcher->configuredWorkerCount()'),
    'dedupe/enqueue must stay in the shared queue service and worker start must honor configured pool size'
);

$jobRun = $read('catalog/api/v1/job-run.php');
$poolReconciler = $read('catalog/src/Infrastructure/Jobs/CatalogWorkerPoolReconciler.php');
$record(
    'job_run_thin_controller',
    str_contains($jobRun, 'CatalogWorkerPoolReconciler')
        && !str_contains($jobRun, 'ue_background_jobs')
        && !str_contains($jobRun, 'CatalogDetachedWorker')
        && !str_contains($jobRun, 'CatalogOrphanedJobRecovery')
        && !str_contains($jobRun, 'CatalogJobResourceLimitStore'),
    'job-run.php must validate/serialize only; pool lifecycle belongs to the reconciler'
);
$record(
    'worker_pool_reconciliation_contract',
    str_contains($poolReconciler, 'PdoBackgroundJobOperationalQuery')
        && str_contains($poolReconciler, 'CatalogOrphanedJobRecovery')
        && str_contains($poolReconciler, 'CatalogJobResourceLimitStore')
        && str_contains($poolReconciler, '$launcher->start($queueName, $maxJobs, $workerCount)')
        && str_contains($poolReconciler, '$launcher->status($queueName, false)')
        && str_contains($poolReconciler, '$launcher->status($queueName, true)'),
    'pool reconciliation must use indexed operational reads, explicit pool size and log-free polling'
);

$statusPolicy = $read('catalog/src/Application/Jobs/CatalogWorkerStatusPolicy.php');
$statusEndpoint = $read('catalog/api/v1/job-worker-status.php');
$record(
    'worker_processed_active_slots_only',
    str_contains($statusPolicy, 'public static function activeProcessed(')
        && str_contains($statusPolicy, "empty(\$slotWorker['active'])")
        && str_contains($statusEndpoint, 'CatalogWorkerStatusPolicy::activeProcessed($worker)')
        && str_contains($statusEndpoint, "\$workerState['processed'] = \$activeProcessed")
        && str_contains($statusEndpoint, "\$worker['active_processed'] = \$activeProcessed"),
    'live processed reporting must exclude retained state from stopped worker slots'
);

try {
    require_once $repoRoot . '/catalog/src/Application/Jobs/CatalogWorkerStatusPolicy.php';
    $fixtureProcessed = \UnrealDb\Catalog\Application\Jobs\CatalogWorkerStatusPolicy::activeProcessed([
        'workers' => [
            ['active' => true, 'state' => ['processed' => 3]],
            ['active' => false, 'state' => ['processed' => 900]],
            ['active' => true, 'state' => ['processed' => 4]],
        ],
    ]);
    $record(
        'worker_processed_fixture',
        $fixtureProcessed === 7,
        'expected active-slot processed total 7, got ' . $fixtureProcessed
    );
} catch (Throwable $error) {
    $record('worker_processed_fixture', false, $error->getMessage());
}

$affectedRefresh = $read('catalog/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshCoordinator.php');
$record(
    'dependency_refresh_expands_pool',
    str_contains($affectedRefresh, '$desiredWorkers = $launcher->configuredWorkerCount()')
        && str_contains($affectedRefresh, '$activeOrLaunching < $desiredWorkers')
        && str_contains($affectedRefresh, '$launcher->start($queueName, 10000, $desiredWorkers)'),
    'dependency follow-up scheduling must repair a partially active worker pool'
);

$legacyStager = $read('catalog/src/Infrastructure/Legacy/LegacyUnverifiedFileStager.php');
$legacyStagerCallers = [
    'catalog/lib/CatalogSourceScan.php',
    'catalog/lib/FederationWorker.php',
    'catalog/lib/Scanner/CatalogScannerSupport.php',
    'catalog/src/Infrastructure/Jobs/CatalogPakImportJobHandler.php',
    'catalog/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
];
$activeCompatibilityCallers = [];
foreach ($legacyStagerCallers as $relative) {
    if (str_contains($read($relative), 'LegacyUnverifiedFileStager')) {
        $activeCompatibilityCallers[] = $relative;
    }
}
$record(
    'legacy_unverified_stager_not_mistaken_for_old_uploader',
    str_contains($legacyStager, 'final class LegacyUnverifiedFileStager')
        && $activeCompatibilityCallers !== [],
    'active compatibility callers: ' . implode(', ', $activeCompatibilityCallers)
);

$criticalPhp = [
    'catalog/bin/verify-upload-worker-contracts.php',
    'catalog/upload-bucket-v2.php',
    'catalog/api/v1/job-run.php',
    'catalog/api/v1/job-worker-status.php',
    'catalog/src/Application/Jobs/CatalogWorkerStatusPolicy.php',
    'catalog/src/Infrastructure/Import/CatalogBucketBatchFinalizer.php',
    'catalog/src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshCoordinator.php',
    'catalog/src/Infrastructure/Jobs/CatalogWorkerPoolReconciler.php',
    'catalog/src/Infrastructure/Jobs/CatalogWorkerPoolStaleRestartFailed.php',
    'catalog/src/Infrastructure/Unverified/CatalogUnverifiedImportService.php',
    'catalog/src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php',
    'catalog/src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually on the guarded PHP files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
