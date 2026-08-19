#!/usr/bin/env php
<?php
/** Static architecture contract for admin actions that must scale beyond display-page limits. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$checks = [
    'shared_bulk_asset_is_injected' => [
        'path' => $root . '/src/Presentation/Http/CatalogPageResponseTransform.php',
        'needle' => "['unverified-files.php', 'system-errors.php', 'upload-issues.php']",
        'present' => true,
    ],
    'unverified_all_matching_uses_background_job' => [
        'path' => $root . '/api/v1/unverified-bulk.php',
        'needle' => 'JobType::UNVERIFIED_BULK_ACTION',
        'present' => true,
    ],
    'unverified_selection_is_cursor_bounded' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoUnverifiedBulkSelectionQuery.php',
        'needle' => 'f.id>? AND f.id<=?',
        'present' => true,
    ],
    'unverified_selection_does_not_render_all_rows' => [
        'path' => $root . '/src/Infrastructure/Unverified/PdoUnverifiedBulkSelectionQuery.php',
        'needle' => 'LIMIT ' . "' . \$limit",
        'present' => true,
    ],
    'unverified_parent_plans_bounded_children' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogUnverifiedBulkActionJobHandler.php',
        'needle' => 'private const CHILD_BATCH_SIZE = 100;',
        'present' => true,
    ],
    'unverified_children_checkpoint_each_file' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogUnverifiedBulkActionJobHandler.php',
        'needle' => "$context->checkpoint([",
        'present' => true,
    ],
    'unverified_per_file_failures_do_not_abort_batch' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogUnverifiedBulkActionJobHandler.php',
        'needle' => 'catch (Throwable $error)',
        'present' => true,
    ],
    'worker_routes_unverified_bulk_parent' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
        'needle' => 'JobType::UNVERIFIED_BULK_ACTION =>',
        'present' => true,
    ],
    'worker_routes_unverified_bulk_batch' => [
        'path' => $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
        'needle' => 'JobType::UNVERIFIED_BULK_ACTION_BATCH =>',
        'present' => true,
    ],
    'system_errors_have_matching_api' => [
        'path' => $root . '/api/v1/system-errors-bulk.php',
        'needle' => '->systemErrors(',
        'present' => true,
    ],
    'upload_issues_have_matching_api' => [
        'path' => $root . '/api/v1/upload-issues-bulk.php',
        'needle' => '->uploadIssues(',
        'present' => true,
    ],
    'log_matching_mutations_do_not_materialize_ids' => [
        'path' => $root . '/src/Infrastructure/Maintenance/CatalogAdminMatchingBulkActionService.php',
        'needle' => 'WHERE id IN',
        'present' => false,
    ],
    'jobs_page_already_supports_all_matching' => [
        'path' => $root . '/background-jobs.php',
        'needle' => 'jobs-select-matching',
        'present' => true,
    ],
    'jobs_api_already_supports_matching_scope' => [
        'path' => $root . '/api/v1/job-bulk.php',
        'needle' => "['selected', 'matching']",
        'present' => true,
    ],
];

$failed = [];
foreach ($checks as $name => $check) {
    $content = @file_get_contents((string)$check['path']);
    $present = is_string($content) && str_contains($content, (string)$check['needle']);
    if (!is_string($content) || $present !== (bool)$check['present']) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Admin all-matching contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo 'Admin all-matching contract passed (' . count($checks) . " checks).\n";
