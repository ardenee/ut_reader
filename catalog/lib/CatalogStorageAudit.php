<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for verified-storage audit helpers.
 * Why: Existing pages retain storage_audit_* signatures while traversal and queue mutation live under src/.
 * Role: Transitional legacy facade over CatalogStorageAuditService.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/Scanner/CatalogScannerPath.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogStorageAuditService;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;

function storage_audit_storage_root(array $config): string
{
    return CatalogUnverifiedQueueStorage::storageRoot($config);
}

function storage_audit_verified_dir(array $config, array $game): string
{
    return CatalogUnverifiedQueueStorage::storageRoot($config)
        . DIRECTORY_SEPARATOR . 'games'
        . DIRECTORY_SEPARATOR . scanner_slug_text((string)($game['slug'] ?? ''))
        . DIRECTORY_SEPARATOR . 'verified';
}

function storage_audit_db_relative_path(string $storageRoot, string $physicalPath): string
{
    return CatalogStorageAuditService::databaseRelativePath($storageRoot, $physicalPath);
}

function storage_audit_normalize_relative(string $relative): string
{
    return CatalogStorageAuditService::normalizeRelative($relative);
}

function storage_audit_inside(string $path, string $root): bool
{
    return CatalogStorageAuditService::pathInside($path, $root);
}

function storage_audit_token(int $gameId, string $relativePath): string
{
    return CatalogStorageAuditService::token($gameId, $relativePath);
}

/** @return array{game:array<string,mixed>,path:string,relative_path:string,storage_relative_path:string,original_name:string,size:int,md5:string} */
function storage_audit_resolve_orphan(PDO $db, array $config, string $token): array
{
    return (new CatalogStorageAuditService($db, $config))->resolveOrphan($token);
}

/** @return array{original_name:string,game_name:string} */
function storage_audit_queue_orphan(PDO $db, array $config, string $token): array
{
    return (new CatalogStorageAuditService($db, $config))->queueOrphan($token);
}

/** @return array{games:list<array<string,mixed>>,orphans:list<array<string,mixed>>,missing_catalog:list<array<string,mixed>>,scanned_files:int} */
function storage_audit_run(PDO $db, array $config, ?int $gameId = null): array
{
    return (new CatalogStorageAuditService($db, $config))->run($gameId);
}
