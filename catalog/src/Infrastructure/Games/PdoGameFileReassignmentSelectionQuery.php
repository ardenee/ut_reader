<?php
/**
 * Bounded selector for moving verified files out of one game.
 *
 * The browser may display only a small cursor page. Bulk reassignment snapshots
 * the current filtered verified set and walks it by stable file id so selecting
 * all matching files never requires rendering or posting every row.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;

final class PdoGameFileReassignmentSelectionQuery
{
    private const TYPE_EXTENSIONS = [
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

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $filters @return array{total:int,max_id:int,filters:array<string,mixed>} */
    public function snapshot(int $gameId, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        [$whereSql, $args] = $this->where($gameId, $filters);
        $statement = $this->db->prepare(
            'SELECT COUNT(*) c,COALESCE(MAX(f.id),0) max_id FROM ue_files f WHERE ' . $whereSql
        );
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => max(0, (int)($row['c'] ?? 0)),
            'max_id' => max(0, (int)($row['max_id'] ?? 0)),
            'filters' => $filters,
        ];
    }

    /** @param array<string,mixed> $filters @return list<int> */
    public function page(int $gameId, array $filters, int $afterId, int $maxId, int $limit = 5000): array
    {
        $filters = $this->normalizeFilters($filters);
        $afterId = max(0, $afterId);
        $maxId = max(0, $maxId);
        $limit = max(1, min(5000, $limit));
        if ($gameId < 1 || $maxId < 1 || $afterId >= $maxId) {
            return [];
        }

        [$whereSql, $args] = $this->where($gameId, $filters);
        $whereSql .= ' AND f.id>? AND f.id<=?';
        $args[] = $afterId;
        $args[] = $maxId;
        $statement = $this->db->prepare(
            'SELECT f.id FROM ue_files f WHERE ' . $whereSql
            . ' ORDER BY f.id ASC LIMIT ' . $limit
        );
        $statement->execute($args);
        return array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
        ));
    }

    /** @param list<int>|array<int,mixed> $fileIds @return list<int> */
    public function selected(int $gameId, array $fileIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($gameId < 1 || $ids === []) {
            return [];
        }
        if (count($ids) > 1000) {
            throw new \InvalidArgumentException('Select no more than 1,000 visible files at once. Use All matching for larger moves.');
        }
        $statement = $this->db->prepare(
            'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $statement->execute(array_merge([$gameId], $ids));
        return array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
        ));
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function normalizeFilters(array $filters): array
    {
        $search = trim((string)($filters['file_filter'] ?? ''));
        if (mb_strlen($search, 'UTF-8') > 200) {
            $search = mb_substr($search, 0, 200, 'UTF-8');
        }
        $dependency = strtolower(trim((string)($filters['dep_filter'] ?? '')));
        if (!in_array($dependency, ['', 'resolved', 'missing', 'package_only', 'common', 'any'], true)) {
            $dependency = '';
        }
        $type = strtolower(trim((string)($filters['type_filter'] ?? '')));
        if ($type !== '' && !array_key_exists($type, self::TYPE_EXTENSIONS)) {
            $type = '';
        }
        $compression = strtolower(trim((string)($filters['compression_filter'] ?? '')));
        if (!in_array($compression, ['', 'compressed', 'uncompressed'], true)) {
            $compression = '';
        }
        return [
            'file_filter' => $search,
            'dep_filter' => $dependency,
            'type_filter' => $type,
            'compression_filter' => $compression,
        ];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function where(int $gameId, array $filters): array
    {
        if ($gameId < 1) {
            throw new \InvalidArgumentException('A valid source game is required.');
        }
        $game = $this->db->prepare(
            'SELECT COALESCE(p.engine_key,"") engine_key FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?'
        );
        $game->execute([$gameId]);
        $engine = trim((string)($game->fetchColumn() ?: ''));
        $engineMajor = preg_match('/UE\s*([0-9]+)/i', $engine, $match) === 1 ? (int)$match[1] : 0;

        $where = ['f.game_id=?', 'f.scan_status="verified"'];
        $args = [$gameId];
        if ($engineMajor === 3) {
            $where[] = 'LOWER(f.extension)<>"upk"';
        }

        $search = (string)$filters['file_filter'];
        if ($search !== '') {
            $where[] = '(f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }

        $dependency = (string)$filters['dep_filter'];
        if ($dependency !== '') {
            if ($dependency === 'any') {
                $where[] = 'EXISTS (SELECT 1 FROM ue_dependency_package_summaries dx WHERE dx.file_id=f.id)';
            } else {
                $column = match ($dependency) {
                    'resolved' => 'resolved_count',
                    'missing' => 'missing_count',
                    'package_only' => 'package_only_count',
                    default => 'common_count',
                };
                $where[] = 'EXISTS (SELECT 1 FROM ue_dependency_package_summaries dx WHERE dx.file_id=f.id AND dx.' . $column . '>0)';
            }
        }

        $type = (string)$filters['type_filter'];
        $extensions = self::TYPE_EXTENSIONS[$type] ?? [];
        if ($extensions !== []) {
            $where[] = 'f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            array_push($args, ...$extensions);
        }

        if ($engineMajor >= 3 && $filters['compression_filter'] === 'compressed') {
            $where[] = 'f.is_compressed=1';
        } elseif ($engineMajor >= 3 && $filters['compression_filter'] === 'uncompressed') {
            $where[] = 'f.is_compressed=0';
        }

        return [implode(' AND ', $where), $args];
    }
}
