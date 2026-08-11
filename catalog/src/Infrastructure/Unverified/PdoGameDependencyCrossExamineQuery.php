<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finds verified packages in sibling games that satisfy actual missing dependency objects in a target game.
 * Why: Cross-game repair must be driven by unresolved dependency rows, not by package-summary limits or target profile heuristics.
 * Role: Read model for the cross-game dependency examination admin workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\CompressedFileMetadataReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class PdoGameDependencyCrossExamineQuery
{
    private const SOURCE_PACKAGE_CHUNK = 250;

    private readonly CompressedFileMetadataReader $metadata;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->metadata = new CompressedFileMetadataReader(
            $db,
            rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
        );
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.profile_id,p.engine_key,p.profile_name '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'ORDER BY p.engine_key,g.name'
        );
    }

    /**
     * @return array{
     *   target:array<string,mixed>,
     *   source_games:list<array<string,mixed>>,
     *   rows:list<array<string,mixed>>,
     *   diagnostics:array<string,int>
     * }
     */
    public function fetch(int $targetGameId, int $sourceGameId = 0, int $limit = 100): array
    {
        $limit = max(10, min(500, $limit));
        $target = $this->targetGame($targetGameId);
        $engine = strtoupper(trim((string)$target['engine_key']));

        // Source selection stays within the configured engine family. Package
        // profile/version ranges do NOT veto a provider: the authoritative test
        // is missing required object -> exported source object.
        $sourceGames = \catalog_all(
            $this->db,
            'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE UPPER(p.engine_key)=? AND g.id<>? ORDER BY g.name',
            [$engine, $targetGameId]
        );
        $allowedSourceIds = [];
        foreach ($sourceGames as $game) {
            $allowedSourceIds[(int)$game['id']] = true;
        }
        if ($sourceGameId > 0 && !isset($allowedSourceIds[$sourceGameId])) {
            throw new \RuntimeException('The selected source game is not a sibling ' . $engine . ' game.');
        }

        $diagnostics = [
            'missing_dependency_rows' => 0,
            'missing_packages' => 0,
            'source_package_files' => 0,
            'metadata_unreadable' => 0,
            'exact_provider_files' => 0,
        ];
        if ($allowedSourceIds === []) {
            return [
                'target' => $target,
                'source_games' => $sourceGames,
                'rows' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        // Start directly from authoritative compact dependency rows. The old
        // package-summary projection and LIMIT 500 are intentionally not used.
        $dependencySource = PdoDependencyReadSource::sql($this->db);
        $dependencyRows = \catalog_all(
            $this->db,
            'SELECT d.id,d.file_id owner_file_id,d.required_package,d.required_object_path '
            . 'FROM ' . $dependencySource . ' d '
            . 'JOIN ue_files owner ON owner.id=d.file_id AND owner.scan_status="verified" '
            . 'WHERE owner.game_id=? AND d.status="missing" '
            . 'AND d.required_package IS NOT NULL AND d.required_package<>"" '
            . 'AND d.required_object_path IS NOT NULL AND d.required_object_path<>""',
            [$targetGameId]
        );
        $diagnostics['missing_dependency_rows'] = count($dependencyRows);
        if ($dependencyRows === []) {
            return [
                'target' => $target,
                'source_games' => $sourceGames,
                'rows' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $dependenciesByPackage = [];
        $packageNames = [];
        $packageStats = [];
        foreach ($dependencyRows as $row) {
            $package = trim((string)($row['required_package'] ?? ''));
            $objectPath = trim((string)($row['required_object_path'] ?? ''));
            if ($package === '' || $objectPath === '') {
                continue;
            }
            $packageKey = $this->key($package);
            $dependencyId = (int)($row['id'] ?? 0);
            $ownerId = (int)($row['owner_file_id'] ?? 0);
            $dependenciesByPackage[$packageKey][] = $row;
            $packageNames[$packageKey] = $package;
            if ($dependencyId > 0) {
                $packageStats[$packageKey]['dependencies'][$dependencyId] = true;
            }
            if ($ownerId > 0) {
                $packageStats[$packageKey]['owners'][$ownerId] = true;
            }
        }
        $diagnostics['missing_packages'] = count($packageNames);
        if ($packageNames === []) {
            return [
                'target' => $target,
                'source_games' => $sourceGames,
                'rows' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $sourceIds = $sourceGameId > 0 ? [$sourceGameId] : array_keys($allowedSourceIds);
        $sources = $this->sourceFilesForMissingPackages($sourceIds, array_values($packageNames));
        $diagnostics['source_package_files'] = count($sources);
        if ($sources === []) {
            return [
                'target' => $target,
                'source_games' => $sourceGames,
                'rows' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        $rows = [];
        /** @var array<string,array<string,bool>|null> $exportCache */
        $exportCache = [];
        foreach ($sources as $source) {
            $packageKey = $this->key((string)$source['package_name']);
            $dependencies = $dependenciesByPackage[$packageKey] ?? [];
            if ($dependencies === []) {
                continue;
            }

            // Identical bytes can already be registered in more than one sibling
            // game. Reuse their export set, but keep each source-game row visible.
            $identityKey = strtolower(trim((string)($source['md5'] ?? '')));
            if ($identityKey === '') {
                $identityKey = 'file:' . (int)$source['id'];
            }
            if (!array_key_exists($identityKey, $exportCache)) {
                try {
                    $exports = $this->metadata->exports((int)$source['id']);
                    $paths = [];
                    foreach ($exports as $export) {
                        $path = trim((string)($export['full_path'] ?? ''));
                        if ($path !== '') {
                            $paths[$this->key($path)] = true;
                        }
                    }
                    $exportCache[$identityKey] = $paths;
                } catch (Throwable) {
                    $exportCache[$identityKey] = null;
                    $diagnostics['metadata_unreadable']++;
                }
            }
            $exportPaths = $exportCache[$identityKey];
            if (!is_array($exportPaths) || $exportPaths === []) {
                continue;
            }

            $exact = 0;
            $exactOwners = [];
            foreach ($dependencies as $dependency) {
                $requiredObject = trim((string)($dependency['required_object_path'] ?? ''));
                if ($requiredObject === '') {
                    continue;
                }
                if (isset($exportPaths[$this->key($requiredObject)])) {
                    $exact++;
                    $ownerId = (int)($dependency['owner_file_id'] ?? 0);
                    if ($ownerId > 0) {
                        $exactOwners[$ownerId] = true;
                    }
                }
            }
            if ($exact < 1) {
                continue;
            }

            $stats = $packageStats[$packageKey] ?? [];
            $missingCount = count((array)($stats['dependencies'] ?? []));
            if ($missingCount < 1) {
                $missingCount = count($dependencies);
            }
            $ownerCount = count((array)($stats['owners'] ?? []));
            if ($ownerCount < 1) {
                $ownerCount = count(array_unique(array_filter(array_map(
                    static fn(array $dependency): int => (int)($dependency['owner_file_id'] ?? 0),
                    $dependencies
                ))));
            }

            // Do not hide an exact provider just because the same bytes are
            // already registered in the target. Showing it diagnoses stale target
            // dependency status; the copy action will refuse a duplicate identity.
            $targetExisting = null;
            $md5 = strtolower(trim((string)($source['md5'] ?? '')));
            if ($md5 !== '') {
                $targetExisting = \catalog_one(
                    $this->db,
                    'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',
                    [$targetGameId, $md5]
                );
            }

            $rows[] = $source + [
                'target_game_id' => $targetGameId,
                'target_game_name' => (string)$target['name'],
                'target_missing_count' => max(1, $missingCount),
                'target_owner_count' => max(0, $ownerCount),
                'exact_object_matches' => $exact,
                'exact_owner_count' => count($exactOwners),
                'coverage_percent' => round(($exact / max(1, $missingCount)) * 100, 1),
                'target_existing_file_id' => (int)($targetExisting['id'] ?? 0),
                'already_in_target' => (int)($targetExisting['id'] ?? 0) > 0,
            ];
        }

        $diagnostics['exact_provider_files'] = count($rows);
        usort($rows, static function (array $left, array $right): int {
            return ($right['exact_object_matches'] <=> $left['exact_object_matches'])
                ?: ($right['coverage_percent'] <=> $left['coverage_percent'])
                ?: ($right['exact_owner_count'] <=> $left['exact_owner_count'])
                ?: strcasecmp((string)$left['package_name'], (string)$right['package_name'])
                ?: strcasecmp((string)$left['source_game_name'], (string)$right['source_game_name']);
        });
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'target' => $target,
            'source_games' => $sourceGames,
            'rows' => $rows,
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array<string,mixed>|null */
    public function one(int $sourceFileId, int $targetGameId): ?array
    {
        if ($sourceFileId < 1 || $targetGameId < 1) {
            return null;
        }

        $source = \catalog_one(
            $this->db,
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.relative_path,f.extension,f.file_size,'
            . 'f.md5,f.sha1,f.package_guid,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
            . 'g.name source_game_name,COALESCE(p.engine_key,"") source_engine '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1',
            [$sourceFileId]
        );
        if (!$source || (int)$source['game_id'] === $targetGameId) {
            return null;
        }

        $target = $this->targetGame($targetGameId);
        // Same configured engine family is the only compatibility gate here.
        if (strcasecmp((string)$source['source_engine'], (string)$target['engine_key']) !== 0) {
            return null;
        }

        $dependencySource = PdoDependencyReadSource::sql($this->db);
        $dependencies = \catalog_all(
            $this->db,
            'SELECT d.id,d.file_id owner_file_id,d.required_object_path '
            . 'FROM ' . $dependencySource . ' d '
            . 'JOIN ue_files owner ON owner.id=d.file_id AND owner.scan_status="verified" '
            . 'WHERE owner.game_id=? AND d.status="missing" AND d.required_package=? '
            . 'AND d.required_object_path IS NOT NULL AND d.required_object_path<>""',
            [$targetGameId, (string)$source['package_name']]
        );
        if ($dependencies === []) {
            return null;
        }

        try {
            $exports = $this->metadata->exports($sourceFileId);
        } catch (Throwable) {
            return null;
        }
        $exportPaths = [];
        foreach ($exports as $export) {
            $path = trim((string)($export['full_path'] ?? ''));
            if ($path !== '') {
                $exportPaths[$this->key($path)] = true;
            }
        }
        if ($exportPaths === []) {
            return null;
        }

        $exact = 0;
        $owners = [];
        $allOwners = [];
        foreach ($dependencies as $dependency) {
            $ownerId = (int)($dependency['owner_file_id'] ?? 0);
            if ($ownerId > 0) {
                $allOwners[$ownerId] = true;
            }
            $requiredObject = trim((string)($dependency['required_object_path'] ?? ''));
            if ($requiredObject !== '' && isset($exportPaths[$this->key($requiredObject)])) {
                $exact++;
                if ($ownerId > 0) {
                    $owners[$ownerId] = true;
                }
            }
        }
        if ($exact < 1) {
            return null;
        }

        $targetExisting = null;
        $md5 = strtolower(trim((string)($source['md5'] ?? '')));
        if ($md5 !== '') {
            $targetExisting = \catalog_one(
                $this->db,
                'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',
                [$targetGameId, $md5]
            );
        }

        return $source + [
            'target_game_id' => $targetGameId,
            'target_game_name' => (string)$target['name'],
            'target_missing_count' => count($dependencies),
            'target_owner_count' => count($allOwners),
            'exact_object_matches' => $exact,
            'exact_owner_count' => count($owners),
            'coverage_percent' => round(($exact / max(1, count($dependencies))) * 100, 1),
            'target_existing_file_id' => (int)($targetExisting['id'] ?? 0),
            'already_in_target' => (int)($targetExisting['id'] ?? 0) > 0,
        ];
    }

    /**
     * @param list<int> $sourceGameIds
     * @param list<string> $packageNames
     * @return list<array<string,mixed>>
     */
    private function sourceFilesForMissingPackages(array $sourceGameIds, array $packageNames): array
    {
        $sourceGameIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceGameIds),
            static fn(int $id): bool => $id > 0
        )));
        $packageNames = array_values(array_unique(array_filter(
            array_map(static fn(string $name): string => trim($name), $packageNames),
            static fn(string $name): bool => $name !== ''
        )));
        if ($sourceGameIds === [] || $packageNames === []) {
            return [];
        }

        $rows = [];
        $seenFileIds = [];
        $gamePlaceholders = implode(',', array_fill(0, count($sourceGameIds), '?'));
        foreach (array_chunk($packageNames, self::SOURCE_PACKAGE_CHUNK) as $packageChunk) {
            $packagePlaceholders = implode(',', array_fill(0, count($packageChunk), '?'));
            $chunkRows = \catalog_all(
                $this->db,
                'SELECT f.id,f.game_id,f.package_name,f.original_name,f.relative_path,f.extension,f.file_size,'
                . 'f.md5,f.sha1,f.package_guid,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
                . 'g.name source_game_name,COALESCE(p.engine_key,"") source_engine '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'WHERE f.scan_status="verified" AND f.game_id IN (' . $gamePlaceholders . ') '
                . 'AND f.package_name IN (' . $packagePlaceholders . ') '
                . 'ORDER BY f.package_name,g.name,f.id',
                array_merge($sourceGameIds, $packageChunk)
            );
            foreach ($chunkRows as $row) {
                $fileId = (int)($row['id'] ?? 0);
                if ($fileId < 1 || isset($seenFileIds[$fileId])) {
                    continue;
                }
                $seenFileIds[$fileId] = true;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function targetGame(int $gameId): array
    {
        if ($gameId < 1) {
            throw new \InvalidArgumentException('Choose a target game.');
        }
        $row = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.profile_id,p.profile_name,p.engine_key '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
            [$gameId]
        );
        if (!$row) {
            throw new \RuntimeException('Target game or active profile was not found.');
        }
        return $row;
    }

    private function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
