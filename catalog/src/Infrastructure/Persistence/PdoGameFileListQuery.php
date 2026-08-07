<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes the game-file list read model and keyset pagination queries through PDO.
 * Why: Game-file ordering, dependency aggregation, identity hydration and cursor queries are persistence concerns shared by the game-files page.
 * Role: Infrastructure read-model adapter for the game-files catalog view.
 * Audit: Preserve sort, keyset, dependency aggregation and hydration semantics; move higher-level filter criteria out of SQL in a later pass.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/** Loads stable cursor pages of a game's file list and dependency summaries. */
final class PdoGameFileListQuery
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param list<mixed> $whereArgs
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public function fetchCursorPage(
        string $whereSql,
        array $whereArgs,
        string $sort,
        string $direction,
        int $limit,
        ?array $cursor,
        string $move = 'first'
    ): array {
        $limit = max(1, min($limit, 500));
        $move = in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
        $reverse = in_array($move, ['prev', 'last'], true);
        [$columns, $directions] = self::sortDefinition($sort, $direction);

        $cursorSql = '';
        $cursorArgs = [];
        if ($cursor !== null && in_array($move, ['next', 'prev'], true)) {
            $comparison = CatalogKeysetPaginator::comparison(
                $columns,
                $directions,
                $cursor,
                $move === 'next'
            );
            $cursorSql = $comparison['sql'];
            $cursorArgs = $comparison['args'];
        }

        $fetchLimit = $limit + 1;
        if ($sort === 'deps') {
            $idRows = $this->dependencySortedIds(
                $whereSql,
                $whereArgs,
                $cursorSql,
                $cursorArgs,
                $columns,
                $directions,
                $reverse,
                $fetchLimit
            );
        } else {
            $idRows = $this->ordinarySortedIds(
                $whereSql,
                $whereArgs,
                $cursorSql,
                $cursorArgs,
                $columns,
                $directions,
                $reverse,
                $fetchLimit
            );
        }

        $hasExtra = count($idRows) > $limit;
        if ($hasExtra) {
            $idRows = array_slice($idRows, 0, $limit);
        }
        if ($reverse) {
            $idRows = array_reverse($idRows);
        }

        $fileIds = array_map(static fn(array $row): int => (int)$row['id'], $idRows);
        $rows = $this->hydrateRows($fileIds);
        $first = $rows !== [] ? self::cursorValues($rows[0], $sort) : null;
        $last = $rows !== [] ? self::cursorValues($rows[count($rows) - 1], $sort) : null;

        return [
            'rows' => $rows,
            'first_cursor' => $first,
            'last_cursor' => $last,
            'has_previous' => $move === 'next' || (($move === 'prev' || $move === 'last') && $hasExtra),
            'has_next' => $move === 'prev' || (($move === 'first' || $move === 'next') && $hasExtra),
        ];
    }

    /** @param list<mixed> $whereArgs @param list<mixed> $cursorArgs @param list<string> $columns @param list<string> $directions */
    private function ordinarySortedIds(
        string $whereSql,
        array $whereArgs,
        string $cursorSql,
        array $cursorArgs,
        array $columns,
        array $directions,
        bool $reverse,
        int $limit
    ): array {
        $cursorClause = $cursorSql !== '' ? ' AND ' . $cursorSql : '';
        return \catalog_all(
            $this->db,
            'SELECT f.id FROM ue_files f ' . $whereSql . $cursorClause
            . ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
            . ' LIMIT ' . $limit,
            array_merge($whereArgs, $cursorArgs)
        );
    }

    /** @param list<mixed> $whereArgs @param list<mixed> $cursorArgs @param list<string> $columns @param list<string> $directions */
    private function dependencySortedIds(
        string $whereSql,
        array $whereArgs,
        string $cursorSql,
        array $cursorArgs,
        array $columns,
        array $directions,
        bool $reverse,
        int $limit
    ): array {
        $having = $cursorSql !== '' ? ' HAVING ' . $cursorSql : '';
        return \catalog_all(
            $this->db,
            'SELECT f.id,f.package_name,f.original_name,COALESCE(SUM(s.missing_count),0) missing_count '
            . 'FROM ue_files f LEFT JOIN ue_dependency_package_summaries s ON s.file_id=f.id '
            . $whereSql . ' GROUP BY f.id,f.package_name,f.original_name' . $having
            . ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
            . ' LIMIT ' . $limit,
            array_merge($whereArgs, $cursorArgs)
        );
    }

    /** @param list<int> $fileIds @return list<array<string,mixed>> */
    private function hydrateRows(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $rows = \catalog_all(
            $this->db,
            'SELECT f.id,f.package_name,f.original_name,f.package_guid,f.md5,f.sha1,f.extension,'
            . 'f.package_version,f.licensee_version,f.file_size,f.is_compressed,f.uploaded_at,'
            . 'COALESCE(SUM(s.resolved_count),0) resolved_count,'
            . 'COALESCE(SUM(s.missing_count),0) missing_count,'
            . 'COALESCE(SUM(s.package_only_count),0) package_only_count,'
            . 'COALESCE(SUM(s.common_count),0) common_count '
            . 'FROM ue_files f LEFT JOIN ue_dependency_package_summaries s ON s.file_id=f.id '
            . 'WHERE f.id IN (' . $placeholders . ') GROUP BY f.id',
            $fileIds
        );

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int)$row['id']] = $row;
        }

        $ordered = [];
        foreach ($fileIds as $fileId) {
            if (isset($rowsById[$fileId])) {
                $ordered[] = $rowsById[$fileId];
            }
        }
        return $ordered;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private static function sortDefinition(string $sort, string $direction): array
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        return match ($sort) {
            'file' => [['f.original_name', 'f.package_name', 'f.id'], [$direction, 'ASC', 'ASC']],
            'version' => [['f.package_version', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'size' => [['f.file_size', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'compression' => [['f.is_compressed', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'uploaded' => [['f.uploaded_at', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'deps' => [['missing_count', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            default => [['f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC']],
        };
    }

    /** @param array<string,mixed> $row @return list<mixed> */
    private static function cursorValues(array $row, string $sort): array
    {
        return match ($sort) {
            'file' => [(string)$row['original_name'], (string)$row['package_name'], (int)$row['id']],
            'version' => [(int)$row['package_version'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'size' => [(int)$row['file_size'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'compression' => [(int)$row['is_compressed'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'uploaded' => [(string)$row['uploaded_at'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'deps' => [(int)$row['missing_count'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            default => [(string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
        };
    }
}
