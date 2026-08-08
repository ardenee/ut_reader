<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for database-backed unverified staging.
 * Why: Existing procedural callers retain stable function signatures while parsing/persistence lives under src/.
 * Role: Transitional legacy facade; new code should use CatalogUnverifiedStagingIndex and PdoUnverifiedGameMatchQuery.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchQuery;

function catalog_unverified_schema_ensure(PDO $db): void
{
    (new CatalogUnverifiedStagingIndex($db))->ensureSchema();
}

function catalog_unverified_queue_key(int $queueGameId, string $queueName): string
{
    return CatalogUnverifiedStagingIndex::queueKey($queueGameId, $queueName);
}

function catalog_unverified_storage_relative(array $config, string $path): string
{
    return CatalogUnverifiedStagingIndex::storageRelative($config, $path);
}

/** @return array{path:string,name:string,temporary:bool,source_name:string} */
function catalog_unverified_prepare_path(string $path, string $originalName): array
{
    return CatalogUnverifiedStagingIndex::preparePath($path, $originalName);
}

/** @return array{0:string,1:array<string,mixed>} */
function catalog_unverified_detect_engine(string $path, string $name): array
{
    return CatalogUnverifiedStagingIndex::detectEngine($path, $name);
}

/**
 * @return array{header:array<string,mixed>,names:array<int,mixed>,imports:array<int,mixed>,exports:array<int,mixed>,notes:list<string>}
 */
function catalog_unverified_parse(
    PDO $db,
    array $config,
    string $path,
    string $name,
    int $queueGameId,
    string $sourceRelativePath
): array {
    return (new CatalogUnverifiedStagingIndex($db, $config))->parse(
        $path,
        $name,
        $queueGameId,
        $sourceRelativePath
    );
}

/** @return array<string,mixed>|null */
function catalog_unverified_find(PDO $db, int $queueGameId, string $queueName): ?array
{
    return (new CatalogUnverifiedStagingIndex($db))->find($queueGameId, $queueName);
}

/** @return array{status:string,file_id:int,message:string,parse_error:?string} */
function catalog_unverified_index_path(
    PDO $db,
    array $config,
    int $queueGameId,
    string $queueName,
    string $path,
    string $originalName,
    string $reason = '',
    ?int $uploadedBy = null,
    string $sourceRelativePath = '',
    bool $force = false
): array {
    return (new CatalogUnverifiedStagingIndex($db, $config))->indexPath(
        $queueGameId,
        $queueName,
        $path,
        $originalName,
        $reason,
        $uploadedBy,
        $sourceRelativePath,
        $force
    );
}

/**
 * @param array<string,mixed> $item
 * @return array{status:string,file_id:int,message:string,parse_error:?string}
 */
function catalog_unverified_index_item(
    PDO $db,
    array $config,
    array $item,
    ?int $uploadedBy = null,
    bool $force = false
): array {
    return (new CatalogUnverifiedStagingIndex($db, $config))->indexItem(
        $item,
        $uploadedBy,
        $force
    );
}

function catalog_unverified_delete_database_row(PDO $db, int $queueGameId, string $queueName): void
{
    (new CatalogUnverifiedStagingIndex($db))->deleteDatabaseRow($queueGameId, $queueName);
}

/** @param array<string,mixed> $sourceItem */
function catalog_unverified_update_queue(
    PDO $db,
    array $config,
    array $sourceItem,
    int $newQueueGameId,
    string $newQueueName,
    string $newPath
): void {
    (new CatalogUnverifiedStagingIndex($db, $config))->updateQueue(
        $sourceItem,
        $newQueueGameId,
        $newQueueName,
        $newPath
    );
}

/**
 * Historical single-file entry point. New code should use
 * CatalogUnverifiedGameMatches.php or PdoUnverifiedGameMatchQuery directly.
 *
 * @return list<array<string,mixed>>
 */
function catalog_unverified_game_matches(PDO $db, int $fileId): array
{
    return (new PdoUnverifiedGameMatchQuery($db))->one($fileId);
}
