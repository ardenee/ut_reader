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
    private const MAX_PATHS_PER_QUERY = 500;

    /**
     * @return list<int>
     */
    public static function findAffectedFileIds(PDO $db, int $gameId, int $newFileId, string $packageName): array
    {
        $fileIds = [];

        self::collectFileIds(
            $db,
            'SELECT DISTINCT d.file_id'
            . ' FROM ue_dependencies d'
            . ' JOIN ue_files f ON f.id=d.file_id'
            . ' WHERE f.game_id=? AND d.file_id<>? AND d.required_package=? AND d.required_object_path=?',
            [$gameId, $newFileId, $packageName, ''],
            $fileIds
        );

        $exportRows = \catalog_all($db, 'SELECT full_path FROM ue_exports WHERE file_id=?', [$newFileId]);
        $paths = array_values(array_unique(
            array_map(static fn(array $row): string => (string)$row['full_path'], $exportRows),
            SORT_STRING
        ));

        foreach (array_chunk($paths, self::MAX_PATHS_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            self::collectFileIds(
                $db,
                'SELECT DISTINCT d.file_id'
                . ' FROM ue_dependencies d'
                . ' JOIN ue_files f ON f.id=d.file_id'
                . ' WHERE f.game_id=? AND d.file_id<>? AND d.required_package=?'
                . ' AND d.required_object_path IN (' . $placeholders . ')',
                array_merge([$gameId, $newFileId, $packageName], $chunk),
                $fileIds
            );
        }

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
