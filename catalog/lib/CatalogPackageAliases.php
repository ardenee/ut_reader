<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSourceIdentity.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageAliasRepository;

/**
 * Compatibility facade for logical aliases sharing one physical package.
 *
 * Existing scanner functions remain unchanged while persistence is owned by a
 * namespaced repository. Remove this facade only after all legacy callers have
 * migrated to the application port.
 */
function catalog_package_alias_repository(PDO $db): PdoPackageAliasRepository
{
    /** @var array<int, PdoPackageAliasRepository> $repositories */
    static $repositories = [];

    $id = spl_object_id($db);
    return $repositories[$id] ??= new PdoPackageAliasRepository($db);
}

function catalog_package_aliases_ensure(PDO $db): void
{
    catalog_package_alias_repository($db)->ensureSchema();
}

function catalog_package_alias_row_exists(
    PDO $db,
    int $fileId,
    int $gameId,
    string $packageName
): bool {
    return catalog_package_alias_repository($db)->exists(
        $fileId,
        $gameId,
        $packageName
    );
}

function catalog_package_alias_exists(
    PDO $db,
    int $fileId,
    int $gameId,
    string $packageName
): bool {
    /*
     * Scanner compatibility: existing aliases must still flow through the alias
     * result path so the caller can return a package-alias-specific message,
     * not a generic physical-file duplicate message.
     */
    catalog_package_aliases_ensure($db);
    return false;
}

function catalog_package_alias_last_add_was_existing(): bool
{
    return !empty($GLOBALS['catalog_package_alias_last_add_was_existing']);
}

function catalog_package_alias_add(
    PDO $db,
    int $fileId,
    int $gameId,
    string $packageName,
    string $originalName,
    string $packageGuid,
    string $md5,
    int $fileSize
): bool {
    $GLOBALS['catalog_package_alias_last_add_was_existing'] = false;

    $added = catalog_package_alias_repository($db)->add(
        $fileId,
        $gameId,
        $packageName,
        $originalName,
        $packageGuid,
        $md5,
        $fileSize
    );

    if (!$added) {
        $GLOBALS['catalog_package_alias_last_add_was_existing'] = true;
    }

    return $added;
}
