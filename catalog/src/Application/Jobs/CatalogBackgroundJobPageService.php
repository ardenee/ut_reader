<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/** Reads stable background-job pages without discarding earlier rows with OFFSET. */
final class CatalogBackgroundJobPageService
{
    /**
     * @param list<mixed> $params
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array}
     */
    public static function fetch(
        PDO $db,
        string $selectSql,
        string $whereSql,
        array $params,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $limit = max(1, min(1000, $limit));
        $move = strtolower(trim($move));
        if ($move === 'prev') {
            $move = 'previous';
        }
        if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
            $move = 'first';
        }

        $reverse = $move === 'previous' || $move === 'last';
        $conditions = trim($whereSql);
        $args = $params;

        // A reversed LIMIT normally returns a full final window. When the total
        // is not divisible by the selected page size that would overlap the
        // preceding page. Count only for an explicit Last request and reduce
        // the read to the exact remainder.
        if ($move === 'last') {
            $countSql = 'SELECT COUNT(*) FROM (' . $selectSql
                . ($conditions !== '' ? ' WHERE ' . $conditions : '')
                . ') background_job_cursor_count';
            $count = $db->prepare($countSql);
            $count->execute($args);
            $total = (int)$count->fetchColumn();
            $remainder = $total % $limit;
            if ($remainder > 0) {
                $limit = $remainder;
            }
        }

        if ($cursor !== null && ($move === 'next' || $move === 'previous')) {
            $comparison = CatalogKeysetPaginator::comparison(['j.id'], ['DESC'], $cursor, $move === 'next');
            $conditions = $conditions === '' ? $comparison['sql'] : '(' . $conditions . ') AND ' . $comparison['sql'];
            array_push($args, ...$comparison['args']);
        }

        $sql = $selectSql;
        if ($conditions !== '') {
            $sql .= ' WHERE ' . $conditions;
        }
        $sql .= ' ORDER BY ' . CatalogKeysetPaginator::order(['j.id'], ['DESC'], $reverse)
            . ' LIMIT ' . ($limit + 1);

        $statement = $db->prepare($sql);
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasExtra = count($rows) > $limit;
        if ($hasExtra) {
            array_pop($rows);
        }
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        $first = $rows !== [] ? [(int)$rows[0]['id']] : null;
        $last = $rows !== [] ? [(int)$rows[count($rows) - 1]['id']] : null;

        return [
            'rows' => $rows,
            'has_previous' => match ($move) {
                'first' => false,
                'next' => $rows !== [],
                'previous' => $hasExtra,
                'last' => $hasExtra,
                default => false,
            },
            'has_next' => match ($move) {
                'first' => $hasExtra,
                'next' => $hasExtra,
                'previous' => $rows !== [],
                'last' => false,
                default => false,
            },
            'first_cursor' => $first,
            'last_cursor' => $last,
        ];
    }
}
