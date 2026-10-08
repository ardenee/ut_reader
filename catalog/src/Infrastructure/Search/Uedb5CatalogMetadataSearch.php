<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Search;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

/**
 * Candidate-first V5 metadata search.
 *
 * SQL projections are disposable accelerators only; every returned metadata
 * match is confirmed from authoritative .uedb5 rows.
 */
final class Uedb5CatalogMetadataSearch
{
    private Uedb5MetadataReader $reader;
    private Uedb5ParityV5ReadService $dependencies;

    public function __construct(private readonly PDO $db)
    {
        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $storage = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storage === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 search.');
        }
        $this->reader = new Uedb5MetadataReader($storage);
        $this->dependencies = new Uedb5ParityV5ReadService($db, is_array($config) ? $config : []);
    }

    /** @param list<string> $fields */
    public function available(array $fields): bool
    {
        $tables = ['ue_uedb5_files'];
        if (in_array('names', $fields, true)) {
            $tables[] = 'ue_uedb5_name_candidates';
        }
        if (in_array('exports', $fields, true)) {
            $tables[] = 'ue_uedb5_object_candidates';
        }
        if (in_array('imports', $fields, true)) {
            $tables[] = 'ue_uedb5_dependency_edges';
        }
        foreach (array_values(array_unique($tables)) as $table) {
            $s = $this->db->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
            );
            $s->execute([$table]);
            if ($s->fetchColumn() === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{fields:list<string>,extensions:list<string>} $filters
     * @return array<int,list<array{field:string,value:string}>>
     */
    public function findMatches(?int $gameId, string $query, int $rowLimit, array $filters): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $rowLimit = max(1, min(5000, $rowLimit));
        $fields = $filters['fields'];
        $extensions = $filters['extensions'];
        if (!str_contains($query, '.')) {
            return $this->findSimpleExactMatches($gameId, $query, $rowLimit, $filters);
        }
        $candidates = [];

        if (in_array('names', $fields, true)) {
            $this->collectNameCandidates($candidates, $gameId, $query, $extensions, $rowLimit);
        }
        if (in_array('exports', $fields, true)) {
            $this->collectObjectCandidates($candidates, $gameId, $query, $extensions, $rowLimit);
            $this->collectQualifiedProviderCandidates($candidates, $gameId, $query, $extensions, $rowLimit);
        }
        if (in_array('imports', $fields, true)) {
            $this->collectDependencyCandidates($candidates, $gameId, $query, $extensions, $rowLimit);
        }

        // Descendant paths need a path-component seed. Unreal object/group path
        // components are FNames, so the final component is indexed in NameMap.
        if (in_array('exports', $fields, true) || in_array('imports', $fields, true)) {
            $this->collectNameCandidates(
                $candidates,
                $gameId,
                $this->candidateNeedle($query),
                $extensions,
                $rowLimit
            );
        }

        if ($candidates === []) {
            return [];
        }

        $aliases = $this->aliases(array_keys($candidates));
        $matches = [];
        foreach ($candidates as $fileId => $candidateGameId) {
            $fileId = (int)$fileId;
            $candidateGameId = (int)$candidateGameId;
            try {
                $snapshot = $this->reader->snapshot($candidateGameId, $fileId);
                $this->matchSnapshot(
                    $matches,
                    $snapshot,
                    $candidateGameId,
                    $fileId,
                    $query,
                    $fields,
                    (array)($aliases[$fileId] ?? [])
                );
                if (in_array('imports', $fields, true)) {
                    $this->matchDependencies(
                        $matches,
                        $candidateGameId,
                        $fileId,
                        $query
                    );
                }
            } catch (Throwable $error) {
                error_log(
                    '[UnrealDB UEDB5 search] file_id=' . $fileId . ' error=' . $error->getMessage()
                );
            } finally {
                $this->reader->clearCache($candidateGameId, $fileId);
            }
            if (count($matches) >= $rowLimit) {
                break;
            }
        }
        return $matches;
    }

    /**
     * Exact non-path search using only indexed candidate positions plus targeted
     * UEDB5 row reads. This avoids opening full containers for broad FNames.
     *
     * @param array{fields:list<string>,extensions:list<string>} $filters
     * @return array<int,list<array{field:string,value:string}>>
     */
    private function findSimpleExactMatches(
        ?int $gameId,
        string $query,
        int $rowLimit,
        array $filters
    ): array {
        $matches = [];
        $fields = $filters['fields'];
        $extensions = $filters['extensions'];
        $nameKey = CatalogUnrealIdentityHash::nameKey($query);
        $fnameKey = CatalogUnrealIdentityHash::fnameKey($query);
        if ($nameKey === '') {
            return [];
        }

        if (in_array('names', $fields, true)) {
            $sql = 'SELECT n.file_id,f.game_id,n.first_name_index FROM ue_uedb5_name_candidates n '
                . 'JOIN ue_files f ON f.id=n.file_id AND f.scan_status="verified" '
                . 'WHERE n.name_key_hash=? AND n.name_key_length=? AND n.name_key_fingerprint=?';
            $args = [md5($nameKey, true), strlen($nameKey), hash('sha256', $nameKey, true)];
            $this->appendScope($sql, $args, $gameId, $extensions);
            $sql .= ' ORDER BY n.file_id LIMIT ' . $rowLimit;
            $s = $this->db->prepare($sql);
            $s->execute($args);
            while (($row = $s->fetch(PDO::FETCH_ASSOC)) !== false) {
                $fileId = (int)$row['file_id'];
                $candidateGameId = (int)$row['game_id'];
                try {
                    $manifest = $this->reader->manifest($candidateGameId, $fileId);
                    $sections = (array)($manifest['sections'] ?? []);
                    $section = array_key_exists('names', $sections)
                        ? 'names'
                        : (array_key_exists('name_map', $sections) ? 'name_map' : '');
                    if ($section === '') {
                        continue;
                    }
                    foreach ($this->reader->rowsByPositions(
                        $candidateGameId,
                        $fileId,
                        $section,
                        [(int)$row['first_name_index']]
                    ) as $raw) {
                        $text = trim((string)((array)$raw)['text'] ?? '');
                        if ($text !== '' && CatalogUnrealIdentityHash::nameKey($text) === $nameKey) {
                            self::addMatch($matches, $fileId, 'Name', $text);
                        }
                    }
                } finally {
                    $this->reader->clearCache($candidateGameId, $fileId);
                }
            }
        }

        if (in_array('exports', $fields, true)) {
            $sql = 'SELECT o.file_id,f.game_id,o.object_kind,o.object_index '
                . 'FROM ue_uedb5_object_candidates o '
                . 'JOIN ue_files f ON f.id=o.file_id AND f.scan_status="verified" '
                . 'WHERE o.object_name_hash=? AND o.object_name_length=?';
            $args = [md5($nameKey, true), strlen($nameKey)];
            $this->appendScope($sql, $args, $gameId, $extensions);
            $sql .= ' ORDER BY o.file_id,o.object_kind,o.object_index LIMIT ' . $rowLimit;
            $s = $this->db->prepare($sql);
            $s->execute($args);
            while (($row = $s->fetch(PDO::FETCH_ASSOC)) !== false) {
                $fileId = (int)$row['file_id'];
                $candidateGameId = (int)$row['game_id'];
                $section = (int)$row['object_kind'] === Uedb5SqlProjectionContract::OBJECT_KIND_CELL_EXPORT
                    ? 'cell_exports'
                    : 'exports';
                try {
                    foreach ($this->reader->rowsByPositions(
                        $candidateGameId,
                        $fileId,
                        $section,
                        [(int)$row['object_index']]
                    ) as $raw) {
                        $raw = (array)$raw;
                        $value = $raw['object_name'] ?? $raw['objectName'] ?? null;
                        $text = is_array($value)
                            ? trim((string)($value['text'] ?? $value['base_text'] ?? ''))
                            : trim((string)$value);
                        if ($text !== '' && CatalogUnrealIdentityHash::nameKey($text) === $nameKey) {
                            self::addMatch($matches, $fileId, 'Export object', $text);
                        }
                    }
                } finally {
                    $this->reader->clearCache($candidateGameId, $fileId);
                }
            }
        }

        if (in_array('imports', $fields, true)) {
            $sql = 'SELECT e.file_id,f.game_id,e.source_kind,e.source_index '
                . 'FROM ue_uedb5_dependency_edges e '
                . 'JOIN ue_files f ON f.id=e.file_id AND f.scan_status="verified" '
                . 'WHERE ((e.required_object_key_kind=? AND e.required_object_key=?) OR '
                . '(e.required_package_key_kind=? AND e.required_package_key=?) OR '
                . '(e.required_package_key_kind=? AND e.required_package_key=?))';
            $args = [
                Uedb5SqlProjectionContract::OBJECT_KEY_NAME,
                md5($fnameKey, true),
                Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,
                md5($fnameKey, true),
                Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
                md5($nameKey, true),
            ];
            $this->appendScope($sql, $args, $gameId, $extensions);
            $sql .= ' ORDER BY e.file_id,e.source_kind,e.source_index LIMIT ' . $rowLimit;
            $s = $this->db->prepare($sql);
            $s->execute($args);
            $byFile = [];
            while (($row = $s->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ((int)$row['source_kind'] !== Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT) {
                    continue;
                }
                $fileId = (int)$row['file_id'];
                $byFile[$fileId]['game_id'] = (int)$row['game_id'];
                $byFile[$fileId]['indexes'][] = (int)$row['source_index'];
            }
            foreach ($byFile as $fileId => $entry) {
                foreach ($this->dependencies->dependenciesAtIndexes(
                    (int)$entry['game_id'],
                    (int)$fileId,
                    array_values(array_unique((array)$entry['indexes']))
                ) as $dependency) {
                    $package = trim((string)($dependency['required_package'] ?? ''));
                    $object = trim((string)($dependency['required_object'] ?? ''));
                    $path = trim((string)($dependency['required_object_path'] ?? ''), '. ');
                    if ($package !== '' && CatalogUnrealIdentityHash::nameKey($package) === $nameKey) {
                        self::addMatch($matches, (int)$fileId, 'Required package', $package);
                    }
                    if ($object !== '' && CatalogUnrealIdentityHash::nameKey($object) === $nameKey) {
                        self::addMatch($matches, (int)$fileId, 'Import object', $object);
                    }
                    if ($path !== '') {
                        $pathKey = CatalogUnrealIdentityHash::pathKey($path);
                        if ($pathKey === CatalogUnrealIdentityHash::pathKey($query)) {
                            self::addMatch($matches, (int)$fileId, 'Import path', $path);
                        }
                    }
                }
            }
        }

        return $matches;
    }

    /** @param array<int,int> $candidates @param list<string> $extensions */
    private function collectNameCandidates(
        array &$candidates,
        ?int $gameId,
        string $query,
        array $extensions,
        int $limit
    ): void {
        $needle = CatalogUnrealIdentityHash::nameKey(trim($query));
        if ($needle === '') {
            return;
        }
        $hash = md5($needle, true);
        $finger = hash('sha256', $needle, true);
        $sql = 'SELECT DISTINCT n.file_id,f.game_id FROM ue_uedb5_name_candidates n '
            . 'JOIN ue_files f ON f.id=n.file_id AND f.scan_status="verified" '
            . 'WHERE n.name_key_hash=? AND n.name_key_length=? AND n.name_key_fingerprint=?';
        $args = [$hash, strlen($needle), $finger];
        $this->appendScope($sql, $args, $gameId, $extensions);
        $sql .= ' ORDER BY n.file_id LIMIT ' . $limit;
        $this->collectCandidateRows($candidates, $sql, $args, $limit);
    }

    /** @param array<int,int> $candidates @param list<string> $extensions */
    private function collectObjectCandidates(
        array &$candidates,
        ?int $gameId,
        string $query,
        array $extensions,
        int $limit
    ): void {
        $needle = CatalogUnrealIdentityHash::nameKey(trim($query));
        if ($needle === '') {
            return;
        }
        $sql = 'SELECT DISTINCT o.file_id,f.game_id FROM ue_uedb5_object_candidates o '
            . 'JOIN ue_files f ON f.id=o.file_id AND f.scan_status="verified" '
            . 'WHERE o.object_name_hash=? AND o.object_name_length=?';
        $args = [md5($needle, true), strlen($needle)];
        $this->appendScope($sql, $args, $gameId, $extensions);
        $sql .= ' ORDER BY o.file_id LIMIT ' . $limit;
        $this->collectCandidateRows($candidates, $sql, $args, $limit);
    }

    /** @param array<int,int> $candidates @param list<string> $extensions */
    private function collectDependencyCandidates(
        array &$candidates,
        ?int $gameId,
        string $query,
        array $extensions,
        int $limit
    ): void {
        $leaf = CatalogUnrealIdentityHash::fnameKey($this->candidateNeedle($query));
        $packageExact = CatalogUnrealIdentityHash::fnameKey($query);
        $packageNormalized = CatalogUnrealIdentityHash::nameKey($query);
        $sql = 'SELECT DISTINCT e.file_id,f.game_id FROM ue_uedb5_dependency_edges e '
            . 'JOIN ue_files f ON f.id=e.file_id AND f.scan_status="verified" '
            . 'WHERE ('
            . '(e.required_object_key_kind=? AND e.required_object_key=?) OR '
            . '(e.required_package_key_kind=? AND e.required_package_key=?) OR '
            . '(e.required_package_key_kind=? AND e.required_package_key=?))';
        $args = [
            Uedb5SqlProjectionContract::OBJECT_KEY_NAME,
            md5($leaf, true),
            Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,
            md5($packageExact, true),
            Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
            md5($packageNormalized, true),
        ];
        $this->appendScope($sql, $args, $gameId, $extensions);
        $sql .= ' ORDER BY e.file_id LIMIT ' . $limit;
        $this->collectCandidateRows($candidates, $sql, $args, $limit);
    }

    /** @param array<int,int> $candidates @param list<string> $extensions */
    private function collectQualifiedProviderCandidates(
        array &$candidates,
        ?int $gameId,
        string $query,
        array $extensions,
        int $limit
    ): void {
        $separator = strpos($query, '.');
        if ($separator === false || $separator < 1 || $separator >= strlen($query) - 1) {
            return;
        }
        $package = substr($query, 0, $separator);
        $sql = 'SELECT DISTINCT f.id file_id,f.game_id FROM ue_files f '
            . 'LEFT JOIN ue_file_package_aliases a ON a.file_id=f.id AND a.game_id=f.game_id '
            . 'WHERE f.scan_status="verified" AND (f.package_name=? OR a.package_name=?)';
        $args = [$package, $package];
        $this->appendScope($sql, $args, $gameId, $extensions);
        $sql .= ' ORDER BY f.id LIMIT ' . $limit;
        $this->collectCandidateRows($candidates, $sql, $args, $limit);
    }

    /** @param array<int,int> $candidates @param list<mixed> $args */
    private function collectCandidateRows(array &$candidates, string $sql, array $args, int $limit): void
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);
        while (($row = $s->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fileId = (int)($row['file_id'] ?? 0);
            $gameId = (int)($row['game_id'] ?? 0);
            if ($fileId > 0 && $gameId > 0) {
                $candidates[$fileId] = $gameId;
            }
            if (count($candidates) >= $limit) {
                break;
            }
        }
    }

    /** @param list<mixed> $args @param list<string> $extensions */
    private function appendScope(string &$sql, array &$args, ?int $gameId, array $extensions): void
    {
        if ($gameId !== null) {
            $sql .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        if ($extensions !== []) {
            $sql .= ' AND f.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            array_push($args, ...$extensions);
        }
    }

    /**
     * @param array<int,list<array{field:string,value:string}>> $matches
     * @param array<string,mixed> $snapshot
     * @param list<string> $aliases
     * @param list<string> $fields
     */
    private function matchSnapshot(
        array &$matches,
        array $snapshot,
        int $gameId,
        int $fileId,
        string $query,
        array $fields,
        array $aliases
    ): void {
        $queryNameKey = CatalogUnrealIdentityHash::nameKey($query);
        $queryPathKey = CatalogUnrealIdentityHash::pathKey($query);
        $descendantPrefix = $queryPathKey !== '' ? $queryPathKey . '.' : '';
        $sections = (array)($snapshot['sections'] ?? []);

        if (in_array('names', $fields, true)) {
            $nameRows = (array)($sections['names'] ?? $sections['name_map'] ?? []);
            foreach ($nameRows as $row) {
                $text = trim((string)((array)$row)['text'] ?? '');
                if ($text !== '' && CatalogUnrealIdentityHash::nameKey($text) === $queryNameKey) {
                    self::addMatch($matches, $fileId, 'Name', $text);
                }
            }
        }

        if (!in_array('exports', $fields, true)) {
            return;
        }
        $package = trim((string)($snapshot['file']['package_name'] ?? ''));
        $packageNames = array_values(array_unique(array_filter(array_merge([$package], $aliases))));
        foreach (Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot) as $export) {
            $objectName = trim((string)($export['object_name'] ?? ''));
            $localPath = trim((string)($export['local_path'] ?? ''), '. ');
            if ($objectName !== '' && CatalogUnrealIdentityHash::nameKey($objectName) === $queryNameKey) {
                self::addMatch($matches, $fileId, 'Export object', $objectName);
                if ($localPath !== '') {
                    self::addMatch($matches, $fileId, 'Export local path', $localPath);
                }
            }
            if ($localPath === '') {
                continue;
            }
            $localKey = CatalogUnrealIdentityHash::pathKey($localPath);
            if ($localKey === $queryPathKey || ($descendantPrefix !== '' && str_starts_with($localKey, $descendantPrefix))) {
                self::addMatch($matches, $fileId, 'Export local path', $localPath);
            }
            foreach ($packageNames as $packageName) {
                $full = $packageName . '.' . $localPath;
                $fullKey = CatalogUnrealIdentityHash::pathKey($full);
                if ($fullKey === $queryPathKey || ($descendantPrefix !== '' && str_starts_with($fullKey, $descendantPrefix))) {
                    self::addMatch(
                        $matches,
                        $fileId,
                        strcasecmp($packageName, $package) === 0 ? 'Export path' : 'Alias export path',
                        $full
                    );
                }
            }
        }
    }

    /** @param array<int,list<array{field:string,value:string}>> $matches */
    private function matchDependencies(
        array &$matches,
        int $gameId,
        int $fileId,
        string $query
    ): void {
        $queryNameKey = CatalogUnrealIdentityHash::nameKey($query);
        $queryPathKey = CatalogUnrealIdentityHash::pathKey($query);
        $descendantPrefix = $queryPathKey !== '' ? $queryPathKey . '.' : '';

        foreach ($this->dependencies->dependencies($gameId, $fileId) as $row) {
            $package = trim((string)($row['required_package'] ?? ''));
            $object = trim((string)($row['required_object'] ?? ''));
            $path = trim((string)($row['required_object_path'] ?? ''), '. ');
            if ($package !== '' && CatalogUnrealIdentityHash::nameKey($package) === $queryNameKey) {
                self::addMatch($matches, $fileId, 'Required package', $package);
            }
            if ($object !== '' && CatalogUnrealIdentityHash::nameKey($object) === $queryNameKey) {
                self::addMatch($matches, $fileId, 'Import object', $object);
            }
            if ($path === '') {
                continue;
            }
            $pathKey = CatalogUnrealIdentityHash::pathKey($path);
            $relative = $path;
            if ($package !== '' && str_starts_with(
                CatalogUnrealIdentityHash::pathKey($path),
                CatalogUnrealIdentityHash::pathKey($package) . '.'
            )) {
                $relative = substr($path, strlen($package) + 1);
            }
            $relativeKey = CatalogUnrealIdentityHash::pathKey($relative);
            if ($pathKey === $queryPathKey
                || $relativeKey === $queryPathKey
                || ($descendantPrefix !== ''
                    && (str_starts_with($pathKey, $descendantPrefix)
                        || str_starts_with($relativeKey, $descendantPrefix)))) {
                self::addMatch($matches, $fileId, 'Import path', $path);
            }
        }
    }

    /** @param list<int|string> $fileIds @return array<int,list<string>> */
    private function aliases(array $fileIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $fileIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT file_id,package_name FROM ue_file_package_aliases WHERE file_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY file_id,id';
        $s = $this->db->prepare($sql);
        $s->execute($ids);
        $out = [];
        while (($row = $s->fetch(PDO::FETCH_ASSOC)) !== false) {
            $name = trim((string)$row['package_name']);
            if ($name !== '') {
                $out[(int)$row['file_id']][] = $name;
            }
        }
        return $out;
    }

    private function candidateNeedle(string $query): string
    {
        $query = trim($query, '. ');
        $dot = strrpos($query, '.');
        return $dot === false ? $query : substr($query, $dot + 1);
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
        if (count($current) < 12) {
            $current[] = ['field'=>$field,'value'=>$value];
            $matches[$fileId] = $current;
        }
    }
}
