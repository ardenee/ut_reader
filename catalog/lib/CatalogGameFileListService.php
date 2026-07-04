<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

/**
 * Produces one page of game files with dependency summaries.
 *
 * For ordinary column sorts, the legacy implementation grouped every matching
 * file with every dependency before pagination. This service finds the page's
 * IDs first, then aggregates dependency counts only for those visible files.
 * Dependency-count sorting retains the grouped query because the aggregate is
 * part of the requested ordering.
 */
final class CatalogGameFileListService
{
    /**
     * @param list<mixed> $whereArgs
     * @return list<array<string, mixed>>
     */
    public static function fetchPage(
        PDO $db,
        string $whereSql,
        array $whereArgs,
        string $sort,
        string $direction,
        int $limit,
        int $offset
    ): array {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        if ($sort === 'deps') {
            return self::fetchDependencySortedPage($db, $whereSql, $whereArgs, $direction, $limit, $offset);
        }

        $orderSql = self::orderSql($sort, $direction);
        $idRows = catalog_all(
            $db,
            'SELECT f.id FROM ue_files f ' . $whereSql
            . ' ORDER BY ' . $orderSql
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $whereArgs
        );
        if ($idRows === []) {
            return [];
        }

        $fileIds = array_map(static fn(array $row): int => (int)$row['id'], $idRows);
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $rows = catalog_all(
            $db,
            'SELECT '
            . 'f.id, f.package_name, f.original_name, f.package_guid, f.md5, f.extension, '
            . 'f.package_version, f.licensee_version, f.file_size, f.is_compressed, '
            . "COALESCE(SUM(d.status='resolved'),0) resolved_count, "
            . "COALESCE(SUM(d.status='missing'),0) missing_count, "
            . "COALESCE(SUM(d.status='package_only'),0) package_only_count, "
            . "COALESCE(SUM(d.status='common'),0) common_count "
            . 'FROM ue_files f '
            . 'LEFT JOIN ue_dependencies d ON d.file_id=f.id '
            . 'WHERE f.id IN (' . $placeholders . ') '
            . 'GROUP BY f.id',
            $fileIds
        );

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int)$row['id']] = $row;
        }

        $orderedRows = [];
        foreach ($fileIds as $fileId) {
            if (isset($rowsById[$fileId])) {
                $orderedRows[] = $rowsById[$fileId];
            }
        }

        return $orderedRows;
    }

    /**
     * @param list<mixed> $whereArgs
     * @return list<array<string, mixed>>
     */
    private static function fetchDependencySortedPage(
        PDO $db,
        string $whereSql,
        array $whereArgs,
        string $direction,
        int $limit,
        int $offset
    ): array {
        return catalog_all(
            $db,
            'SELECT '
            . 'f.id, f.package_name, f.original_name, f.package_guid, f.md5, f.extension, '
            . 'f.package_version, f.licensee_version, f.file_size, f.is_compressed, '
            . "COALESCE(SUM(d.status='resolved'),0) resolved_count, "
            . "COALESCE(SUM(d.status='missing'),0) missing_count, "
            . "COALESCE(SUM(d.status='package_only'),0) package_only_count, "
            . "COALESCE(SUM(d.status='common'),0) common_count "
            . 'FROM ue_files f '
            . 'LEFT JOIN ue_dependencies d ON d.file_id=f.id '
            . $whereSql . ' '
            . 'GROUP BY f.id '
            . 'ORDER BY missing_count ' . $direction . ', f.package_name ASC, f.original_name ASC '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            $whereArgs
        );
    }

    private static function orderSql(string $sort, string $direction): string
    {
        $column = match ($sort) {
            'file' => 'f.original_name',
            'version' => 'f.package_version',
            'size' => 'f.file_size',
            'compression' => 'f.is_compressed',
            'uploaded' => 'f.uploaded_at',
            default => 'f.package_name',
        };

        return $column . ' ' . $direction . ', f.package_name ASC, f.original_name ASC';
    }
}
