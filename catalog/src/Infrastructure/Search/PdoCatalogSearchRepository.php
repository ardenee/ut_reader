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

    /** @var array<string,bool> */
    private static array $compactAvailability = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function findFiles(string $query, int $limit = 200, ?int $gameId = null, array $filters = []): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > self::MAX_QUERY_LENGTH) {
            return [];
        }

        $limit = max(1, min($limit, 500));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;
        $filters = self::normalizeFilters($filters);
        $base = $this->findCoreFiles($query, $limit, $gameId, $filters);
        if (
            mb_strlen($query, 'UTF-8') < self::MIN_BROAD_QUERY_LENGTH
            || count($base) >= $limit
            || !self::hasMetadataScope($filters['fields'])
            || !$this->compactAvailable($filters['fields'])
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

        // Deep metadata search must stay index-driven. The previous implementation
        // applied leading-wildcard LIKE predicates to converted term BLOBs while
        // joined to very large export/dependency projections. On a mature catalog
        // that can scan tens of millions of rows and monopolise MySQL. Exact term
        // identity uses ue_terms(value_hash,value_length), then indexed term-id
        // references in the compact projections. Filename/package search above
        // still supports prefix/contains matching.
        $matches = [];
        $rowLimit = min(self::MAX_ROWS, max(100, $limit * 12));
        $this->collectExactMetadataMatches($gameId, $query, $rowLimit, $matches, $filters);
        if (in_array('exports', $filters['fields'], true)) {
            $this->collectExactQualifiedExportMatches($gameId, $query, $rowLimit, $matches, $filters['extensions']);
        }
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
    private function findCoreFiles(string $query, int $limit, ?int $gameId, array $filters): array
    {
        $candidateMatches = [];
        [$fileScopeSql, $fileScopeArgs] = self::fileScope($gameId, $filters['extensions']);

        foreach (self::identityQueries($query, $filters['fields']) as [$stage, $column, $value, $label]) {
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

        if (!in_array('files', $filters['fields'], true)) {
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

        $this->collectAlias($gameId, $filters['extensions'], 'package_name', $prefix, 'Package alias', $limit, $candidateMatches);
        $this->collectAlias($gameId, $filters['extensions'], 'original_name', $prefix, 'Alias file', $limit, $candidateMatches);

        // Contains search is retained only on the comparatively small file/alias
        // identity tables. It is deliberately not used on compact metadata term
        // projections, where leading wildcards are prohibitively expensive.
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
            $this->collectAlias($gameId, $filters['extensions'], 'package_name', $contains, 'Package alias', $limit, $candidateMatches);
            $this->collectAlias($gameId, $filters['extensions'], 'original_name', $contains, 'Alias file', $limit, $candidateMatches);
        }

        return $this->hydrate($candidateMatches, $limit);
    }

    /** @param array<int,list<array{field:string,value:string}>> $candidateMatches */
    private function collectAlias(
        ?int $gameId,
        array $extensions,
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
        if ($extensions !== []) {
            $sql .= ' AND f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            array_push($args, ...$extensions);
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
    private static function identityQueries(string $query, array $fields): array
    {
        if (preg_match('/^[A-Fa-f0-9]{40}$/', $query) === 1 && in_array('sha1', $fields, true)) {
            return [['hash_sha1', 'sha1', strtolower($query), 'SHA1']];
        }
        if (preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{8}){3}$/', $query) === 1
            && in_array('guid', $fields, true)) {
            return [['guid_exact', 'package_guid', strtoupper($query), 'GUID']];
        }
        if (preg_match('/^[A-Fa-f0-9]{32}$/', $query) === 1) {
            $queries = [];
            if (in_array('md5', $fields, true)) {
                $queries[] = ['hash_md5', 'md5', strtolower($query), 'MD5'];
            }
            if (in_array('guid', $fields, true)) {
                $queries[] = ['guid_compact', 'package_guid', strtoupper(implode('-', str_split($query, 8))), 'GUID'];
            }
            return $queries;
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

    private function compactAvailable(array $fields): bool
    {
        $key = spl_object_id($this->db) . ':' . implode(',', array_values(array_intersect(
            ['names', 'imports', 'exports'],
            $fields
        )));
        if (array_key_exists($key, self::$compactAvailability)) {
            return self::$compactAvailability[$key];
        }
        try {
            $columns = [];
            if (in_array('names', $fields, true)) {
                $columns[] = ['ue_name_lookup', 'name_term_id'];
            }
            if (in_array('exports', $fields, true)) {
                $columns[] = ['ue_export_lookup', 'local_path_term_id'];
            }
            if (in_array('imports', $fields, true)) {
                $columns[] = ['ue_dependency_links', 'import_object_term_id'];
                $columns[] = ['ue_dependency_links', 'required_object_term_id'];
            }
            foreach ($columns as [$table, $column]) {
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
    private function collectExactMetadataMatches(
        ?int $gameId,
        string $query,
        int $rowLimit,
        array &$matches,
        array $filters
    ): void {
        $term = $this->exactTerm($query);
        if ($term === null) {
            return;
        }
        $termId = (int)$term['id'];
        $value = (string)$term['value'];

        if (in_array('names', $filters['fields'], true)) {
            $this->collectTermReferenceMatches(
                'ue_name_lookup', 'name_term_id', 'name_index', 'Name',
                $termId, $value, $gameId, $rowLimit, $matches, $filters['extensions']
            );
        }
        if (in_array('exports', $filters['fields'], true)) {
            $this->collectTermReferenceMatches(
                'ue_export_lookup', 'object_term_id', 'export_index', 'Export object',
                $termId, $value, $gameId, $rowLimit, $matches, $filters['extensions']
            );
            $this->collectTermReferenceMatches(
                'ue_export_lookup', 'local_path_term_id', 'export_index', 'Export local path',
                $termId, $value, $gameId, $rowLimit, $matches, $filters['extensions']
            );
        }
        if (in_array('imports', $filters['fields'], true)) {
            $this->collectTermReferenceMatches(
                'ue_dependency_links', 'import_object_term_id', 'import_index', 'Import object',
                $termId, $value, $gameId, $rowLimit, $matches, $filters['extensions']
            );
            $this->collectTermReferenceMatches(
                'ue_dependency_links', 'required_object_term_id', 'import_index', 'Import path',
                $termId, $value, $gameId, $rowLimit, $matches, $filters['extensions']
            );
            $this->collectTermReferenceMatches(
                'ue_dependency_links', 'required_package_term_id', 'import_index', 'Required package',
                $termId, $value, $gameId, $rowLimit, $matches, $filters['extensions']
            );
        }
    }

    /**
     * @param array<int,list<array{field:string,value:string}>> $matches
     */
    private function collectTermReferenceMatches(
        string $table,
        string $termColumn,
        string $orderColumn,
        string $label,
        int $termId,
        string $value,
        ?int $gameId,
        int $rowLimit,
        array &$matches,
        array $extensions = []
    ): void {
        $sql = 'SELECT l.file_id id FROM ' . $table . ' l '
            . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
            . 'WHERE l.' . $termColumn . '=?';
        $args = [$termId];
        if ($gameId !== null) {
            $sql .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        if ($extensions !== []) {
            $sql .= ' AND f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            array_push($args, ...$extensions);
        }
        $sql .= ' ORDER BY l.file_id,l.' . $orderColumn . ' LIMIT ' . $rowLimit;

        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($args);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::addMatch($matches, (int)$row['id'], $label, $value);
            }
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Indexed compact metadata search failed: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    /**
     * Search a fully-qualified export such as Package.Group.Object without a
     * contains scan. The package part is compared directly and the local export
     * path is resolved through the exact term dictionary.
     *
     * @param array<int,list<array{field:string,value:string}>> $matches
     */
    private function collectExactQualifiedExportMatches(
        ?int $gameId,
        string $query,
        int $rowLimit,
        array &$matches,
        array $extensions = []
    ): void {
        $separator = strpos($query, '.');
        if ($separator === false || $separator < 1 || $separator >= strlen($query) - 1) {
            return;
        }
        $packageName = substr($query, 0, $separator);
        $localPath = substr($query, $separator + 1);
        $term = $this->exactTerm($localPath);
        if ($term === null) {
            return;
        }
        $termId = (int)$term['id'];

        $sql = 'SELECT l.file_id id,f.package_name match_package '
            . 'FROM ue_export_lookup l '
            . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
            . 'WHERE l.local_path_term_id=? AND f.package_name=?';
        $args = [$termId, $packageName];
        if ($gameId !== null) {
            $sql .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        if ($extensions !== []) {
            $sql .= ' AND f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            array_push($args, ...$extensions);
        }
        $sql .= ' ORDER BY l.file_id,l.export_index LIMIT ' . $rowLimit;

        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($args);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::addMatch(
                    $matches,
                    (int)$row['id'],
                    'Export path',
                    (string)$row['match_package'] . '.' . $localPath
                );
            }

            $aliasSql = 'SELECT l.file_id id,a.package_name match_package '
                . 'FROM ue_export_lookup l '
                . 'JOIN ue_file_package_aliases a ON a.file_id=l.file_id '
                . 'JOIN ue_files f ON f.id=l.file_id AND f.game_id=a.game_id AND f.scan_status="verified" '
                . 'WHERE l.local_path_term_id=? AND a.package_name=?';
            $aliasArgs = [$termId, $packageName];
            if ($gameId !== null) {
                $aliasSql .= ' AND a.game_id=?';
                $aliasArgs[] = $gameId;
            }
            if ($extensions !== []) {
                $aliasSql .= ' AND f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
                array_push($aliasArgs, ...$extensions);
            }
            $aliasSql .= ' ORDER BY l.file_id,l.export_index LIMIT ' . $rowLimit;
            $aliasStatement = $this->db->prepare($aliasSql);
            $aliasStatement->execute($aliasArgs);
            while (($row = $aliasStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
                self::addMatch(
                    $matches,
                    (int)$row['id'],
                    'Alias export path',
                    (string)$row['match_package'] . '.' . $localPath
                );
            }
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Indexed qualified export search failed: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    /** @return array{id:int,value:string}|null */
    private function exactTerm(string $value): ?array
    {
        $length = strlen($value);
        if ($value === '' || $length > 65535) {
            return null;
        }
        try {
            $statement = $this->db->prepare(
                'SELECT id,value_prefix FROM ue_terms WHERE value_hash=? AND value_length=? LIMIT 1'
            );
            $statement->execute([md5($value, true), $length]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $stored = (string)$row['value_prefix'];
            if (!hash_equals($stored, $value)) {
                return null;
            }
            return ['id' => (int)$row['id'], 'value' => $stored];
        } catch (PDOException $error) {
            throw new CatalogSearchUnavailableException(
                'Compact term lookup failed: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    /** @return array{fields:list<string>,extensions:list<string>} */
    private static function normalizeFilters(array $filters): array
    {
        $allowedFields = ['files', 'names', 'imports', 'exports', 'guid', 'md5', 'sha1'];
        $fields = is_array($filters['fields'] ?? null) ? $filters['fields'] : [];
        $fields = array_values(array_unique(array_filter(
            array_map(static fn($value): string => strtolower(trim((string)$value)), $fields),
            static fn(string $value): bool => in_array($value, $allowedFields, true)
        )));
        if ($fields === []) {
            $fields = $allowedFields;
        }

        $extensions = is_array($filters['extensions'] ?? null) ? $filters['extensions'] : [];
        $extensions = array_values(array_unique(array_filter(
            array_map(static fn($value): string => strtolower(trim((string)$value, ". \\t\\r\\n")), $extensions),
            static fn(string $value): bool => $value !== '' && preg_match('/^[a-z0-9_]{1,16}$/', $value) === 1
        )));
        return ['fields' => $fields, 'extensions' => array_slice($extensions, 0, 32)];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function fileScope(?int $gameId, array $extensions): array
    {
        $sql = ' AND f.scan_status="verified"';
        $args = [];
        if ($gameId !== null) {
            $sql .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        if ($extensions !== []) {
            $sql .= ' AND f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            array_push($args, ...$extensions);
        }
        return [$sql, $args];
    }

    private static function hasMetadataScope(array $fields): bool
    {
        return in_array('names', $fields, true)
            || in_array('imports', $fields, true)
            || in_array('exports', $fields, true);
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
