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
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogZenStaticDependencyLookup;

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

    /**
     * Direct bounded page selection for numbered links and a Go-to-page form.
     * The ordinary adjacent-page route stays keyset based (no offsets).
     *
     * @param list<mixed> $whereArgs
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public function fetchNumberedPage(
        string $whereSql,
        array $whereArgs,
        string $sort,
        string $direction,
        int $limit,
        int $pageNumber
    ): array {
        $limit = max(1, min(500, $limit));
        $pageNumber = max(1, $pageNumber);
        $offset = ($pageNumber - 1) * $limit;
        [$columns, $directions] = self::sortDefinition($sort, $direction);
        $order = CatalogKeysetPaginator::order($columns, $directions);
        $fetchLimit = $limit + 1;
        if ($sort === 'deps') {
            $sql = 'SELECT f.id,f.package_name,f.original_name,'
                . 'COALESCE(SUM(s.unresolved_count+s.common_count),0) unresolved_count '
                . 'FROM ue_files f LEFT JOIN ue_uedb5_dependency_packages s ON s.file_id=f.id '
                . 'AND ' . Uedb5DependencyPublicationScope::currentOwnerPredicate('s') . ' '
                . $whereSql . ' GROUP BY f.id,f.package_name,f.original_name'
                . ' ORDER BY ' . $order . ' LIMIT ' . $fetchLimit . ' OFFSET ' . $offset;
        } else {
            $sql = 'SELECT f.id FROM ue_files f ' . $whereSql
                . ' ORDER BY ' . $order . ' LIMIT ' . $fetchLimit . ' OFFSET ' . $offset;
        }
        $idRows = \catalog_all($this->db, $sql, $whereArgs);
        $hasNext = count($idRows) > $limit;
        if ($hasNext) {
            $idRows = array_slice($idRows, 0, $limit);
        }
        $rows = $this->hydrateRows(array_map(static fn(array $r): int => (int)$r['id'], $idRows));
        return [
            'rows' => $rows,
            'first_cursor' => $rows !== [] ? self::cursorValues($rows[0], $sort) : null,
            'last_cursor' => $rows !== [] ? self::cursorValues($rows[count($rows) - 1], $sort) : null,
            'has_previous' => $pageNumber > 1,
            'has_next' => $hasNext,
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
            'SELECT f.id,f.package_name,f.original_name,COALESCE(SUM(s.unresolved_count+s.common_count),0) unresolved_count '
            . 'FROM ue_files f LEFT JOIN ue_uedb5_dependency_packages s ON s.file_id=f.id '
            . ' AND ' . Uedb5DependencyPublicationScope::currentOwnerPredicate('s') . ' '
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
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.package_guid,f.md5,f.sha1,f.extension,'
            . 'f.package_version,f.licensee_version,f.file_size,f.is_compressed,f.uploaded_at,'
            . '(SELECT HEX(z.package_id) FROM ue_iostore_packages z WHERE z.file_id=f.id AND z.chunk_index=0 LIMIT 1) zen_package_id,'
            . '(SELECT z.container_id FROM ue_iostore_packages z WHERE z.file_id=f.id AND z.chunk_index=0 LIMIT 1) zen_container_id,'
            . '(SELECT m.status FROM ue_uedb5_migration_status m WHERE m.file_id=f.id LIMIT 1) v5_stage_status,'
            . '(CASE WHEN ' . Uedb5DependencyPublicationScope::currentFilePredicate('f')
            . ' THEN 1 ELSE 0 END) dependency_projection_current,'
            . 'COALESCE(SUM(s.resolved_count),0) resolved_count,'
            . 'COALESCE(SUM(s.missing_count),0) missing_count,'
            . 'COALESCE(SUM(s.package_only_count),0) package_only_count,'
            . 'COALESCE(SUM(s.common_count),0) common_count,'
            . 'COALESCE(SUM(s.unresolved_count+s.common_count),0) unresolved_count '
            . 'FROM ue_files f LEFT JOIN ue_uedb5_dependency_packages s ON s.file_id=f.id '
            . ' AND ' . Uedb5DependencyPublicationScope::currentOwnerPredicate('s') . ' '
            . 'WHERE f.id IN (' . $placeholders . ') GROUP BY f.id',
            $fileIds
        );

        $rowsById = [];
        foreach ($rows as $row) {
            $row['source_unresolved_count'] = (int)($row['unresolved_count'] ?? 0);
            $rowsById[(int)$row['id']] = $row;
        }

        // Package-summary rows exclude Zen ScriptImport identities. For Zen
        // show every currently published unresolved dependency edge, not just
        // the package subset, so script-only packages are not shown as "none".
        $zenIds = [];
        foreach ($rowsById as $fileId => $row) {
            if (strtolower((string)($row['extension'] ?? '')) === 'zenpkg'
                && (int)($row['dependency_projection_current'] ?? 0) === 1) {
                $zenIds[] = (int)$fileId;
                $rowsById[$fileId]['unresolved_count'] = 0;
                $rowsById[$fileId]['static_resolved_count'] = 0;
                $rowsById[$fileId]['static_script_count'] = 0;
                $rowsById[$fileId]['static_package_found_count'] = 0;
            }
        }
        if ($zenIds !== []) {
            $zenPlaceholders = implode(',', array_fill(0, count($zenIds), '?'));
            $unresolvedRows = \catalog_all($this->db,
                'SELECT file_id,COUNT(*) unresolved '
                . 'FROM ue_uedb5_dependency_edges '
                . 'WHERE file_id IN (' . $zenPlaceholders . ') AND outcome=4 GROUP BY file_id',
                $zenIds
            );
            foreach ($unresolvedRows as $entry) {
                $id = (int)$entry['file_id'];
                if (isset($rowsById[$id])) {
                    $rowsById[$id]['unresolved_count'] = (int)$entry['unresolved'];
                    $rowsById[$id]['source_unresolved_count'] = (int)$entry['unresolved'];
                }
            }

            // The Epic-analog dependency projection deliberately records
            // unknown mount selection. UnrealDB's *catalogue* question is
            // simpler: does a verified file actually provide the exact source
            // identity? Match it without reading files or simulating a game.
            $candidateRows = catalog_all($this->db,
                'SELECT file_id,source_kind,source_index,required_package_key_kind,'
                . 'HEX(required_package_key) package_key,required_object_key_kind,'
                . 'HEX(required_object_key) object_key '
                . 'FROM ue_uedb5_dependency_edges '
                . 'WHERE file_id IN (' . $zenPlaceholders . ') AND outcome=4 '
                . 'AND (required_object_key_kind IN (2,3) '
                . 'OR required_package_key_kind=2)',
                $zenIds
            );
            $gameId = (int)($rowsById[$zenIds[0]]['game_id'] ?? 0);
            $static = (new CatalogZenStaticDependencyLookup($this->db))
                ->forEdges($gameId, $candidateRows);
            foreach ($candidateRows as $edge) {
                $id = (int)$edge['file_id'];
                $key = $id . ':' . (int)$edge['source_kind'] . ':' . (int)$edge['source_index'];
                $state = (string)($static[$key]['state'] ?? '');
                if ($state === 'resolved_script' || $state === 'resolved_export') {
                    $rowsById[$id]['static_resolved_count']++;
                    if ($state === 'resolved_script') $rowsById[$id]['static_script_count']++;
                    $rowsById[$id]['unresolved_count'] = max(0,
                        (int)$rowsById[$id]['unresolved_count'] - 1);
                } elseif ($state === 'package_found') {
                    $rowsById[$id]['static_package_found_count']++;
                    $rowsById[$id]['unresolved_count'] = max(0,
                        (int)$rowsById[$id]['unresolved_count'] - 1);
                }
            }
            foreach ($zenIds as $id) {
                $rowsById[$id]['resolved_count'] += $rowsById[$id]['static_resolved_count'];
                $rowsById[$id]['package_only_count'] += $rowsById[$id]['static_package_found_count'];
            }
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
            'licensee' => [['f.licensee_version', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'size' => [['f.file_size', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'compression' => [['f.is_compressed', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'uploaded' => [['f.uploaded_at', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            'deps' => [['unresolved_count', 'f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC', 'ASC']],
            default => [['f.package_name', 'f.original_name', 'f.id'], [$direction, 'ASC', 'ASC']],
        };
    }

    /** @param array<string,mixed> $row @return list<mixed> */
    private static function cursorValues(array $row, string $sort): array
    {
        return match ($sort) {
            'file' => [(string)$row['original_name'], (string)$row['package_name'], (int)$row['id']],
            'version' => [(int)$row['package_version'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'licensee' => [(int)$row['licensee_version'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'size' => [(int)$row['file_size'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'compression' => [(int)$row['is_compressed'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'uploaded' => [(string)$row['uploaded_at'], (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            'deps' => [(int)($row['source_unresolved_count'] ?? $row['unresolved_count']), (string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
            default => [(string)$row['package_name'], (string)$row['original_name'], (int)$row['id']],
        };
    }
}
