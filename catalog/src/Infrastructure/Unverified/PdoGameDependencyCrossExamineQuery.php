<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finds verified packages in sibling games that satisfy actual missing dependencies in a target game.
 * Why: Cross-game repair must evaluate each candidate with the target game's authoritative dependency semantics.
 * Role: Read model for the cross-game dependency examination admin workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassRemapRepository;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoLegacyVerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

final class PdoGameDependencyCrossExamineQuery
{
    private const SOURCE_PACKAGE_CHUNK = 250;

    private readonly string $storageRoot;

    /** @var array<int,list<array<string,mixed>>> */
    private array $consumerImportCache = [];

    /** @var array<int,list<array<string,mixed>>> */
    private array $consumerExportCache = [];

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogPackageAliases.php';

        $this->storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($this->storageRoot === '') {
            throw new \RuntimeException('Catalog storage_path is required for dependency cross-examination.');
        }
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
        $targetEngine = strtoupper(trim((string)$target['engine_key']));
        $targetEngineGeneration = $this->engineGeneration($targetEngine);

        $sourceGames = \catalog_all(
            $this->db,
            'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id<>? ORDER BY p.engine_key,g.name',
            [$targetGameId]
        );
        $sourceGames = array_values(array_filter(
            $sourceGames,
            fn(array $game): bool => $this->isCompatibleSourceEngine(
                $targetEngineGeneration,
                $this->engineGeneration((string)($game['engine_key'] ?? ''))
            )
        ));

        $allowedSourceIds = [];
        foreach ($sourceGames as $game) {
            $allowedSourceIds[(int)$game['id']] = true;
        }
        if ($sourceGameId > 0 && !isset($allowedSourceIds[$sourceGameId])) {
            throw new \RuntimeException('The selected source game is outside the dependency-compatible engine family for this target.');
        }

        $diagnostics = [
            'missing_dependency_rows' => 0,
            'missing_packages' => 0,
            'source_package_files' => 0,
            'format3_source_files' => 0,
            'exact_provider_files' => 0,
        ];
        if ($allowedSourceIds === []) {
            return $this->result($target, $sourceGames, [], $diagnostics);
        }

        PdoDependencyReadSource::sql($this->db);

        $packageStatsRows = \catalog_all(
            $this->db,
            'SELECT '
            . 'CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci required_package,'
            . 'COUNT(DISTINCT l.file_id,l.import_index) missing_count,'
            . 'COUNT(DISTINCT l.file_id) owner_count '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=' . BlockedCompressedMetadataContainer::FORMAT_VERSION . ' '
            . 'JOIN ue_files owner ON owner.id=l.file_id AND owner.scan_status="verified" '
            . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
            . 'WHERE owner.game_id=? AND l.status=0 '
            . 'GROUP BY CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci',
            [$targetGameId]
        );
        if ($packageStatsRows === []) {
            return $this->result($target, $sourceGames, [], $diagnostics);
        }

        $packageStats = [];
        $packageNames = [];
        foreach ($packageStatsRows as $row) {
            $package = trim((string)($row['required_package'] ?? ''));
            if ($package === '') {
                continue;
            }
            $key = $this->key($package);
            $missingCount = max(0, (int)($row['missing_count'] ?? 0));
            $ownerCount = max(0, (int)($row['owner_count'] ?? 0));
            $packageStats[$key] = [
                'missing_count' => $missingCount,
                'owner_count' => $ownerCount,
            ];
            $packageNames[$key] = $package;
            $diagnostics['missing_dependency_rows'] += $missingCount;
        }
        $diagnostics['missing_packages'] = count($packageNames);
        if ($packageNames === []) {
            return $this->result($target, $sourceGames, [], $diagnostics);
        }

        $sourceIds = $sourceGameId > 0 ? [$sourceGameId] : array_keys($allowedSourceIds);
        $sources = $this->sourceFilesForMissingPackages(
            $targetGameId,
            $sourceIds,
            array_values($packageNames)
        );
        $diagnostics['source_package_files'] = count($sources);
        if ($sources === []) {
            return $this->result($target, $sourceGames, [], $diagnostics);
        }

