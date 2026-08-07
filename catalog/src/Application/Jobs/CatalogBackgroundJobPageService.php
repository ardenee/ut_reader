<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the background-job keyset pagination policy used by cursor-based job listings.
 * Why: Pagination rules belong in Application while database execution remains an Infrastructure concern.
 * Role: Pure application service for move normalization, cursor comparisons, ordering, final-page sizing, and page metadata.
 * Audit: Keep PDO and database execution out of this class; persistence adapters may depend on this policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/** Defines stable background-job cursor pagination without owning persistence. */
final class CatalogBackgroundJobPageService
{
    /** @return array{limit:int,move:string,reverse:bool} */
    public static function window(int $limit, string $move): array
    {
        $limit = max(1, min(1000, $limit));
        $move = strtolower(trim($move));
        if ($move === 'prev') {
            $move = 'previous';
        }
        if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
            $move = 'first';
        }

        return [
            'limit' => $limit,
            'move' => $move,
            'reverse' => $move === 'previous' || $move === 'last',
        ];
    }

    public static function lastPageLimit(int $limit, int $total, string $move): int
    {
        if ($move !== 'last') {
            return $limit;
        }

        $remainder = $total % $limit;
        return $remainder > 0 ? $remainder : $limit;
    }

    /** @param list<mixed>|null $cursor @return array{sql:string,args:list<mixed>}|null */
    public static function cursorComparison(?array $cursor, string $move): ?array
    {
        if ($cursor === null || ($move !== 'next' && $move !== 'previous')) {
            return null;
        }

        return CatalogKeysetPaginator::comparison(['j.id'], ['DESC'], $cursor, $move === 'next');
    }

    public static function order(bool $reverse): string
    {
        return CatalogKeysetPaginator::order(['j.id'], ['DESC'], $reverse);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array}
     */
    public static function finish(array $rows, int $limit, string $move, bool $reverse): array
    {
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
            'rows' => array_values($rows),
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
