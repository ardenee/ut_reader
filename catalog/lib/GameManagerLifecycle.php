<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for Game Manager reset/delete lifecycle helpers.
 * Why: Existing gm_lifecycle_* callers keep stable function names while implementation lives under src/Infrastructure/Games.
 * Role: Transitional procedural facade only; no lifecycle SQL, filesystem traversal or progress implementation belongs here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogGameLifecycleService;
use UnrealDb\Catalog\Infrastructure\Games\CatalogGameStorageCleanup;
use UnrealDb\Catalog\Infrastructure\Games\PdoCatalogGameTableMaintenance;

function gm_lifecycle_table_exists(PDO $db, string $table): bool
{
    return (new PdoCatalogGameTableMaintenance($db))->exists($table);
}

/** @return list<string> */
function gm_lifecycle_optimise_table_list(bool $deleteGame): array
{
    return PdoCatalogGameTableMaintenance::tableList($deleteGame);
}

/**
 * @param list<string> $tables
 * @param null|callable(array<string,mixed>):void $progress
 * @return array{optimised:list<string>,failed:array<string,string>}
 */
function gm_lifecycle_optimise_tables(
    PDO $db,
    array $tables,
    ?callable $progress,
    int $startPercent,
    int $endPercent
): array {
    return (new PdoCatalogGameTableMaintenance($db))->optimiseTables(
        $tables,
        $progress,
        $startPercent,
        $endPercent
    );
}

/** @return list<array{id:int,relative_path:string,file_size:int}> */
function gm_lifecycle_unverified_rows(PDO $db, int $gameId): array
{
    return CatalogGameLifecycleService::readUnverifiedRows($db, $gameId);
}

/** @param list<array{id:int,relative_path:string,file_size:int}> $rows */
function gm_lifecycle_remove_staged_storage(array $config, array $rows): int
{
    return (new CatalogGameStorageCleanup($config))->removeStagedRows($rows);
}

/**
 * @param null|callable(array<string,mixed>):void $progress
 * @return array<string,mixed>
 */
function gm_lifecycle_cleanup_game(
    PDO $db,
    array $config,
    int $gameId,
    ?callable $progress,
    int $startPercent,
    int $endPercent
): array {
    return (new CatalogGameLifecycleService($db, $config))->cleanup(
        $gameId,
        $progress,
        $startPercent,
        $endPercent
    );
}

/**
 * @param null|callable(array<string,mixed>):void $progress
 * @return array<string,mixed>
 */
function gm_lifecycle_reset_game(
    PDO $db,
    array $config,
    int $gameId,
    ?callable $progress = null
): array {
    return (new CatalogGameLifecycleService($db, $config))->reset($gameId, $progress);
}

/**
 * @param null|callable(array<string,mixed>):void $progress
 * @return array<string,mixed>
 */
function gm_lifecycle_delete_game(
    PDO $db,
    array $config,
    int $gameId,
    ?callable $progress = null
): array {
    return (new CatalogGameLifecycleService($db, $config))->delete($gameId, $progress);
}