        $rows = [];
        foreach ($sources as $source) {
            $sourceFileId = (int)($source['id'] ?? 0);
            if ($sourceFileId < 1) {
                continue;
            }
            $isCurrentMetadata = (int)($source['metadata_format_version'] ?? 0) === BlockedCompressedMetadataContainer::FORMAT_VERSION;
            if ($isCurrentMetadata) {
                $diagnostics['format3_source_files']++;
            }

            $packageName = trim((string)($source['package_name'] ?? ''));
            $packageKey = $this->key($packageName);
            $stats = $packageStats[$packageKey] ?? null;
            if (!is_array($stats)) {
                continue;
            }

            $coverage = $isCurrentMetadata
                ? $this->completeConsumerCoverage($target, $sourceFileId, $packageName)
                : [
                    'complete_consumer_count' => 0,
                    'partial_consumer_count' => 0,
                    'consumers' => [],
                ];

            $requiredTotal = 0;
            $matchedTotal = 0;
            $matchedOwners = 0;
            foreach ((array)$coverage['consumers'] as $consumer) {
                $requiredTotal += max(0, (int)($consumer['required_count'] ?? 0));
                $matched = max(0, (int)($consumer['matched_count'] ?? 0));
                $matchedTotal += $matched;
                if ($matched > 0) {
                    $matchedOwners++;
                }
            }
            if ($matchedTotal > 0) {
                $diagnostics['exact_provider_files']++;
            }

            $missingCount = max(1, (int)($stats['missing_count'] ?? 0));
            $ownerCount = max(0, (int)($stats['owner_count'] ?? 0));
            $rows[] = $source + [
                'target_game_id' => $targetGameId,
                'target_game_name' => (string)$target['name'],
                'target_missing_count' => $missingCount,
                'target_owner_count' => $ownerCount,
                // Kept for the presentation model. These are now authoritative
                // target-game VerifyImport matches, not path-hash prefilter hits.
                'exact_object_matches' => $matchedTotal,
                'exact_owner_count' => $matchedOwners,
                'coverage_percent' => $requiredTotal > 0 ? round(($matchedTotal / $requiredTotal) * 100, 1) : 0.0,
                'complete_consumer_count' => (int)$coverage['complete_consumer_count'],
                'partial_consumer_count' => (int)$coverage['partial_consumer_count'],
                'consumer_coverage' => (array)$coverage['consumers'],
                'verification_engine' => $targetEngine,
                'verification_policy' => $this->verificationPolicyLabel($target),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return ((int)($right['complete_consumer_count'] ?? 0) <=> (int)($left['complete_consumer_count'] ?? 0))
                ?: ((int)($right['exact_object_matches'] ?? 0) <=> (int)($left['exact_object_matches'] ?? 0))
                ?: ((float)($right['coverage_percent'] ?? 0) <=> (float)($left['coverage_percent'] ?? 0))
                ?: ((int)($right['exact_owner_count'] ?? 0) <=> (int)($left['exact_owner_count'] ?? 0))
                ?: strcasecmp((string)$left['package_name'], (string)$right['package_name'])
                ?: strcasecmp((string)$left['source_game_name'], (string)$right['source_game_name']);
        });
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $this->result($target, $sourceGames, $rows, $diagnostics);
    }

    /** @return array<string,mixed>|null */
    public function one(int $sourceFileId, int $targetGameId): ?array
    {
        if ($sourceFileId < 1 || $targetGameId < 1) {
            return null;
        }

        // Queue-time revalidation deliberately comes back through fetch(), so the
        // report and the import action cannot disagree about engine semantics.
        $source = \catalog_one(
            $this->db,
            'SELECT game_id FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
            [$sourceFileId]
        );
        $sourceGameId = (int)($source['game_id'] ?? 0);
        if ($sourceGameId < 1 || $sourceGameId === $targetGameId) {
            return null;
        }

        $result = $this->fetch($targetGameId, $sourceGameId, 500);
        foreach ((array)($result['rows'] ?? []) as $row) {
            if ((int)($row['id'] ?? 0) !== $sourceFileId) {
                continue;
            }
            return (int)($row['complete_consumer_count'] ?? 0) > 0 ? $row : null;
        }
        return null;
    }

    /**
     * Evaluate every affected consumer against one physical candidate package.
     *
     * Consumer Imports are loaded from authoritative compact metadata. UE1/UE2,
     * UE3, and UE4 are delegated to the same source-backed VerifyImport resolvers
     * used by normal dependency rebuilding. Profiles without a registered source
     * resolver fail closed and cannot be promoted to queueable repair candidates.
     *
     * @param array<string,mixed> $target
     * @return array{complete_consumer_count:int,partial_consumer_count:int,consumers:list<array<string,mixed>>}
     */
    private function completeConsumerCoverage(array $target, int $sourceFileId, string $packageName): array
    {
        $targetGameId = (int)($target['id'] ?? 0);
        $targetEngine = strtoupper(trim((string)($target['engine_key'] ?? '')));
        $legacyPolicy = $this->legacyVerifyImportPolicy($target);
        $classRemaps = $legacyPolicy === 'unreal2'
            ? (new PdoClassRemapRepository($this->db))->mappingsForGame($targetGameId)
            : [];

        $affected = \catalog_all(
            $this->db,
            'SELECT DISTINCT l.file_id FROM ue_dependency_links l '
            . 'JOIN ue_files f ON f.id=l.file_id AND f.game_id=? AND f.scan_status="verified" '
            . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
            . 'WHERE l.status=0 AND CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci=?',
            [$targetGameId, $packageName]
        );

        $consumers = [];
        $complete = 0;
        $partial = 0;
        foreach ($affected as $affectedRow) {
            $consumerId = (int)($affectedRow['file_id'] ?? 0);
            if ($consumerId < 1) {
                continue;
            }

            $allImports = $this->consumerImports($consumerId);
            $requirements = $this->packageObjectRequirements($allImports, $packageName);
            if ($requirements === []) {
                continue;
            }

            $requiredCount = count($requirements);
            $matchedIndexes = [];
            $matchedPaths = [];
            $missingPaths = [];

            if ($legacyPolicy !== null) {
                require_once dirname(__DIR__) . '/Persistence/PdoLegacyVerifyImportProjectionResolver.php';
                $variants = PdoLegacyVerifyImportProjectionResolver::resolveProviderVariants(
                    $this->db,
                    $sourceFileId,
                    $allImports,
                    $classRemaps
                );
                $matches = (array)($variants[$legacyPolicy] ?? []);
                foreach ($requirements as $importIndex => $requirement) {
                    if (array_key_exists($importIndex, $matches)) {
                        $matchedIndexes[$importIndex] = (int)$matches[$importIndex];
                        $matchedPaths[] = (string)$requirement['path'];
                    } else {
                        $missingPaths[] = (string)$requirement['path'];
                    }
                }
            } elseif ($targetEngine === 'UE3') {
                require_once dirname(__DIR__) . '/Persistence/PdoUe3VerifyImportProjectionResolver.php';
                $matches = PdoUe3VerifyImportProjectionResolver::resolveProvider(
                    $this->db,
                    $sourceFileId,
                    $allImports
                );
                foreach ($requirements as $importIndex => $requirement) {
                    if (array_key_exists($importIndex, $matches)) {
                        $matchedIndexes[$importIndex] = (int)$matches[$importIndex];
                        $matchedPaths[] = (string)$requirement['path'];
                    } else {
                        $missingPaths[] = (string)$requirement['path'];
                    }
                }
            } elseif ($targetEngine === 'UE4') {
                require_once dirname(__DIR__) . '/Persistence/PdoUe4VerifyImportProjectionResolver.php';
                $outcome = PdoUe4VerifyImportProjectionResolver::resolveProviderOutcome(
                    $this->db,
                    $sourceFileId,
                    $allImports,
                    $this->consumerExports($consumerId),
                    $allImports
                );
                $matches = (array)($outcome['matches'] ?? []);
                foreach ($requirements as $importIndex => $requirement) {
                    if (array_key_exists($importIndex, $matches)) {
                        $matchedIndexes[$importIndex] = (int)$matches[$importIndex];
                        $matchedPaths[] = (string)$requirement['path'];
                    } else {
                        $missingPaths[] = (string)$requirement['path'];
                    }
                }
            } else {
                // Do not substitute path/class coverage for a missing engine source
                // resolver. A cross-game copy candidate may be displayed elsewhere,
                // but it cannot be certified or queued as dependency-complete here.
                foreach ($requirements as $requirement) {
                    $missingPaths[] = (string)$requirement['path'];
                }
            }

            $matchedCount = count($matchedIndexes);
            $missingCount = max(0, $requiredCount - $matchedCount);
            $isComplete = $missingCount === 0;
            if ($isComplete) {
                $complete++;
            } else {
                $partial++;
            }

            $consumerFile = \catalog_one(
                $this->db,
                'SELECT original_name,package_name FROM ue_files WHERE id=? LIMIT 1',
                [$consumerId]
            ) ?: [];
            $consumers[] = [
                'file_id' => $consumerId,
                'file_name' => trim((string)($consumerFile['original_name'] ?? '')) ?: ('File #' . $consumerId),
                'package_name' => trim((string)($consumerFile['package_name'] ?? '')),
                'required_count' => $requiredCount,
                'matched_count' => $matchedCount,
                'missing_count' => $missingCount,
                'status' => $isComplete
                    ? 'fully_satisfies'
                    : ($matchedCount > 0 ? 'partially_satisfies' : 'does_not_satisfy'),
                'matched_paths' => $matchedPaths,
                'missing_paths' => $missingPaths,
            ];
        }

        return [
            'complete_consumer_count' => $complete,
            'partial_consumer_count' => $partial,
            'consumers' => $consumers,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function consumerImports(int $consumerFileId): array
    {
        if (isset($this->consumerImportCache[$consumerFileId])) {
            return $this->consumerImportCache[$consumerFileId];
        }
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($this->db, $this->storageRoot))
            ->loadDependencySnapshot($consumerFileId);
        $imports = [];
        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            if (is_array($row)) {
                $imports[] = $row;
            }
        }
        return $this->consumerImportCache[$consumerFileId] = $imports;
    }

    /** @return list<array<string,mixed>> */
    private function consumerExports(int $consumerFileId): array
    {
        if (isset($this->consumerExportCache[$consumerFileId])) {
            return $this->consumerExportCache[$consumerFileId];
        }
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($this->db, $this->storageRoot))
            ->loadDependencySnapshot($consumerFileId, true);
        if (!isset($this->consumerImportCache[$consumerFileId])) {
            $this->consumerImportCache[$consumerFileId] = array_values(array_filter(
                (array)($snapshot['imports'] ?? []),
                'is_array'
            ));
        }
        return $this->consumerExportCache[$consumerFileId] = array_values(array_filter(
            (array)($snapshot['exports'] ?? []),
            'is_array'
        ));
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @return array<int,array{path:string,relative_path:string,class_package:string,class_name:string}>
     */
    private function packageObjectRequirements(array $imports, string $packageName): array
    {
        $packageKey = $this->key($packageName);
        $requirements = [];
        foreach ($imports as $fallback => $import) {
            if ($this->key((string)($import['root_package'] ?? '')) !== $packageKey) {
                continue;
            }
            $relative = trim((string)($import['relative_object_path'] ?? ''));
            if ($relative === '') {
                // Package-only Imports prove that the package linker is required,
                // but they do not require an Export object.
                continue;
            }
            $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            $fullPath = trim((string)($import['full_path'] ?? ''));
            if ($fullPath === '') {
                $fullPath = $packageName . '.' . $relative;
            }
            $requirements[$importIndex] = [
                'path' => $fullPath,
                'relative_path' => $relative,
                'class_package' => trim((string)($import['class_package'] ?? '')),
                'class_name' => trim((string)($import['class_name'] ?? '')),
            ];
        }
        ksort($requirements);
        return $requirements;
    }

    /**
     * @param list<int> $sourceGameIds
     * @param list<string> $packageNames
     * @return list<array<string,mixed>>
     */
    private function sourceFilesForMissingPackages(
        int $targetGameId,
        array $sourceGameIds,
        array $packageNames
    ): array {
        $sourceGameIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceGameIds),
            static fn(int $id): bool => $id > 0
        )));
        $packageNames = array_values(array_unique(array_filter(
            array_map(static fn(string $name): string => trim($name), $packageNames),
            static fn(string $name): bool => $name !== ''
        )));
        if ($targetGameId < 1 || $sourceGameIds === [] || $packageNames === []) {
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
                . 'g.name source_game_name,COALESCE(p.engine_key,"") source_engine,m.format_version metadata_format_version '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
                . 'WHERE f.scan_status="verified" AND f.game_id IN (' . $gamePlaceholders . ') '
                . 'AND f.package_name IN (' . $packagePlaceholders . ') '
                . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
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
            'SELECT g.id,g.name,g.slug,g.profile_id,p.profile_name,p.engine_key,p.notes '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
            [$gameId]
        );
        if (!$row) {
            throw new \RuntimeException('Target game or active profile was not found.');
        }
        return $row;
    }

    /**
     * Mirrors the target-game policy selection in PdoDependencyResolver. The
     * actual matching is delegated to the same resolver class, so this method only
     * chooses which source-backed UE1/UE2 variant applies to the selected game.
     */
    private function legacyVerifyImportPolicy(array $target): ?string
    {
        $engine = strtoupper(trim((string)($target['engine_key'] ?? '')));
        if (!in_array($engine, ['UE1', 'UE2'], true)) {
            return null;
        }
        if ($engine === 'UE2') {
            $identity = strtolower(implode(' ', [
                (string)($target['profile_name'] ?? ''),
                (string)($target['notes'] ?? ''),
                (string)($target['name'] ?? ''),
                (string)($target['slug'] ?? ''),
            ]));
            if (preg_match('/\bunreal[ _-]*ii\b|\bunreal[ _-]*2\b/', $identity) === 1) {
                return 'unreal2';
            }
        }
        return 'standard';
    }

    private function verificationPolicyLabel(array $target): string
    {
        $legacy = $this->legacyVerifyImportPolicy($target);
        if ($legacy === 'unreal2') {
            return 'unreal2_verify_import';
        }
        if ($legacy === 'standard') {
            return 'legacy_verify_import';
        }
        return strtoupper(trim((string)($target['engine_key'] ?? ''))) === 'UE3'
            ? 'ue3_verify_import'
            : 'complete_package_object';
    }

    /**
     * @param array<string,mixed> $target
     * @param list<array<string,mixed>> $sourceGames
     * @param list<array<string,mixed>> $rows
     * @param array<string,int> $diagnostics
     * @return array<string,mixed>
     */
    private function result(array $target, array $sourceGames, array $rows, array $diagnostics): array
    {
        return [
            'target' => $target,
            'source_games' => $sourceGames,
            'rows' => $rows,
            'diagnostics' => $diagnostics,
        ];
    }

    private function isCompatibleSourceEngine(int $targetGeneration, int $sourceGeneration): bool
    {
        if ($targetGeneration < 1 || $sourceGeneration < 1) {
            return false;
        }

        return match ($targetGeneration) {
            1 => $sourceGeneration === 1,
            2 => $sourceGeneration === 1 || $sourceGeneration === 2,
            3 => $sourceGeneration === 3,
            4 => $sourceGeneration === 4,
            5 => $sourceGeneration === 4 || $sourceGeneration === 5,
            default => false,
        };
    }

    private function engineGeneration(string $engineKey): int
    {
        $key = strtoupper(trim($engineKey));
        if (preg_match('/^UE([1-5])(?:\D|$)/', $key, $matches) === 1) {
            return (int)$matches[1];
        }
        if (preg_match('/UNREAL(?:ENGINE)?[^0-9]*([1-5])/', $key, $matches) === 1) {
            return (int)$matches[1];
        }
        return 0;
    }

    private function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
