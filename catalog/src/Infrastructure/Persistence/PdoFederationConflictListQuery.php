<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes bounded federation identity-conflict list/count queries through PDO.
 * Why: Application owns the conflict query specification while database execution and keyset paging belong in Infrastructure.
 * Role: PDO read adapter used by federation diagnostics.
 * Audit: Preserve the exact identity comparisons and cursor ordering when changing this adapter.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Federation\CatalogFederationConflictListService;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

final class PdoFederationConflictListQuery
{
    public static function count(PDO $db, int $peerId, bool $ignoreBaseGame): int
    {
        $query = CatalogFederationConflictListService::countQuery($peerId, $ignoreBaseGame);
        $statement = $db->prepare($query['sql']);
        $statement->execute($query['args']);
        return (int)$statement->fetchColumn();
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public static function fetch(
        PDO $db,
        int $peerId,
        bool $ignoreBaseGame,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $limit = max(1, min(500, $limit));
        $move = strtolower(trim($move));
        if ($move === 'prev') {
            $move = 'previous';
        }
        if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
            $move = 'first';
        }

        if ($move === 'last') {
            $total = self::count($db, $peerId, $ignoreBaseGame);
            $remainder = $total % $limit;
            if ($remainder > 0) {
                $limit = $remainder;
            }
        }

        $columns = ['p.id', 'pf.package_name', 'pf.original_name', 'pf.id', 'f.id'];
        $directions = ['ASC', 'ASC', 'ASC', 'ASC', 'ASC'];
        $reverse = $move === 'previous' || $move === 'last';
        $filter = CatalogFederationConflictListService::filter($peerId, $ignoreBaseGame);
        $where = $filter['sql'];
        $args = $filter['args'];
        if ($cursor !== null && ($move === 'next' || $move === 'previous')) {
            $comparison = CatalogKeysetPaginator::comparison($columns, $directions, $cursor, $move === 'next');
            $where = $where === '' ? $comparison['sql'] : '(' . $where . ') AND ' . $comparison['sql'];
            array_push($args, ...$comparison['args']);
        }

        $sql = 'SELECT pf.*,pf.id peer_file_id,p.id peer_id,p.site_name peer_name,'
            . 'f.id local_id,f.original_name local_file,f.package_guid local_guid,'
            . 'f.md5 local_md5,f.sha1 local_sha1,f.file_size local_size'
            . CatalogFederationConflictListService::fromSql();
        if ($where !== '') {
            $sql .= ' AND ' . $where;
        }
        $sql .= ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
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

        $cursorValues = static fn(array $row): array => [
            (int)$row['peer_id'],
            (string)$row['package_name'],
            (string)$row['original_name'],
            (int)$row['peer_file_id'],
            (int)$row['local_id'],
        ];

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
            'first_cursor' => $rows !== [] ? $cursorValues($rows[0]) : null,
            'last_cursor' => $rows !== [] ? $cursorValues($rows[count($rows) - 1]) : null,
        ];
    }
}
