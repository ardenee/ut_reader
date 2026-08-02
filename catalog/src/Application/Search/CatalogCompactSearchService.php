<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Search;

use PDO;
use Throwable;

/** Adds compact metadata matches to the existing file/legacy search results. */
final class CatalogCompactSearchService
{
    private const MIN_BROAD_QUERY_LENGTH = 3;
    private const MAX_QUERY_LENGTH = 255;
    private const MAX_MATCHES_PER_FILE = 12;
    private const MAX_ROWS = 5000;
    private const TEXT_COLLATION = 'utf8mb4_unicode_ci';

    /** @var array<int,bool> */
    private static array $availability = [];

    /** @return list<array<string,mixed>> */
    public static function findFiles(PDO $db, string $query, int $limit = 200, ?int $gameId = null): array
    {
        $query = trim($query);
        $limit = max(1, min(500, $limit));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;

        $base = CatalogSearchService::findFiles($db, $query, $limit, $gameId);
        if (
            $query === ''
            || strlen($query) > self::MAX_QUERY_LENGTH
            || mb_strlen($query, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH
            || count($base) >= $limit
            || !self::available($db)
        ) {
            return $base;
        }

        $rowsById = [];
        $order = [];
        foreach ($base as $row) {
            $fileId = (int)($row['id'] ?? 0);
            if ($fileId < 1) {
                continue;
            }
            $rowsById[$fileId] = $row;
            $order[] = $fileId;
        }

        $matches = [];
        $like = '%' . $query . '%';
        $rowLimit = min(self::MAX_ROWS, max(100, $limit * 12));
        self::collectMetadataMatches($db, $gameId, $like, $rowLimit, $matches);
        self::collectAliasExportMatches($db, $gameId, $query, $like, $rowLimit, $matches);
        if ($matches === []) {
            return $base;
        }

        $newIds = [];
        foreach (array_keys($matches) as $fileId) {
            if (!isset($rowsById[$fileId])) {
                $newIds[] = $fileId;
            }
        }
        if ($newIds !== []) {
            $remaining = $limit - count($rowsById);
            $newIds = array_slice($newIds, 0, max(0, $remaining));
            if ($newIds !== []) {
                $placeholders = implode(',', array_fill(0, count($newIds), '?'));
                $statement = $db->prepare(
                    'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
                    . 'f.file_size,f.package_guid,f.md5,f.sha1,g.name game_name '
                    . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                    . 'WHERE f.scan_status="verified" AND f.id IN (' . $placeholders . ') '
                    . 'ORDER BY g.name,f.package_name,f.original_name,f.id'
                );
                $statement->execute($newIds);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $fileId = (int)$row['id'];
                    $rowsById[$fileId] = $row;
                    $order[] = $fileId;
                }
            }
        }

        foreach ($matches as $fileId => $fileMatches) {
            if (!isset($rowsById[$fileId])) {
                continue;
            }
            $existing = is_array($rowsById[$fileId]['matched_fields'] ?? null)
                ? $rowsById[$fileId]['matched_fields']
                : [];
            $rowsById[$fileId]['matched_fields'] = self::mergeMatches($existing, $fileMatches);
        }

        $result = [];
        foreach ($order as $fileId) {
            if (isset($rowsById[$fileId])) {
                $result[] = $rowsById[$fileId];
            }
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }

    private static function available(PDO $db): bool
    {
        $key = spl_object_id($db);
        if (array_key_exists($key, self::$availability)) {
            return self::$availability[$key];
        }
        try {
            $columns = [
                ['ue_export_lookup', 'local_path_term_id'],
                ['ue_dependency_links', 'import_object_term_id'],
                ['ue_dependency_links', 'required_object_term_id'],
            ];
            foreach ($columns as [$table, $column]) {
                $statement = $db->prepare(
                    'SELECT 1 FROM information_schema.COLUMNS '
                    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
                );
                $statement->execute([$table, $column]);
                if ($statement->fetchColumn() === false) {
                    return self::$availability[$key] = false;
                }
            }
            return self::$availability[$key] = true;
        } catch (Throwable) {
            return self::$availability[$key] = false;
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $matches */
    private static function collectMetadataMatches(
        PDO $db,
        ?int $gameId,
        string $like,
        int $rowLimit,
        array &$matches
    ): void {
        $collation = self::TEXT_COLLATION;
        $gameSql = $gameId !== null ? ' AND f.game_id=?' : '';

        $queries = [
            [
                'Export object',
                'SELECT l.file_id id,(CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') match_value '
                . 'FROM ue_export_lookup l '
                . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
                . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
                . 'JOIN ue_terms t ON t.id=l.object_term_id '
                . 'WHERE (CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') LIKE ?'
                . $gameSql . ' ORDER BY l.file_id,l.export_index LIMIT ' . $rowLimit,
                [$like],
            ],
            [
                'Export path',
                'SELECT l.file_id id,'
                . '(CONCAT(f.package_name,".",CONVERT(t.value_prefix USING utf8mb4)) COLLATE ' . $collation . ') match_value '
                . 'FROM ue_export_lookup l '
                . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
                . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
                . 'JOIN ue_terms t ON t.id=l.local_path_term_id '
                . 'WHERE ((CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') LIKE ? '
                . 'OR (CONCAT(f.package_name,".",CONVERT(t.value_prefix USING utf8mb4)) COLLATE '
                . $collation . ') LIKE ?)'
                . $gameSql . ' ORDER BY l.file_id,l.export_index LIMIT ' . $rowLimit,
                [$like, $like],
            ],
            [
                'Import object',
                'SELECT l.file_id id,(CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') match_value '
                . 'FROM ue_dependency_links l '
                . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
                . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
                . 'JOIN ue_terms t ON t.id=l.import_object_term_id '
                . 'WHERE (CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') LIKE ?'
                . $gameSql . ' ORDER BY l.file_id,l.import_index LIMIT ' . $rowLimit,
                [$like],
            ],
            [
                'Import path',
                'SELECT l.file_id id,(CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') match_value '
                . 'FROM ue_dependency_links l '
                . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
                . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
                . 'JOIN ue_terms t ON t.id=l.required_object_term_id '
                . 'WHERE (CONVERT(t.value_prefix USING utf8mb4) COLLATE ' . $collation . ') LIKE ?'
                . $gameSql . ' ORDER BY l.file_id,l.import_index LIMIT ' . $rowLimit,
                [$like],
            ],
        ];

        foreach ($queries as [$label, $sql, $args]) {
            if ($gameId !== null) {
                $args[] = $gameId;
            }
            $statement = $db->prepare($sql);
            $statement->execute($args);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                self::addMatch($matches, (int)$row['id'], $label, (string)$row['match_value']);
            }
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $matches */
    private static function collectAliasExportMatches(
        PDO $db,
        ?int $gameId,
        string $query,
        string $like,
        int $rowLimit,
        array &$matches
    ): void {
        if (strpos($query, '.') === false) {
            return;
        }
        $collation = self::TEXT_COLLATION;
        $sql = 'SELECT a.file_id id,'
            . '(CONCAT(a.package_name,".",CONVERT(t.value_prefix USING utf8mb4)) COLLATE ' . $collation . ') match_value '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_file_metadata m ON m.file_id=a.file_id AND m.format_version=2 '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.scan_status="verified" '
            . 'JOIN ue_export_lookup l ON l.file_id=a.file_id '
            . 'JOIN ue_terms t ON t.id=l.local_path_term_id '
            . 'WHERE (CONCAT(a.package_name,".",CONVERT(t.value_prefix USING utf8mb4)) COLLATE '
            . $collation . ') LIKE ?';
        $args = [$like];
        if ($gameId !== null) {
            $sql .= ' AND a.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY a.file_id,l.export_index LIMIT ' . $rowLimit;
        $statement = $db->prepare($sql);
        $statement->execute($args);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            self::addMatch($matches, (int)$row['id'], 'Alias export path', (string)$row['match_value']);
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $matches */
    private static function addMatch(array &$matches, int $fileId, string $field, string $value): void
    {
        $value = trim($value);
        if ($fileId < 1 || $value === '') {
            return;
        }
        $current = $matches[$fileId] ?? [];
        foreach ($current as $match) {
            if ((string)$match['field'] === $field && (string)$match['value'] === $value) {
                return;
            }
        }
        if (count($current) >= self::MAX_MATCHES_PER_FILE) {
            return;
        }
        $current[] = ['field' => $field, 'value' => $value];
        $matches[$fileId] = $current;
    }

    /**
     * @param list<array{field:string,value:string}> $left
     * @param list<array{field:string,value:string}> $right
     * @return list<array{field:string,value:string}>
     */
    private static function mergeMatches(array $left, array $right): array
    {
        $result = [];
        foreach (array_merge($left, $right) as $match) {
            $field = (string)($match['field'] ?? '');
            $value = (string)($match['value'] ?? '');
            if ($field === '' || $value === '') {
                continue;
            }
            $key = $field . "\0" . $value;
            $result[$key] = ['field' => $field, 'value' => $value];
            if (count($result) >= self::MAX_MATCHES_PER_FILE) {
                break;
            }
        }
        return array_values($result);
    }
}
