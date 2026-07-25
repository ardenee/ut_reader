<?php
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
$factory = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$handler = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogUnverifiedMetadataRepairJobHandler.php');
$library = file_get_contents($root . '/lib/UnverifiedMetadataRepair.php');
$page = file_get_contents($root . '/unverified-database-import.php');
$action = file_get_contents($root . '/unverified-database-import-action.php');
$ui = file_get_contents($root . '/src/Presentation/Ui/CatalogUi.php');

foreach (compact('jobType', 'policy', 'factory', 'handler', 'library', 'page', 'action', 'ui') as $name => $source) {
    metadata_repair_expect(is_string($source), $name . ' source is missing.');
}

metadata_repair_expect(
    str_contains($jobType, 'REPAIR_UNVERIFIED_METADATA')
        && str_contains($factory, 'CatalogUnverifiedMetadataRepairJobHandler'),
    'The targeted repair job is not registered with the worker.'
);
metadata_repair_expect(
    str_contains($policy, 'JobType::REPAIR_UNVERIFIED_METADATA')
        && str_contains($policy, "'bucket-processing'"),
    'Metadata repair is not serialized with Upload Bucket processing.'
);
metadata_repair_expect(
    str_contains($library, 'No file content')
        && str_contains($library, 'actual_name_count')
        && str_contains($library, 'actual_import_count')
        && str_contains($library, 'actual_export_count')
        && str_contains($library, 'Missing database inventory row')
        && str_contains($library, 'Package table inventory is empty'),
    'Candidate discovery does not remain lightweight or cannot find inventory gaps.'
);
metadata_repair_expect(
    str_contains($library, 'Metadata repair attempted:')
        && str_contains($library, 'REPAIR_UNVERIFIED_METADATA')
        && str_contains($library, 'unverified-metadata:'),
    'Repair jobs can loop unreadable files or be queued repeatedly without deduplication.'
);
metadata_repair_expect(
    str_contains($handler, 'catalog_unverified_index_path')
        && str_contains($handler, 'true')
        && str_contains($handler, 'game_id=NULL')
        && str_contains($handler, 'INTERVAL 6 HOUR'),
    'Repair jobs do not force a safe staging reindex or preserve unverified ownership.'
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
