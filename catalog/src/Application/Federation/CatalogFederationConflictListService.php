<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogFederationConflictListService` for catalog federation conflict list
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

/** Reads bounded federation identity-conflict pages while preserving exact identity comparisons. */
final class CatalogFederationConflictListService
{
    /** @return array{sql:string,args:list<mixed>} */
    public static function filter(int $peerId, bool $ignoreBaseGame): array
    {
        $where = [];
        $args = [];
        if ($peerId > 0) {
            $where[] = 'pf.peer_id=?';
            $args[] = $peerId;
        }
        if ($ignoreBaseGame) {
            $where[] = 'COALESCE(pf.is_base_game,0)=0';
        }
        return ['sql' => implode(' AND ', $where), 'args' => $args];
    }

    /** @return array{sql:string,args:list<mixed>} */
    public static function countQuery(int $peerId, bool $ignoreBaseGame): array
    {
        $filter = self::filter($peerId, $ignoreBaseGame);
        $sql = 'SELECT COUNT(*) c' . self::fromSql();
        if ($filter['sql'] !== '') {
            $sql .= ' AND ' . $filter['sql'];
        }
        return ['sql' => $sql, 'args' => $filter['args']];
    }

    public static function count(PDO $db, int $peerId, bool $ignoreBaseGame): int
    {
        $query = self::countQuery($peerId, $ignoreBaseGame);
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
        $filter = self::filter($peerId, $ignoreBaseGame);
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
            . self::fromSql();
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

    private static function fromSql(): string
    {
        return ' FROM ue_federation_peer_files pf'
            . ' JOIN ue_federation_peers p ON p.id=pf.peer_id'
            . ' JOIN ue_files f ON f.scan_status="verified" AND ('
            . '(COALESCE(pf.package_guid,"")<>"" AND f.package_guid=pf.package_guid '
            . 'AND COALESCE(pf.md5,"")<>"" AND f.md5<>pf.md5)'
            . ' OR '
            . '(COALESCE(pf.md5,"")<>"" AND f.md5=pf.md5 AND ('
            . 'COALESCE(f.package_guid,"")<>COALESCE(pf.package_guid,"") OR f.file_size<>pf.file_size))'
            . ') WHERE 1=1';
    }
}
