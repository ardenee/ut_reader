<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical base-game protection helper API for existing callers.
 * Why: Protection policy, lookups, schema verification and seeding now live in CatalogBaseGameProtectionService.
 * Role: Thin compatibility/presentation facade; do not add base-game persistence or policy implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogBaseGameProtectionService;

function base_game_ensure(PDO $db): void
{
    (new CatalogBaseGameProtectionService($db))->ensureSchema();
}

function base_game_normalize_guid(string $guid): string
{
    return CatalogBaseGameProtectionService::normalizeGuid($guid);
}

function base_game_guid_is_usable(string $guid): bool
{
    return CatalogBaseGameProtectionService::guidIsUsable($guid);
}

function base_game_package_exists_sql(string $packageSql, ?string $gameIdSql = null): string
{
    return CatalogBaseGameProtectionService::packageExistsSql($packageSql, $gameIdSql);
}

function base_game_dependency_is_official_sql(string $fileAlias = 'f', string $dependencyAlias = 'd'): string
{
    return CatalogBaseGameProtectionService::dependencyIsOfficialSql($fileAlias, $dependencyAlias);
}

/** @return array<string,mixed>|null */
function base_game_lookup(PDO $db, int $gameId, string $packageGuid): ?array
{
    return (new CatalogBaseGameProtectionService($db))->lookup($gameId, $packageGuid);
}

/** @param array<string,mixed> $file */
function base_game_file_is_protected(PDO $db, array $file): bool
{
    return (new CatalogBaseGameProtectionService($db))->fileIsProtected($file);
}

/** @return array{file:array<string,mixed>,base:array<string,mixed>}|null */
function base_game_file_protection(PDO $db, int $fileId): ?array
{
    return (new CatalogBaseGameProtectionService($db))->fileProtection($fileId);
}

/** @param array<string,mixed>|null $file */
function base_game_block_message(?array $file = null): string
{
    return CatalogBaseGameProtectionService::blockMessage($file);
}

/** @param array<string,mixed>|null $file */
function base_game_block_html(?array $file = null): string
{
    return '<div class="card"><h1>Download blocked</h1><p>'
        . catalog_h(CatalogBaseGameProtectionService::blockMessage($file))
        . '</p></div>';
}

/** @param array<string,mixed> $file */
function base_game_require_transfer_allowed(PDO $db, array $file, bool $dependencyException = false): void
{
    (new CatalogBaseGameProtectionService($db))->requireTransferAllowed($file, $dependencyException);
}

/** @return array{scanned:int,inserted:int,updated:int} */
function base_game_seed_from_current_files(PDO $db, int $gameId, ?int $userId = null): array
{
    return (new CatalogBaseGameProtectionService($db))->seedFromCurrentFiles($gameId, $userId);
}
