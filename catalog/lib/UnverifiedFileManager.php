<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for physical unverified-queue storage helpers.
 * Why: Existing procedural callers retain stable uvf_* signatures while queue storage/inventory lives under src/.
 * Role: Transitional legacy facade; new code should use CatalogUnverifiedQueueStorage directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;

function uvf_base64url_encode(string $value): string
{
    return CatalogUnverifiedQueueStorage::base64UrlEncode($value);
}

function uvf_base64url_decode(string $value): ?string
{
    return CatalogUnverifiedQueueStorage::base64UrlDecode($value);
}

/** @return array<string,mixed> */
function uvf_bucket_game(): array
{
    return CatalogUnverifiedQueueStorage::bucketGame();
}

function uvf_storage_root(array $config): string
{
    return CatalogUnverifiedQueueStorage::storageRoot($config);
}

function uvf_storage_games_root(array $config): string
{
    return CatalogUnverifiedQueueStorage::storageGamesRoot($config);
}

function uvf_upload_bucket_dir(array $config, bool $create = false): string
{
    return CatalogUnverifiedQueueStorage::uploadBucketDirectory($config, $create);
}

function uvf_unverified_dir(array $config, array $game, bool $create = false): string
{
    return CatalogUnverifiedQueueStorage::unverifiedDirectory($config, $game, $create);
}

function uvf_path_inside(string $path, string $root): bool
{
    return CatalogUnverifiedQueueStorage::pathInside($path, $root);
}

function uvf_original_name_from_queue_name(string $queueName): string
{
    return CatalogUnverifiedQueueStorage::originalNameFromQueueName($queueName);
}

function uvf_token(int $gameId, string $queueName): string
{
    return CatalogUnverifiedQueueStorage::token($gameId, $queueName);
}

function uvf_safe_queue_name(string $originalName): string
{
    return CatalogUnverifiedQueueStorage::safeQueueName($originalName);
}

/** @return array{queue_name:string,original_name:string,size:int,path:string} */
function uvf_store_bucket_upload(array $config, string $tmp, string $originalName, string $reason): array
{
    return CatalogUnverifiedQueueStorage::storeBucketUpload(
        $config,
        $tmp,
        $originalName,
        $reason
    );
}

/** @return array{md5:string,package_guid:string} */
function uvf_identity(array $config, string $path, string $originalName, array $legacy): array
{
    return CatalogUnverifiedQueueStorage::identity($config, $path, $originalName, $legacy);
}

/** @return array<string,mixed> */
function uvf_resolve(PDO $db, array $config, string $token): array
{
    return (new CatalogUnverifiedQueueStorage($db, $config))->resolve($token);
}

/** @return list<array<string,mixed>> */
function uvf_list(PDO $db, array $config, ?int $sourceGameId = null): array
{
    return (new CatalogUnverifiedQueueStorage($db, $config))->list($sourceGameId);
}

/**
 * @param list<string> $packageNames
 * @return array<string,list<array{game_id:int,game_name:string,owner_count:int,import_count:int}>>
 */
function uvf_reference_matches(PDO $db, array $packageNames): array
{
    // referenceMatches only needs the database; storage config is unused.
    return (new CatalogUnverifiedQueueStorage($db, []))->referenceMatches($packageNames);
}

function uvf_unique_destination(string $directory, string $name): string
{
    return CatalogUnverifiedQueueStorage::uniqueDestination($directory, $name);
}
