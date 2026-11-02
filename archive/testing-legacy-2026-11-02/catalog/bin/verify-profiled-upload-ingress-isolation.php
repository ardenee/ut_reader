<?php
/**
 * Static regression contract for large profiled-folder upload isolation.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'browser uses isolated batch API' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => "api/v1/profiled-upload-batch.php",
        'present' => true,
    ],
    'folder picker wrapper loads upload core' => [
        'path' => $root . '/assets/profiled-upload-jobs.js',
        'needle' => 'assets/profiled-upload-jobs-core.js',
        'present' => true,
    ],
    'browser no longer accumulates per-file queued job ids' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'queuedJobIds',
        'present' => false,
    ],
    'browser no longer releases thousands of held jobs after upload' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'releaseBatch()',
        'present' => false,
    ],
    'browser performs no per-file server duplicate preflight' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'serverDuplicatePreflight',
        'present' => false,
    ],
    'browser builds upload plan before staging begins' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'const plan = await buildUploadPlan(',
        'present' => true,
    ],
    'browser has a separate continuous upload phase' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'await uploadPlan(plan);',
        'present' => true,
    ],
    'browser caps live result DOM rows' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'MAX_LOG_ROWS = 250',
        'present' => true,
    ],
    'browser reuses one hash worker across files' => [
        'path' => $root . '/assets/profiled-upload-jobs-core.js',
        'needle' => 'if (!activeHashWorker)',
        'present' => true,
    ],
    'standard file upload is staged without creating a job' => [
        'path' => $root . '/api/v1/profiled-upload-batch.php',
        'needle' => "'background_job_created' => false",
        'present' => true,
    ],
    'batch API releases PHP session lock before ingress work' => [
        'path' => $root . '/api/v1/profiled-upload-batch.php',
        'needle' => 'session_write_close();',
        'present' => true,
    ],
    'chunk API releases PHP session lock before chunk work' => [
        'path' => $root . '/api/v1/profiled-upload-chunk.php',
        'needle' => 'session_write_close();',
        'present' => true,
    ],
    'chunk init no longer prunes stale uploads inline' => [
        'path' => $root . '/api/v1/profiled-upload-chunk.php',
        'needle' => 'pruneIncomplete',
        'present' => false,
    ],
    'duplicate preflight does not reopen application session' => [
        'path' => $root . '/api/v1/profiled-upload-preflight.php',
        'needle' => '$application = catalog_api_application();',
        'present' => false,
    ],
    'batch store is append-only during file ingress' => [
        'path' => $root . '/src/Infrastructure/Import/CatalogProfiledUploadBatchStore.php',
        'needle' => "fopen(\$this->manifestPath(\$batchId), 'ab')",
        'present' => true,
    ],
    'batch finalization creates one coordinator job type' => [
        'path' => $root . '/api/v1/profiled-upload-batch.php',
        'needle' => 'JobType::PROFILED_UPLOAD_BATCH',
        'present' => true,
    ],
    'coordinator expands manifest in bounded slices' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
        'needle' => 'PLAN_BATCH_SIZE = 100',
        'present' => true,
    ],
    'coordinator yields between manifest slices' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php',
        'needle' => '$context->defer(1, $progress, false);',
        'present' => true,
    ],
    'worker factory routes upload batch coordinator' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
        'needle' => 'JobType::PROFILED_UPLOAD_BATCH => static fn() => new CatalogProfiledUploadBatchJobHandler',
        'present' => true,
    ],
];

$failed = [];
foreach ($checks as $label => $check) {
    $content = is_file($check['path']) ? file_get_contents($check['path']) : false;
    $actual = is_string($content) && str_contains($content, $check['needle']);
    if (!is_string($content) || $actual !== $check['present']) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Profiled upload ingress isolation FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Profiled upload ingress isolation passed (" . count($checks) . " checks).\n";
