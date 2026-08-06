<?php
declare(strict_types=1);

function job_status_filter_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$display = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobDisplayStatus.php');
job_status_filter_expect(is_string($display), 'Could not read job display status helper.');
foreach ([
    'public static function normalize(',
    'public static function group(',
    'public static function sqlExpression(',
    'public static function filterCondition(',
    "private const FAILED_OUTCOMES = ['failed', 'rejected', 'unverified']",
    "return \$resultStatus === 'verified' ? 'imported' : \$resultStatus;",
    "if (in_array(\$status, ['queued', 'running', 'dead_letter', 'cancelled'], true))",
    "return ['sql' => \$prefix . 'status=?', 'params' => [\$status]];",
    "if (\$status === 'failed')",
    "if (\$status === 'completed')",
] as $fragment) {
    job_status_filter_expect(str_contains($display, $fragment), 'Job display status helper is missing: ' . $fragment);
}

$statusEndpoint = file_get_contents(__DIR__ . '/../api/v1/job-status.php');
job_status_filter_expect(is_string($statusEndpoint), 'Could not read job status endpoint.');
foreach ([
    'CatalogJobDisplayStatus::isValidFilter',
    'CatalogJobDisplayStatus::filterCondition',
    'CatalogJobDisplayStatus::sqlExpression',
    'display_status',
] as $fragment) {
    job_status_filter_expect(str_contains($statusEndpoint, $fragment), 'Job status endpoint is missing: ' . $fragment);
}

$bulkEndpoint = file_get_contents(__DIR__ . '/../api/v1/job-bulk.php');
job_status_filter_expect(is_string($bulkEndpoint), 'Could not read bulk job endpoint.');
foreach ([
    'session_write_close()',
    "SET SESSION innodb_lock_wait_timeout=5",
    '$limit = 10000;',
    'SELECT id FROM ue_background_jobs WHERE ',
    "'worker_start_required'",
    'catalog_job_bulk_resume_payload(',
    'JobType::REBUILD_AFFECTED_DEPENDENCIES',
    "payload['resume_offset']",
    'Processed affected file\\s+(\\d+)\\/\\d+',
] as $fragment) {
    job_status_filter_expect(str_contains($bulkEndpoint, $fragment), 'Bulk job endpoint is missing: ' . $fragment);
}
job_status_filter_expect(
    !str_contains($bulkEndpoint, 'new CatalogDetachedWorker('),
    'Bulk restart still launches detached workers inside the HTTP request.'
);
job_status_filter_expect(
    !str_contains($bulkEndpoint, 'UPDATE ue_background_jobs SET status="queued"')
        || str_contains($bulkEndpoint, 'id IN ('),
    'Bulk restart is not bounded to a selected ID batch.'
);

$page = file_get_contents(__DIR__ . '/../background-jobs.php');
job_status_filter_expect(is_string($page), 'Could not read Background Jobs page.');
job_status_filter_expect(str_contains($page, 'CatalogJobDisplayStatus::group'), 'Background Jobs summary cards still use raw queue status only.');
job_status_filter_expect(str_contains($page, '.job-status + .muted.small{display:none}'), 'Background Jobs still displays the misleading job completed subline.');

$cleanup = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogBackgroundJobCleanup.php');
job_status_filter_expect(is_string($cleanup), 'Could not read background job cleanup service.');
job_status_filter_expect(str_contains($cleanup, 'CatalogJobDisplayStatus::filterCondition'), 'Bulk cleanup does not follow the visible status filter.');

$layout = file_get_contents(__DIR__ . '/../assets/catalog-layout-fixes.js');
job_status_filter_expect(is_string($layout), 'Could not read catalog layout fixes.');
foreach ([
    '.pak-info-table-region th:first-child',
    "page !== 'pak-info.php'",
    "header.textContent = 'Database'",
    "header.title = 'Names / Imports / Exports'",
] as $fragment) {
    job_status_filter_expect(str_contains($layout, $fragment), 'PAK information layout fix is missing: ' . $fragment);
}

echo "Background job status filter contract tests passed.\n";
