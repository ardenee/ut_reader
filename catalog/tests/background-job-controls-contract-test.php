<?php
declare(strict_types=1);

function background_job_controls_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$wrapper = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogNonBlockingImportJobHandler.php');
background_job_controls_expect(is_string($wrapper), 'Could not read non-blocking import handler.');
foreach ([
    'catch (JobCancellationRequested $error)',
    'catch (PDOException $error)',
    "'status' => 'failed'",
    "'percent' => 100",
    'isInfrastructureFailure',
] as $fragment) {
    background_job_controls_expect(str_contains($wrapper, $fragment), 'Non-blocking import handler is missing: ' . $fragment);
}

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
background_job_controls_expect(is_string($factory), 'Could not read job worker factory.');
background_job_controls_expect(
    str_contains($factory, 'new CatalogNonBlockingImportJobHandler('),
    'Job worker factory does not use the non-blocking import wrapper.'
);

$bootstrap = file_get_contents(__DIR__ . '/../api/v1/_bootstrap.php');
background_job_controls_expect(is_string($bootstrap), 'Could not read API bootstrap.');
background_job_controls_expect(
    str_contains($bootstrap, 'function catalog_api_require_admin(bool $requireRecentAuthentication = true)'),
    'API bootstrap does not support routine authenticated admin actions.'
);

foreach (['job-action.php', 'job-run.php', 'job-worker-action.php'] as $file) {
    $endpoint = file_get_contents(__DIR__ . '/../api/v1/' . $file);
    background_job_controls_expect(is_string($endpoint), 'Could not read ' . $file . '.');
    background_job_controls_expect(
        str_contains($endpoint, 'catalog_api_require_admin(false);'),
        $file . ' still requires recent reauthentication.'
    );
}

$action = file_get_contents(__DIR__ . '/../api/v1/job-action.php');
foreach ([
    "if ($action === 'delete')",
    "if ($action === 'cleanup')",
    'CatalogBackgroundJobCleanup',
    'retention_days',
] as $fragment) {
    background_job_controls_expect(str_contains($action, $fragment), 'Job action endpoint is missing: ' . $fragment);
}

$cleanup = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogBackgroundJobCleanup.php');
background_job_controls_expect(is_string($cleanup), 'Could not read background job cleanup service.');
foreach ([
    'status IN ("completed","failed","dead_letter","cancelled")',
    'deleteTerminalJob',
    'deleteStagedFiles',
    'MAX_BULK_DELETE',
] as $fragment) {
    background_job_controls_expect(str_contains($cleanup, $fragment), 'Background job cleanup service is missing: ' . $fragment);
}

$page = file_get_contents(__DIR__ . '/../background-jobs.php');
background_job_controls_expect(is_string($page), 'Could not read Background Jobs page.');
foreach (['jobs-cleanup-days', 'jobs-cleanup', 'Clean old jobs'] as $fragment) {
    background_job_controls_expect(str_contains($page, $fragment), 'Background Jobs page is missing: ' . $fragment);
}

$jobsClient = file_get_contents(__DIR__ . '/../assets/background-jobs.js');
background_job_controls_expect(is_string($jobsClient), 'Could not read Background Jobs client.');
foreach ([
    "mutate('cleanup'",
    "mutate('delete'",
    'job-status-duplicate',
    'job-status-failed',
    'effectiveStatus',
] as $fragment) {
    background_job_controls_expect(str_contains($jobsClient, $fragment), 'Background Jobs client is missing: ' . $fragment);
}

$uploadClient = file_get_contents(__DIR__ . '/../assets/profiled-upload-jobs.js');
background_job_controls_expect(is_string($uploadClient), 'Could not read profiled upload client.');
foreach ([
    'upload-result-imported',
    'upload-result-duplicate',
    'upload-result-failed',
    'upload-result-rejected',
    'installStatusStyles();',
] as $fragment) {
    background_job_controls_expect(str_contains($uploadClient, $fragment), 'Upload result colours are missing: ' . $fragment);
}

$statusEndpoint = file_get_contents(__DIR__ . '/../api/v1/job-status.php');
background_job_controls_expect(is_string($statusEndpoint), 'Could not read job status endpoint.');
background_job_controls_expect(
    str_contains($statusEndpoint, 'JSON_EXTRACT(result_json,"$.status")'),
    'Job list does not expose compact import outcomes.'
);

echo "Background job controls contract tests passed.\n";
