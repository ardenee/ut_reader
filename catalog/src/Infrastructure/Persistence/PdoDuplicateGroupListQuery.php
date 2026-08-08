<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads filtered/paginated active GUID duplicate groups for the administrator duplicate manager.
 * Why: Large duplicate detection/count/list SQL should have one persistence owner instead of living in Presentation.
 * Role: Infrastructure read model; no mutation or rendering.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoDuplicateGroupListQuery
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all($this->db, 'SELECT id, name FROM ue_games ORDER BY name');
    }

    /**
     * @return array{
     *   game_id:int,total_rows:int,total_groups:int,total_pages:int,page:int,offset:int,
     *   rows:list<array<string,mixed>>,groups:array<string,array<string,mixed>>
     * }
     */
    public function fetch(
        int $gameId,
        string $query,
        string $typeFilter,
        string $compressionFilter,
        int $limit,
        int $page
    ): array {
        $games = $this->games();
        $knownGameIds = array_map(static fn(array $game): int => (int)$game['id'], $games);
        if ($gameId > 0 && !in_array($gameId, $knownGameIds, true)) {
            $gameId = 0;
        }

        $duplicateGroupSql = 'SELECT game_id, package_guid, COUNT(*) duplicate_count FROM ue_files '
            . 'WHERE package_guid IS NOT NULL AND package_guid<>"" '
            . 'AND REPLACE(package_guid,"-","")<>REPEAT("0",32) '
            . 'AND scan_status="verified" GROUP BY game_id, package_guid HAVING COUNT(*) > 1';
        $where = 'WHERE f.scan_status="verified"';
        $args = [];

        if ($gameId > 0) {
            $where .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        if ($query !== '') {
            $where .= ' AND (g.name LIKE ? OR f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?'
                . (ctype_digit($query) ? ' OR f.id=?' : '') . ')';
            $like = '%' . $query . '%';
            array_push($args, $like, $like, $like, $like, $like, $like);
            if (ctype_digit($query)) {
                $args[] = (int)$query;
            }
        }

        $typeExts = self::extensionsForType($typeFilter);
        if ($typeExts !== []) {
            $where .= ' AND f.extension IN (' . implode(',', array_fill(0, count($typeExts), '?')) . ')';
            array_push($args, ...$typeExts);
        }
        if ($compressionFilter === 'compressed') {
            $where .= ' AND f.is_compressed=1';
        } elseif ($compressionFilter === 'uncompressed') {
            $where .= ' AND f.is_compressed=0';
        }

        $countSql = 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN (' . $duplicateGroupSql . ') grp ON grp.game_id=f.game_id AND grp.package_guid=f.package_guid ' . $where;
        $totalRows = (int)(\catalog_one($this->db, 'SELECT COUNT(*) c ' . $countSql, $args)['c'] ?? 0);
        $totalGroups = (int)(\catalog_one($this->db, 'SELECT COUNT(DISTINCT f.game_id, f.package_guid) c ' . $countSql, $args)['c'] ?? 0);
        $totalPages = max(1, (int)ceil($totalRows / $limit));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $limit;

        $rows = \catalog_all($this->db, '
            SELECT f.id,f.game_id,g.name AS game_name,f.package_guid,grp.duplicate_count,
                   f.package_name,f.original_name,f.md5,f.sha1,f.extension,f.file_size,f.is_compressed,
                   f.uploaded_at,f.package_version,f.licensee_version,
                   COALESCE(f.name_count,0) AS name_count,
                   COALESCE(f.import_count,0) AS import_count,
                   COALESCE(f.export_count,0) AS export_count,
                   COALESCE(l.source_location_count,0) AS source_location_count
            FROM ue_files f
            JOIN ue_games g ON g.id=f.game_id
            JOIN (' . $duplicateGroupSql . ') grp ON grp.game_id=f.game_id AND grp.package_guid=f.package_guid
            LEFT JOIN (
                SELECT file_id,COUNT(*) AS source_location_count
                FROM ue_file_locations
                WHERE exists_in_source=1
                GROUP BY file_id
            ) l ON l.file_id=f.id
            ' . $where . '
            ORDER BY g.name,f.package_guid,f.is_compressed ASC,f.file_size DESC,f.uploaded_at ASC,f.id ASC
            LIMIT ' . $limit . ' OFFSET ' . $offset,
            $args
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = (int)$row['game_id'] . ':' . (string)$row['package_guid'];
            $groups[$key]['game_name'] = (string)$row['game_name'];
            $groups[$key]['package_guid'] = (string)$row['package_guid'];
            $groups[$key]['duplicate_count'] = (int)$row['duplicate_count'];
            $groups[$key]['rows'][] = $row;
        }

        return [
            'game_id' => $gameId,
            'total_rows' => $totalRows,
            'total_groups' => $totalGroups,
            'total_pages' => $totalPages,
            'page' => $page,
            'offset' => $offset,
            'rows' => $rows,
            'groups' => $groups,
        ];
    }

    /** @return list<string> */
    private static function extensionsForType(string $type): array
    {
        $map = [
            'map' => ['unr', 'un2', 'ut2', 'ut3', 'umap'],
            'music' => ['umx'],
            'sound' => ['uax'],
            'texture' => ['utx'],
            'static_mesh' => ['usx'],
            'animation' => ['ukx'],
            'particle_effect' => ['upx'],
            'gui' => ['ugx'],
            'content' => ['con'],
            'package' => ['u', 'upk', 'uasset'],
        ];
        return $map[$type] ?? [];
    }
}
