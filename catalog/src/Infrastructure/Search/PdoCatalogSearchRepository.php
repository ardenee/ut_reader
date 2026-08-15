<?php
/**
 * PDO implementation of catalogue identity, filename and compact-metadata search.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Search;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Search\CatalogSearchRepository;
use UnrealDb\Catalog\Application\Search\CatalogSearchUnavailableException;

final class PdoCatalogSearchRepository implements CatalogSearchRepository
{
    private const MAX_QUERY_LENGTH = 255;
    private const MIN_BROAD_QUERY_LENGTH = 3;
    private const MAX_MATCHES_PER_FILE = 12;
    private const MAX_ROWS = 5000;
    private const TEXT_COLLATION = 'utf8mb4_unicode_ci';

    /** @var array<int,bool> */
    private static array $compactAvailability = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function findFiles(string $query, int $limit = 200, ?int $gameId = null): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > self::MAX_QUERY_LENGTH) {
            return [];
        }

        $limit = max(1, min($limit, 500));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;
        $base = $this->findCoreFiles($query, $limit, $gameId);
        if (
            mb_strlen($query, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH
            || count($base) >= $limit
            || !$this->compactAvailable()
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
        $this->collectMetadataMatches($gameId, $like, $rowLimit, $matches);
        $this->collectAliasExportMatches($gameId, $query, $like, $rowLimit, $matches);
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
                try {
                    $statement = $this->db->prepare(
                        'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
                        . 'f.file_size,f.package_guid,f.md5,f.sha1,g.name game_name '
                        . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                        . 'WHERE f.scan_status="verified" AND f.id IN (' . $placeholders . ') '
                        . 'ORDER BY g.name,f.package_name,f.original_name,f.id'
                    );
                    $statement->execute($newIds);
                    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $fileId = (int)$row['id'];
                        $rowsById[$fileId] = $row;
                        $order[] = $fileId;
                    }
                } catch (PDOException $error) {
                    throw new CatalogSearchUnavailableException(
                        'Compact catalogue search hydration failed: ' . $error->getMessage(),
                        0,
                        $error
                    );
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

    /** @return list<array<string,mixed>> */
    private function findCoreFiles(string $query, int $limit, ?int $gameId): array
    {
        $candidateMatches = [];
        $fileScopeSql = ' AND f.scan_status="verified"' . ($gameId === null ? '' : ' AND f.game_id=?');
        $fileScopeArgs = $gameId === null ? [] : [$gameId];

        foreach (self::identityQueries($query) as [$stage, $column, $value, $label]) {
            $this->collectStage(
                $stage,
                'SELECT f.id,f.' . $column . ' match_value FROM ue_files f '
                    . 'WHERE f.' . $column . '=?' . $fileScopeSql . ' '
                    . 'ORDER BY f.package_name,f.original_name,f.id',
                array_merge([$value], $fileScopeArgs),
                $label,
                $limit,
                $candidateMatches
            );
        }
        if ($candidateMatches !== []) {
            return $this->hydrate($candidateMatches, $limit);
        }

        if (mb_strlen($query, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH) {
            return [];
        }

        $prefix = $query . '%';
        $contains = '%' . $query . '%';
        foreach ([
            ['package_name_prefix', 'package_name', $prefix, 'Package'],
            ['file_name_prefix', 'original_name', $prefix, 'File'],
            ['stored_name_prefix', 'stored_name', $prefix, 'Stored file'],
        ] as [$stage, $column, $value, $label]) {
            $this->collectStage(
                $stage,
                'SELECT f.id,f.' . $column . ' match_value FROM ue_files f '
                    . 'WHERE f.' . $column . ' LIKE ?' . $fileScopeSql . ' '
                    . 'ORDER BY f.' . $column . ',f.id',
                array_merge([$value], $fileScopeArgs),
                $label,
                $limit,
                $candidateMatches
            );
        }

        $this->collectAlias($gameId, 'package_name', $prefix, 'Package alias', $limit, $candidateMatches);
        $this->collectAlias($gameId, 'original_name', $prefix, 'Alias file', $limit, $candidateMatches);

        if (count($candidateMatches) < $limit) {
            foreach ([
                ['package_name_contains', 'package_name', $contains, 'Package'],
                ['file_name_contains', 'original_name', $contains, 'File'],
                ['stored_name_contains', 'stored_name', $contains, 'Stored file'],
            ] as [$stage, $column, $value, $label]) {
                $this->collectStage(
                    $stage,
                    'SELECT f.id,f.' . $column . ' match_value FROM ue_files f '
                        . 'WHERE f.' . $column . ' LIKE ?' . $fileScopeSql . ' '
                        . 'ORDER BY f.' . $column . ',f.id',
                    array_merge([$value], $fileScopeArgs),
                    $label,
                    $limit,
                    $candidateMatches
                );
            }
            $this->collectAlias($gameId, 'package_name', $contains, 'Package alias', $limit, $candidateMatches);
            $this->collectAlias($gameId, 'original_name', $contains, 'Alias file', $limit, $candidateMatches);
        }

        return $this->hydrate($candidateMatches, $limit);
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private function collectAlias(
        ?int $gameId,
        string $column,
        string $value,
        string $label,
        int $limit,
        array &$candidateMatches
    ): void {
        if (count($candidateMatches) >= $limit) {
            return;
        }

        $sql = 'SELECT a.file_id id,a.' . $column . ' match_value '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'WHERE f.scan_status="verified" AND a.' . $column . ' LIKE ?';
        $args = [$value];
        if ($gameId !== null) {
            $sql .= ' AND a.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY a.' . $column . ',a.id';
        $this->collectStage('alias_' . $column, $sql, $args, $label, $limit, $candidateMatches);
    }

    /** @param list<mixed> $args @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private function collectStage(
        string $stage,
        string $sql,
        array $args,
        string $label,
        int $limit,
        array &$candidateMatches
    ): void {
        if (count($candidateMatches) >= $limit) {
            return;
        }
        $rowLimit = max(1, min(self::MAX_ROWS, ($limit - count($candidateMatches)) * 12));
        try {
            $statement = $this->db->prepare($sql . ' LIMIT ' . $rowLimit);
            $statement->execute($args);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::addMatch(
                    $candidateMatches,
                    (int)($row['id'] ?? 0),
                    $label,
                    (string)($row['match_value'] ?? '')
                );
                if (count($candidateMatches) >= $limit) {
                    $statement->closeCursor();
                    break;
                }
            }
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Catalogue search stage ' . $stage . ' failed: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    /** @return list<array{string,string,string,string}> */
    private static function identityQueries(string $query): array
    {
        if (preg_match('/^[A-Fa-f0-9]{40}$/', $query) === 1) {
            return [['hash_sha1', 'sha1', strtolower($query), 'SHA1']];
        }
        if (preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{8}){3}$/', $query) === 1) {
            return [['guid_exact', 'package_guid', strtoupper($query), 'GUID']];
        }
        if (preg_match('/^[A-Fa-f0-9]{32}$/', $query) === 1) {
            return [
                ['hash_md5', 'md5', strtolower($query), 'MD5'],
                ['guid_compact', 'package_guid', strtoupper(implode('-', str_split($query, 8))), 'GUID'],
            ];
        }
        return [];
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches @return list<array<string,mixed>> */
    private function hydrate(array $candidateMatches, int $limit): array
    {
        if ($candidateMatches === []) {
            return [];
        }
        $ids = array_slice(array_keys($candidateMatches), 0, $limit);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $statement = $this->db->prepare(
                'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
                . 'f.file_size,f.package_guid,f.md5,f.sha1,g.name game_name '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'WHERE f.scan_status="verified" AND f.id IN (' . $placeholders . ') '
                . 'ORDER BY g.name,f.package_name,f.original_name,f.id LIMIT ' . $limit
            );
            $statement->execute($ids);
            $rows = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $row['matched_fields'] = $candidateMatches[(int)$row['id']] ?? [];
                $rows[] = $row;
            }
            return $rows;
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Final catalogue search lookup failed: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    private function compactAvailable(): bool
    {
        $key = spl_object_id($this->db);
        if (array_key_exists($key, self::$compactAvailability)) {
            return self::$compactAvailability[$key];
        }
        try {
            foreach ([
                ['ue_export_lookup', 'local_path_term_id'],
                ['ue_dependency_links', 'import_object_term_id'],
                ['ue_dependency_links', 'required_object_term_id'],
            ] as [$table, $column]) {
                $statement = $this->db->prepare(
                    'SELECT 1 FROM information_schema.COLUMNS '
                    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
                );
                $statement->execute([$table, $column]);
                if ($statement->fetchColumn() === false) {
                    return self::$compactAvailability[$key] = false;
                }
            }
            return self::$compactAvailability[$key] = true;
        } catch (Throwable) {
            return self::$compactAvailability[$key] = false;
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $matches */
    private function collectMetadataMatches(?int $gameId, string $like, int $rowLimit, array &$matches): void
    {
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
            try {
                $statement = $this->db->prepare($sql);
                $statement->execute($args);
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    self::addMatch($matches, (int)$row['id'], $label, (string)$row['match_value']);
                }
            } catch (PDOException $error) {
                throw new CatalogSearchUnavailableException(
                    'Compact catalogue metadata search failed: ' . $error->getMessage(),
                    0,
                    $error
                );
            }
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $matches */
    private function collectAliasExportMatches(
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
        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($args);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::addMatch($matches, (int)$row['id'], 'Alias export path', (string)$row['match_value']);
            }
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Alias export search failed: ' . $error->getMessage(),
                0,
                $error
            );
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
