<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Federation;

use PDO;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

/** Loads parent/child federation inventory pages from compact package summaries. */
final class CatalogFederationInventoryListService
{
    /** @var array<int,bool> */
    private static array $examplePathAvailability = [];

    /** @return array{required:int,missing:int} */
    public static function parentCounts(PDO $db, int $peerId, bool $ignoreBaseGame): array
    {
        $base = self::parentBaseSql($ignoreBaseGame);
        $row = \catalog_one(
            $db,
            'SELECT COUNT(*) missing_count,COALESCE(SUM(inventory.needed_by_parent_files>0),0) required_count '
            . 'FROM (' . $base . ') inventory',
            [$peerId]
        ) ?? [];

        return [
            'required' => (int)($row['required_count'] ?? 0),
            'missing' => (int)($row['missing_count'] ?? 0),
        ];
    }

    /**
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public static function parentCursorPage(
        PDO $db,
        int $peerId,
        string $tab,
        bool $ignoreBaseGame,
        int $limit,
        ?array $cursor,
        string $move = 'first'
    ): array {
        $limit = max(1, min($limit, 500));
        $tab = $tab === 'required' ? 'required' : 'missing';
        $move = self::move($move);
        $reverse = in_array($move, ['prev', 'last'], true);
        $columns = ['inventory.needed_by_parent_files', 'inventory.display_game', 'inventory.package_name', 'inventory.original_name', 'inventory.id'];
        $directions = ['DESC', 'ASC', 'ASC', 'ASC', 'ASC'];
        $where = $tab === 'required' ? ' WHERE inventory.needed_by_parent_files>0' : '';
        $args = [$peerId];
        if ($cursor !== null && in_array($move, ['next', 'prev'], true)) {
            $comparison = CatalogKeysetPaginator::comparison($columns, $directions, $cursor, $move === 'next');
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . $comparison['sql'];
            $args = array_merge($args, $comparison['args']);
        }

        $rows = \catalog_all(
            $db,
            'SELECT inventory.* FROM (' . self::parentBaseSql($ignoreBaseGame) . ') inventory'
            . $where
            . ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
            . ' LIMIT ' . ($limit + 1),
            $args
        );

        return self::finishPage($rows, $limit, $reverse, static fn(array $row): array => [
            (int)$row['needed_by_parent_files'],
            (string)$row['display_game'],
            (string)$row['package_name'],
            (string)$row['original_name'],
            (int)$row['id'],
        ], $move);
    }

    public static function childMissingTotal(PDO $db, bool $ignoreBaseGame): int
    {
        return (int)(\catalog_one(
            $db,
            'SELECT COUNT(*) c FROM (' . self::childNeedsSql($db, $ignoreBaseGame) . ') needs'
        )['c'] ?? 0);
    }

    /**
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    public static function childCursorPage(
        PDO $db,
        int $peerId,
        bool $ignoreBaseGame,
        int $limit,
        ?array $cursor,
        string $move = 'first'
    ): array {
        $limit = max(1, min($limit, 1000));
        $move = self::move($move);
        $reverse = in_array($move, ['prev', 'last'], true);
        $columns = ['inventory.game_name', 'inventory.use_count', 'inventory.required_package', 'inventory.game_id'];
        $directions = ['ASC', 'DESC', 'ASC', 'ASC'];
        $where = '';
        $args = [$peerId];
        if ($cursor !== null && in_array($move, ['next', 'prev'], true)) {
            $comparison = CatalogKeysetPaginator::comparison($columns, $directions, $cursor, $move === 'next');
            $where = ' WHERE ' . $comparison['sql'];
            $args = array_merge($args, $comparison['args']);
        }

        $peerPolicy = $ignoreBaseGame ? ' AND COALESCE(pf.is_base_game,0)=0' : '';
        $rows = \catalog_all(
            $db,
            'SELECT inventory.* FROM ('
            . 'SELECT needs.game_id,needs.game_name,needs.engine_key,needs.required_package,'
            . 'needs.required_object_path,needs.object_count,needs.use_count,needs.is_base_game,'
            . 'MAX(CASE WHEN pf.id IS NOT NULL THEN 1 ELSE 0 END) parent_available,'
            . 'MAX(pf.id) parent_peer_file_id,MAX(pf.original_name) parent_file,MAX(pf.file_size) parent_file_size '
            . 'FROM (' . self::childNeedsSql($db, $ignoreBaseGame) . ') needs '
            . 'LEFT JOIN ue_federation_peer_files pf ON pf.peer_id=? '
            . 'AND LOWER(TRIM(pf.package_name))=LOWER(TRIM(needs.required_package)) '
            . 'AND (pf.game_id=needs.game_id OR pf.remote_game_name=needs.game_name)'
            . $peerPolicy . ' '
            . 'GROUP BY needs.game_id,needs.game_name,needs.engine_key,needs.required_package,'
            . 'needs.required_object_path,needs.object_count,needs.use_count,needs.is_base_game'
            . ') inventory'
            . $where
            . ' ORDER BY ' . CatalogKeysetPaginator::order($columns, $directions, $reverse)
            . ' LIMIT ' . ($limit + 1),
            $args
        );

        return self::finishPage($rows, $limit, $reverse, static fn(array $row): array => [
            (string)$row['game_name'],
            (int)$row['use_count'],
            (string)$row['required_package'],
            (int)$row['game_id'],
        ], $move);
    }

    private static function parentBaseSql(bool $ignoreBaseGame): string
    {
        $policy = $ignoreBaseGame ? ' AND COALESCE(pf.is_base_game,0)=0' : '';
        return 'SELECT pf.*,COALESCE(NULLIF(pf.remote_game_name,""),g.name,"") display_game,'
            . 'COALESCE(need.needed_by_parent_files,0) needed_by_parent_files '
            . 'FROM ue_federation_peer_files pf '
            . 'LEFT JOIN ue_games g ON g.id=pf.game_id '
            . 'LEFT JOIN ('
            . 'SELECT s.game_id,ng.name game_name,LOWER(TRIM(s.required_package)) package_key,'
            . 'COUNT(*) needed_by_parent_files '
            . 'FROM ue_dependency_package_summaries s '
            . 'JOIN ue_files needer ON needer.id=s.file_id AND needer.scan_status="verified" '
            . 'JOIN ue_games ng ON ng.id=s.game_id '
            . 'WHERE s.missing_count>0 '
            . 'GROUP BY s.game_id,ng.name,LOWER(TRIM(s.required_package))'
            . ') need ON need.package_key=LOWER(TRIM(pf.package_name)) '
            . 'AND ((COALESCE(pf.remote_game_name,"")<>"" AND need.game_name=pf.remote_game_name) '
            . 'OR (COALESCE(pf.remote_game_name,"")="" AND pf.game_id IS NOT NULL AND need.game_id=pf.game_id)) '
            . 'WHERE pf.peer_id=? '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM ue_files local WHERE local.scan_status="verified" '
            . 'AND ((COALESCE(pf.package_guid,"")<>"" AND local.package_guid=pf.package_guid) '
            . 'OR (COALESCE(pf.md5,"")<>"" AND local.md5=pf.md5))'
            . ')' . $policy;
    }

    private static function childNeedsSql(PDO $db, bool $ignoreBaseGame): string
    {
        $baseGameSql = \federation_base_game_package_exists_sql('s.required_package', 's.game_id');
        $policy = $ignoreBaseGame ? ' AND NOT (' . $baseGameSql . ')' : '';
        $examplePath = self::hasExamplePathColumn($db)
            ? 'MIN(COALESCE(s.example_required_object_path,""))'
            : '""';
        return 'SELECT s.game_id,g.name game_name,COALESCE(gp.engine_key,"") engine_key,s.required_package,'
            . $examplePath . ' required_object_path,'
            . 'SUM(s.missing_count) object_count,COUNT(*) use_count,'
            . 'MAX(CASE WHEN ' . $baseGameSql . ' THEN 1 ELSE 0 END) is_base_game '
            . 'FROM ue_dependency_package_summaries s '
            . 'JOIN ue_files f ON f.id=s.file_id AND f.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles gp ON gp.id=g.profile_id AND gp.is_active=1 '
            . 'WHERE s.missing_count>0 AND s.required_package<>""' . $policy . ' '
            . 'GROUP BY s.game_id,g.name,gp.engine_key,s.required_package';
    }

    private static function hasExamplePathColumn(PDO $db): bool
    {
        $connectionId = spl_object_id($db);
        if (array_key_exists($connectionId, self::$examplePathAvailability)) {
            return self::$examplePathAvailability[$connectionId];
        }
        try {
            $statement = $db->query(
                'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() '
                . 'AND table_name="ue_dependency_package_summaries" '
                . 'AND column_name="example_required_object_path" LIMIT 1'
            );
            self::$examplePathAvailability[$connectionId] = $statement !== false && $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            self::$examplePathAvailability[$connectionId] = false;
        }
        return self::$examplePathAvailability[$connectionId];
    }

    private static function move(string $move): string
    {
        return in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):list<mixed> $cursorValues
     * @return array{rows:list<array<string,mixed>>,first_cursor:?array,last_cursor:?array,has_previous:bool,has_next:bool}
     */
    private static function finishPage(array $rows, int $limit, bool $reverse, callable $cursorValues, string $move): array
    {
        $hasExtra = count($rows) > $limit;
        if ($hasExtra) {
            $rows = array_slice($rows, 0, $limit);
        }
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        return [
            'rows' => $rows,
            'first_cursor' => $rows !== [] ? $cursorValues($rows[0]) : null,
            'last_cursor' => $rows !== [] ? $cursorValues($rows[count($rows) - 1]) : null,
            'has_previous' => $move === 'next' || (($move === 'prev' || $move === 'last') && $hasExtra),
            'has_next' => $move === 'prev' || (($move === 'first' || $move === 'next') && $hasExtra),
        ];
    }
}
