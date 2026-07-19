<?php
declare(strict_types=1);

function source_identity_job_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../source-identity-repair.php');
source_identity_job_expect(is_string($page), 'source-identity-repair.php could not be read.');
source_identity_job_expect(str_contains($page, 'source-identity-repair-jobs.js'), 'Source Identity Repair no longer loads its durable job client.');
source_identity_job_expect(str_contains($page, "catalog_csrf('job_action')"), 'Source Identity Repair no longer uses the protected job-action scope.');
foreach (['catalog_source_identity_rebuild_file(', 'scanner_rebuild_game(', 'GET_LOCK(', "operation === 'repair_game'"] as $inlineBoundary) {
    source_identity_job_expect(!str_contains($page, $inlineBoundary), 'Source Identity Repair returned to inline mutation: ' . $inlineBoundary);
}

$compatibilityApi = file_get_contents(__DIR__ . '/../source-identity-repair-api.php');
source_identity_job_expect(is_string($compatibilityApi), 'source-identity-repair-api.php could not be read.');
source_identity_job_expect(str_contains($compatibilityApi, 'PdoJobQueue'), 'The compatibility endpoint no longer enqueues durable jobs.');
source_identity_job_expect(str_contains($compatibilityApi, 'REPAIR_SOURCE_IDENTITY_FILE'), 'The compatibility endpoint no longer queues source identity repair.');
foreach (['catalog_source_identity_rebuild_file(', 'scanner_rebuild_dependencies(', 'upload_progress_write(', 'source_identity_api_with_lock'] as $inlineBoundary) {
    source_identity_job_expect(!str_contains($compatibilityApi, $inlineBoundary), 'The compatibility endpoint still executes maintenance inline: ' . $inlineBoundary);
}

$client = file_get_contents(__DIR__ . '/../assets/source-identity-repair-jobs.js');
source_identity_job_expect(is_string($client), 'source-identity-repair-jobs.js could not be read.');
foreach (['enqueue_source_identity_file', 'enqueue_source_identity_game', 'job_id=', "action: 'cancel'", 'catalog.repair_source_identity_game'] as $fragment) {
    source_identity_job_expect(str_contains($client, $fragment), 'Source identity job client is missing ' . $fragment);
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
source_identity_job_expect(is_string($types), 'JobType.php could not be read.');
source_identity_job_expect(str_contains($types, 'REPAIR_SOURCE_IDENTITY_FILE'), 'The file source identity job type is missing.');
source_identity_job_expect(str_contains($types, 'REPAIR_SOURCE_IDENTITY_GAME'), 'The game source identity job type is missing.');

$policy = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php');
source_identity_job_expect(is_string($policy), 'JobResourcePolicy.php could not be read.');
source_identity_job_expect(str_contains($policy, "'source-identity:file:'"), 'File source identity jobs have no target concurrency key.');
source_identity_job_expect(str_contains($policy, "'source-identity:game:'"), 'Game source identity jobs have no target concurrency key.');
source_identity_job_expect(substr_count($policy, 'self::DEPENDENCY_HEAVY') >= 4, 'Source identity repair no longer shares the exclusive dependency-heavy class.');

$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogMaintenanceJobHandler.php');
source_identity_job_expect(is_string($handler), 'CatalogMaintenanceJobHandler.php could not be read.');
foreach (['repairSourceIdentityFile', 'repairSourceIdentityGame', 'catalog_source_identity_rebuild_file(', 'scanner_rebuild_game(', 'withMaintenanceWriteLock'] as $fragment) {
    source_identity_job_expect(str_contains($handler, $fragment), 'The source identity worker handler is missing ' . $fragment);
}

$action = file_get_contents(__DIR__ . '/../api/v1/job-action.php');
source_identity_job_expect(is_string($action), 'job-action.php could not be read.');
source_identity_job_expect(str_contains($action, 'enqueue_source_identity_file'), 'The secured API cannot enqueue file source identity repair.');
source_identity_job_expect(str_contains($action, 'enqueue_source_identity_game'), 'The secured API cannot enqueue game source identity repair.');
source_identity_job_expect(str_contains($action, 'unsupported_engine'), 'The secured API no longer rejects legacy-engine repair targets.');

echo "Source identity durable-job contract tests passed.\n";
