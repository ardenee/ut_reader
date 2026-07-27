<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;

/**
 * Finds dependency owners whose exact package/object resolution can change after
 * a package is imported. This application service contains invalidation rules
 * only; the scanner remains responsible for rebuilding the returned files.
 */
final class CatalogAffectedDependencyRefreshService
{
    /**
     * @return list<int>
     */
    public static function findAffectedFileIds(PDO $db, int $gameId, int $newFileId, string $packageName): array
    {
        if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
            return [];
        }

        $fileIds = [];

        /*
         * Any import rooted at the newly available package may now resolve by
         * exact primary package identity, exact package-alias identity, or the
         * corresponding exact object identity. This is an invalidation query
         * only; the resolver does not use fuzzy object-name matches, inferred
         * package variants, or folder guesses.
         */
        self::collectFileIds(
            $db,
            'SELECT DISTINCT d.file_id'
            . ' FROM ue_dependencies d'
            . ' JOIN ue_files f ON f.id=d.file_id'
            . ' WHERE d.required_package=? AND d.file_id<>?'
            . ' AND f.game_id=? AND f.scan_status="verified"'
            . ' ORDER BY d.file_id',
            [$packageName, $newFileId, $gameId],
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
