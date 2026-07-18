<?php
declare(strict_types=1);

require_once __DIR__ . '/UnverifiedFileManager.php';
require_once __DIR__ . '/CatalogUnverifiedIndex.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Unverified\UnverifiedDuplicateCleanupService;
use UnrealDb\Catalog\Infrastructure\Filesystem\NativeUnverifiedFileSystem;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUnverifiedRecordStore;
use UnrealDb\Catalog\Infrastructure\Unverified\LegacyUnverifiedQueueInventory;

/** @return list<array<string,mixed>> */
function catalog_unverified_duplicate_queues(PDO $db): array
{
    $queues = [uvf_bucket_game()];
    foreach (catalog_all($db, 'SELECT id,name,slug,profile_id FROM ue_games ORDER BY name') as $game) {
        $queues[] = $game;
    }
    return $queues;
}

function catalog_unverified_duplicate_service(
    PDO $db,
    array $config
): UnverifiedDuplicateCleanupService {
    catalog_unverified_schema_ensure($db);

    return new UnverifiedDuplicateCleanupService(
        new LegacyUnverifiedQueueInventory($db, $config),
        new PdoUnverifiedRecordStore($db),
        new NativeUnverifiedFileSystem()
    );
}

/**
 * Inventory all physical unverified queues and identify exact duplicate groups.
 * Files are grouped by size first, so MD5 is calculated only for size collisions.
 *
 * @return array{physical_files:int,hashed_files:int,groups:list<array<string,mixed>>,duplicate_groups:int,duplicate_files:int,duplicate_bytes:int}
 */
function catalog_unverified_duplicate_scan(PDO $db, array $config): array
{
    return catalog_unverified_duplicate_service($db, $config)->scan();
}

/**
 * Delete all duplicate physical queue files while retaining one copy per size+MD5 group.
 * An indexed copy is retained when available; otherwise the oldest queue copy is retained.
 *
 * @return array<string,mixed>
 */
function catalog_unverified_delete_duplicates(PDO $db, array $config): array
{
    $result = catalog_unverified_duplicate_service($db, $config)->deleteDuplicates();
    $result['deleted_bytes_text'] = catalog_bytes((int)$result['deleted_bytes']);

    return $result;
}
