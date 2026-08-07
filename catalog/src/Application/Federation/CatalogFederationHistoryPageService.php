<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines pure pagination policy for bounded federation history views.
 * Why: Cursor validation, movement, ordering and page-window semantics belong in Application without owning PDO.
 * Role: Application-layer pagination policy shared by federation pages and APIs.
 * Audit: Keep database execution outside this class; Infrastructure adapters consume the generated query plan.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Federation;

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

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
     * @param list<string> $sortColumns
     * @param list<string> $cursorKeys
     * @param list<string> $directions
     * @return array{page_size:int,move:string,context:string,where_sql:string,args:list<mixed>,order_sql:string,reverse:bool,cursor_keys:list<string>}
     */
    public static function plan(
        array $config,
        string $context,
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

        return [
  'page_size' => $pageSize,
  'move' => $move,
  'context' => $context,
  'where_sql' => implode(' AND ', $clauses),
  'args' => $args,
  'order_sql' => CatalogKeysetPaginator::order($sortColumns, $directions, $reverse),
  'reverse' => $reverse,
  'cursor_keys' => $cursorKeys,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array{page_size:int,move:string,context:string,where_sql:string,args:list<mixed>,order_sql:string,reverse:bool,cursor_keys:list<string>} $plan
     * @param list<array<string,mixed>> $rows
     * @return array{rows:list<array<string,mixed>>,page_size:int,has_previous:bool,has_next:bool,previous_cursor:string,next_cursor:string,move:string}
     */
    public static function finish(array $config, array $plan, array $rows): array
    {
        $pageSize = $plan['page_size'];
        $move = $plan['move'];
        $reverse = $plan['reverse'];

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
      $plan['context'],
      self::values($rows[0], $plan['cursor_keys'])
  );
  $nextCursor = CatalogKeysetPaginator::encode(
      $config,
      $plan['context'],
      self::values($rows[count($rows) - 1], $plan['cursor_keys'])
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
