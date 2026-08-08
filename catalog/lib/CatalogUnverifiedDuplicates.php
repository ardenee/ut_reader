<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility helpers for unverified duplicate cleanup.
 * Why: Existing pages keep their procedural API while duplicate cleanup and queue storage remain namespaced.
 * Role: Thin legacy facade over application/infrastructure services.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Unverified\UnverifiedDuplicateCleanupService;
use UnrealDb\Catalog\Infrastructure\Composition\CatalogServiceFactory;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;

/** @return list<array<string,mixed>> */
function catalog_unverified_duplicate_queues(PDO $db): array
{
    $queues = [CatalogUnverifiedQueueStorage::bucketGame()];
    foreach (catalog_all($db, 'SELECT id,name,slug,profile_id FROM ue_games ORDER BY name') as $game) {
        $queues[] = $game;
    }
    return $queues;
}

function catalog_unverified_duplicate_service(
    PDO $db,
    array $config
): UnverifiedDuplicateCleanupService {
    (new CatalogUnverifiedStagingIndex($db, $config))->ensureSchema();
    return (new CatalogServiceFactory($db, $config))->unverifiedDuplicateCleanup();
}

/** @return array{physical_files:int,hashed_files:int,groups:list<array<string,mixed>>,duplicate_groups:int,duplicate_files:int,duplicate_bytes:int} */
function catalog_unverified_duplicate_scan(PDO $db, array $config): array
{
    return catalog_unverified_duplicate_service($db, $config)->scan();
}

/** @return array<string,mixed> */
function catalog_unverified_delete_duplicates(PDO $db, array $config): array
{
    $result = catalog_unverified_duplicate_service($db, $config)->deleteDuplicates();
    return [
        'physical_files' => (int)$result['physical_files'],
        'hashed_files' => (int)$result['hashed_files'],
        'duplicate_groups' => (int)$result['duplicate_groups'],
        'duplicate_files_found' => (int)$result['duplicate_files_found'],
        'deleted_files' => (int)$result['deleted_files'],
        'deleted_bytes' => (int)$result['deleted_bytes'],
        'deleted_bytes_text' => catalog_bytes((int)$result['deleted_bytes']),
        'deleted' => (array)$result['deleted'],
        'deleted_list_truncated' => !empty($result['deleted_list_truncated']),
        'errors' => (array)$result['errors'],
    ];
}
