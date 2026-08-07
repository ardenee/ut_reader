<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies unverified metadata repair behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function metadata_repair_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$jobType = file_get_contents($root . '/src/Domain/Jobs/JobType.php');
$policy = file_get_contents($root . '/src/Domain/Jobs/JobResourcePolicy.php');
$context = file_get_contents($root . '/src/Application/Jobs/JobExecutionContext.php');
$factory = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$handler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogUnverifiedMetadataRepairJobHandler.php');
$processor = file_get_contents($root . '/src/Infrastructure/Import/CatalogUnverifiedMetadataRepairProcessor.php');
$library = file_get_contents($root . '/lib/UnverifiedMetadataRepair.php');
$profiles = file_get_contents($root . '/lib/GameProfiles.php');
$page = file_get_contents($root . '/unverified-database-import.php');
$action = file_get_contents($root . '/unverified-database-import-action.php');
$jobsPage = file_get_contents($root . '/background-jobs.php');
$statusApi = file_get_contents($root . '/api/v1/job-status.php');
$ui = file_get_contents($root . '/src/Presentation/Ui/CatalogUi.php');

foreach (compact('jobType', 'policy', 'context', 'factory', 'handler', 'processor', 'library', 'profiles', 'page', 'action', 'jobsPage', 'statusApi', 'ui') as $name => $source) {
    metadata_repair_expect(is_string($source), $name . ' source is missing.');
}

metadata_repair_expect(
    str_contains($jobType, 'REPAIR_UNVERIFIED_METADATA')
        && str_contains($factory, 'CatalogUnverifiedMetadataRepairJobHandler'),
    'The targeted repair job is not registered with the worker.'
);
metadata_repair_expect(
    str_contains($policy, 'JobType::REPAIR_UNVERIFIED_METADATA')
        && str_contains($policy, "'bucket-processing'")
        && str_contains($context, 'JobType::REPAIR_UNVERIFIED_METADATA'),
    'Metadata repair is not serialized with Upload Bucket processing or given a renewable package lease.'
);
metadata_repair_expect(
    str_contains($library, '16-byte package summary')
        && str_contains($library, 'gp_read_legacy_summary($path)')
        && str_contains($library, 'actual_name_count')
        && str_contains($library, 'actual_import_count')
        && str_contains($library, 'actual_export_count')
        && str_contains($library, 'Missing database inventory row')
        && str_contains($library, 'Package table inventory is empty'),
    'Candidate discovery does not remain lightweight or cannot find inventory/classification gaps.'
);
metadata_repair_expect(
    str_contains($profiles, 'if ($version >= 334)')
        && str_contains($profiles, 'UE3 package-summary layout starts at version 334')
        && str_contains($library, 'does not match package header')
        && str_contains($library, 'unverified-metadata-v2:'),
    'Early UE3 packages cannot be reclassified and queued after the reader-boundary fix.'
);
metadata_repair_expect(
    str_contains($library, 'Metadata repair attempted:')
        && str_contains($library, 'REPAIR_UNVERIFIED_METADATA'),
    'Repair jobs can loop unreadable files or are not registered for deduplicated queueing.'
);
metadata_repair_expect(
    str_contains($processor, "new ReflectionMethod(CatalogBucketUploadProcessor::class, 'hashIdentity')")
        && str_contains($processor, "new ReflectionMethod(CatalogBucketUploadProcessor::class, 'indexStored')")
        && !str_contains($processor, 'rename('),
    'Upload Bucket repair does not reuse the granular parser/database writer in place.'
);
metadata_repair_expect(
    str_contains($handler, 'Part 1 of 4')
        && str_contains($handler, 'Part 2 of 4')
        && str_contains($handler, 'Part 3 of 4')
        && str_contains($handler, 'Part 4 of 4')
        && str_contains($handler, "'repair_header'")
        && str_contains($handler, "'repair_names'")
        && str_contains($handler, "'repair_imports'")
        && str_contains($handler, "'repair_exports'")
        && str_contains($handler, "'repair_save'")
        && str_contains($handler, '$context->checkpoint($mapped)')
        && str_contains($handler, "'stage' => 'complete'")
        && str_contains($handler, "'message' => \$completionMessage"),
    'Repair progress does not expose forced Header/Names/Imports/Exports/save checkpoints and a real completion message.'
);
metadata_repair_expect(
    str_contains($handler, "'file_started_at'")
        && str_contains($handler, "'stage_started_at'")
        && str_contains($jobsPage, 'Current file time')
        && str_contains($jobsPage, 'job’s own claim time'),
    'Per-file and per-stage timing is not identified separately from the worker process lifetime.'
);
metadata_repair_expect(
    str_contains($statusApi, 'normalizedResult')
        && str_contains($statusApi, 'normalizedProgress')
        && str_contains($statusApi, "unset(\$result['message'])"),
    'Completed parser warnings can still be shown twice as status and Error/result.'
);
metadata_repair_expect(
    str_contains($page, 'Complete files are not opened or reprocessed')
        && str_contains($page, 'Queue ')
        && str_contains($action, 'CatalogDetachedWorker')
        && str_contains($action, 'recoverInactiveQueue'),
    'The repair UI does not explain the targeted scope or start the background worker safely.'
);
metadata_repair_expect(
    str_contains($ui, 'Repair missing metadata')
        && str_contains($ui, 'unverified-database-import.php'),
    'The repair option is not visibly labelled from Unverified Files.'
);

echo "Targeted unverified metadata repair contract tests passed.\n";
