<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogFederationHistoryPageService` for catalog federation history page
 *          service.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Federation;

use PDO;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/**
 * Runs bounded, context-bound cursor pages for federation history tables.
 *
 * Query fragments are supplied only by trusted application code. Every sort
 * tuple must finish with a unique key, normally the table's id column.
 */
final class CatalogFederationHistoryPageService
{
    public const DEFAULT_PAGE_SIZE = 100;

    public static function normalizePageSize(int $value): int
    {
        return in_array($value, [50, 100, 250, 500], true) ? $value : self::DEFAULT_PAGE_SIZE;
    }

    public static function normalizeMove(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['first', 'next', 'previous', 'last'], true) ? $value : 'first';
    }

    /**
     * @param array<string,mixed> $config
     * @param list<mixed> $args
     * @param list<string> $sortColumns SQL expressions used by ORDER BY and cursor comparison.
     * @param list<string> $cursorKeys Selected row keys containing the corresponding visible values.
     * @param list<string> $directions
     * @return array{rows:list<array<string,mixed>>,page_size:int,has_previous:bool,has_next:bool,previous_cursor:string,next_cursor:string,move:string}
     */
    public static function fetch(
        PDO $db,
        array $config,
        string $context,
        string $selectFromSql,
        string $whereSql,
        array $args,
        array $sortColumns,
        array $cursorKeys,
        array $directions,
        int $pageSize,
        string $cursor,
        string $move
    ): array {
        $pageSize = self::normalizePageSize($pageSize);
        $move = self::normalizeMove($move);
        $context .= '|page_size=' . $pageSize;

        if ($sortColumns === [] || count($sortColumns) !== count($cursorKeys) || count($sortColumns) !== count($directions)) {
            throw new \InvalidArgumentException('Federation history cursor tuple is invalid.');
        }

        $cursorValues = $cursor !== ''
            ? CatalogKeysetPaginator::decode($config, $context, $cursor)
            : null;
        if ($cursorValues === null || count($cursorValues) !== count($sortColumns)) {
            $cursorValues = null;
            if (in_array($move, ['next', 'previous'], true)) {
                $move = 'first';
            }
        }

        $reverse = in_array($move, ['previous', 'last'], true);
        $clauses = [];
        if (trim($whereSql) !== '') {
            $clauses[] = '(' . $whereSql . ')';
        }
        if ($cursorValues !== null && in_array($move, ['next', 'previous'], true)) {
            $comparison = CatalogKeysetPaginator::comparison(
                $sortColumns,
                $directions,
                $cursorValues,
                $move === 'next'
            );
            $clauses[] = $comparison['sql'];
            $args = array_merge($args, $comparison['args']);
        }

        $sql = $selectFromSql
            . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
            . ' ORDER BY ' . CatalogKeysetPaginator::order($sortColumns, $directions, $reverse)
            . ' LIMIT ' . ($pageSize + 1);
        $statement = $db->prepare($sql);
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];

        $hasExtra = count($rows) > $pageSize;
        if ($hasExtra) {
            array_pop($rows);
        }
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        $hasPrevious = match ($move) {
            'next' => $rows !== [],
            'previous', 'last' => $hasExtra,
            default => false,
        };
        $hasNext = match ($move) {
            'previous' => $rows !== [],
            'next', 'first' => $hasExtra,
            default => false,
        };

        $previousCursor = '';
        $nextCursor = '';
        if ($rows !== []) {
            $previousCursor = CatalogKeysetPaginator::encode(
                $config,
                $context,
                self::values($rows[0], $cursorKeys)
            );
            $nextCursor = CatalogKeysetPaginator::encode(
                $config,
                $context,
                self::values($rows[count($rows) - 1], $cursorKeys)
            );
        }

        return [
            'rows' => array_values($rows),
            'page_size' => $pageSize,
            'has_previous' => $hasPrevious,
            'has_next' => $hasNext,
            'previous_cursor' => $previousCursor,
            'next_cursor' => $nextCursor,
            'move' => $move,
        ];
    }

    /** @param array<string,mixed> $row @param list<string> $keys @return list<mixed> */
    private static function values(array $row, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \RuntimeException('Federation history query omitted cursor value: ' . $key);
            }
            $values[] = $row[$key];
        }
        return $values;
    }
}
