<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;

/**
 * Finds dependency owners whose resolution can change after a new package is
 * imported. This application service contains invalidation rules only; the
 * scanner remains responsible for rebuilding the returned files.
 */
final class CatalogAffectedDependencyRefreshService
{
    /**
     * @return list<int>
     */
    public static function findAffectedFileIds(PDO $db, int $gameId, int $newFileId, string $packageName): array
    {
        $fileIds = [];

        /*
         * Every import rooted at this package can change state when the package
         * becomes available: an object may resolve to an Export, or it may at
         * least become package_only. Do not limit this to paths currently in
         * the new file's Export table.
         */
        self::collectFileIds(
            $db,
            'SELECT DISTINCT d.file_id'
            . ' FROM ue_dependencies d'
            . ' JOIN ue_files f ON f.id=d.file_id'
            . ' WHERE f.game_id=? AND d.file_id<>? AND d.required_package=?',
            [$gameId, $newFileId, $packageName],
            $fileIds
        );

        return array_map('intval', array_keys($fileIds));
    }

    /**
     * @param list<mixed> $args
     * @param array<int, true> $fileIds
     */
    private static function collectFileIds(PDO $db, string $sql, array $args, array &$fileIds): void
    {
        foreach (\catalog_all($db, $sql, $args) as $row) {
            $fileIds[(int)$row['file_id']] = true;
        }
    }
}
