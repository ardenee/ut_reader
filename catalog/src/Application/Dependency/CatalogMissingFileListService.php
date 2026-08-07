<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogMissingFileListService` for catalog missing file list service.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/** Loads cursor pages for files that currently have missing dependencies. */
final class CatalogMissingFileListService
{
    /**
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public static function fetchCursorPage(
        PDO $db,
        bool $summaryAvailable,
        int $limit,
        ?array $cursor,
        string $move = 'first'
    ): array {
        $limit = max(1, min($limit, 500));
        $move = in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
        $reverse = in_array($move, ['prev', 'last'], true);
        $columns = ['missing_object_rows', 'missing_package_count', 'g.name', 'f.package_name', 'f.original_name', 'f.id'];
        $directions = ['DESC', 'DESC', 'ASC', 'ASC', 'ASC', 'ASC'];

        $having = '';
        $args = [];
        if ($cursor !== null && in_array($move, ['next', 'prev'], true)) {
            $comparison = CatalogKeysetPaginator::comparison(
                $columns,
                $directions,
                $cursor,
                $move === 'next'
            );
            $having = ' HAVING ' . $comparison['sql'];
            $args = $comparison['args'];
        }

        $fetchLimit = $limit + 1;
        if ($summaryAvailable) {
            $rows = \catalog_all(
                $db,
                'SELECT f.id file_id,f.package_name,f.original_name,g.id game_id,g.name game_name,'
                . 'SUM(s.missing_count) missing_object_rows,COUNT(*) missing_package_count,'
                . 'GROUP_CONCAT(s.required_package ORDER BY s.required_package SEPARATOR ", ") missing_package_names '
                . 'FROM ue_dependency_package_summaries s '
                . 'JOIN ue_files f ON f.id=s.file_id JOIN ue_games g ON g.id=s.game_id '
                . 'WHERE s.missing_count>0 GROUP BY f.id,f.package_name,f.original_name,g.id,g.name'
                . $having . ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
                . ' LIMIT ' . $fetchLimit,
                $args
            );
        } else {
            $dependencySource = CatalogDependencyReadSource::sql($db);
            $rows = \catalog_all(
                $db,
                'SELECT f.id file_id,f.package_name,f.original_name,g.id game_id,g.name game_name,'
                . 'COUNT(d.id) missing_object_rows,COUNT(DISTINCT d.required_package) missing_package_count,'
                . 'GROUP_CONCAT(DISTINCT d.required_package ORDER BY d.required_package SEPARATOR ", ") missing_package_names '
                . 'FROM ' . $dependencySource . ' d '
                . 'JOIN ue_files f ON f.id=d.file_id JOIN ue_games g ON g.id=f.game_id '
                . 'WHERE d.status="missing" GROUP BY f.id,f.package_name,f.original_name,g.id,g.name'
                . $having . ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
                . ' LIMIT ' . $fetchLimit,
                $args
            );
        }

        $hasExtra = count($rows) > $limit;
        if ($hasExtra) {
            $rows = array_slice($rows, 0, $limit);
        }
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        return [
            'rows' => $rows,
            'first_cursor' => $rows !== [] ? self::cursorValues($rows[0]) : null,
            'last_cursor' => $rows !== [] ? self::cursorValues($rows[count($rows) - 1]) : null,
            'has_previous' => $move === 'next' || (($move === 'prev' || $move === 'last') && $hasExtra),
            'has_next' => $move === 'prev' || (($move === 'first' || $move === 'next') && $hasExtra),
        ];
    }

    /** @param array<string,mixed> $row @return list<mixed> */
    private static function cursorValues(array $row): array
    {
        return [
            (int)$row['missing_object_rows'],
            (int)$row['missing_package_count'],
            (string)$row['game_name'],
            (string)$row['package_name'],
            (string)$row['original_name'],
            (int)$row['file_id'],
        ];
    }
}
