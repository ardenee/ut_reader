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
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

final class PdoGameDependencyCrossExamineQuery
{
    private const SOURCE_PACKAGE_CHUNK = 250;

    private readonly string $storageRoot;
    private readonly Uedb5MetadataReader $v5Reader;
    private readonly Uedb5ParityV5ReadService $v5Dependencies;

    /** @var array<int,array<string,mixed>> */
    private array $snapshotCache = [];

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
        $this->v5Reader = new Uedb5MetadataReader($this->storageRoot);
        $config['storage_path'] = $this->storageRoot;
        $this->v5Dependencies = new Uedb5ParityV5ReadService($db, $config);
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

        $packageStatsRows = \catalog_all(
            $this->db,
            'SELECT p.required_package_name required_package,'
            . 'SUM(p.missing_count) missing_count,COUNT(DISTINCT p.file_id) owner_count '
            . 'FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files owner ON owner.id=p.file_id AND owner.game_id=p.game_id AND owner.scan_status="verified" '
            . 'WHERE p.game_id=? AND p.missing_count>0 '
            . 'GROUP BY p.required_package_name',
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
            $isCurrentMetadata = (int)($source['metadata_format_version'] ?? 0) === Uedb5MetadataContainer::FORMAT_VERSION;
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
        $affected = \catalog_all(
            $this->db,
            'SELECT DISTINCT p.file_id FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'WHERE p.game_id=? AND p.required_package_name=? AND p.missing_count>0',
            [$targetGameId, $packageName]
        );

        $providerSnapshot = $this->snapshotForFile($sourceFileId);
        $providerTables = Uedb5ClassicDependencyResolver::normalizedTables($providerSnapshot);
        $providerPackageName = (string)($providerSnapshot['file']['package_name'] ?? $packageName);
        $providerSummary = (array)($providerSnapshot['sections']['summary'][0] ?? []);
        $providerVersion = (int)($providerSummary['package_version'] ?? 0);

        $consumers = [];
        $complete = 0;
        $partial = 0;
        foreach ($affected as $affectedRow) {
            $consumerId = (int)($affectedRow['file_id'] ?? 0);
            if ($consumerId < 1) {
                continue;
            }

            $requirements = [];
            foreach ($this->v5Dependencies->dependencies($targetGameId, $consumerId) as $dependency) {
                if (strcasecmp((string)($dependency['required_package'] ?? ''), $packageName) !== 0) {
                    continue;
                }
                $path = trim((string)($dependency['required_object_path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $requirements[(int)($dependency['source_index'] ?? -1)] = ['path' => $path];
            }
            if ($requirements === []) {
                continue;
            }

            $consumerSnapshot = $this->snapshotForFile($consumerId);
            $consumerTables = Uedb5ClassicDependencyResolver::normalizedTables($consumerSnapshot);
            $consumerSummary = (array)($consumerSnapshot['sections']['summary'][0] ?? []);
            $consumerVersion = (int)($consumerSummary['package_version'] ?? 0);
            $consumerLicensee = (int)($consumerSummary['licensee_version'] ?? 0);
            $outcomes = [];

            if ($targetEngine === 'UE1') {
                $profile = $this->ue1VerifyImportProfile($targetGameId, $consumerVersion, $consumerLicensee);
                if ($profile !== null) {
                    $outcomes = PdoUe1VerifyImportProjectionResolver::resolveInMemoryOutcome(
                        $profile,
                        array_values($consumerTables['imports']),
                        array_values($providerTables['imports']),
                        array_values($providerTables['exports']),
                        $providerPackageName,
                        $consumerVersion,
                        $providerVersion
                    );
                }
            } elseif ($targetEngine === 'UE2') {
                $profile = $this->ue2VerifyImportProfile($targetGameId, $consumerVersion);
                if ($profile !== null) {
                    $outcomes = PdoUe2VerifyImportProjectionResolver::resolveInMemoryOutcome(
                        $profile,
                        array_values($consumerTables['imports']),
                        array_values($providerTables['imports']),
                        array_values($providerTables['exports']),
                        $providerPackageName
                    );
                }
            } elseif ($targetEngine === 'UE3') {
                $profile = strtolower(trim((string)($target['slug'] ?? ''))) === 'ut3'
                    && $consumerVersion === 512
                    && $consumerLicensee === 0
                        ? PdoUe3VerifyImportProjectionResolver::PROFILE_UT3_V512
                        : null;
                if ($profile !== null) {
                    $outcomes = PdoUe3VerifyImportProjectionResolver::resolveInMemoryOutcome(
                        $profile,
                        array_values($consumerTables['imports']),
                        array_values($providerTables['imports']),
                        array_values($providerTables['exports']),
                        $providerPackageName,
                        $providerVersion,
                        array_values($consumerTables['exports'])
                    );
                }
            } elseif ($targetEngine === 'UE4') {
                $profile = strtolower(trim((string)($target['slug'] ?? ''))) === 'ut4'
                    && $consumerVersion >= 214
                    && $consumerVersion <= 511
                    && $consumerLicensee === 0
                        ? PdoUe4VerifyImportProjectionResolver::PROFILE_UT4_CLEAN_MASTER
                        : null;
                if ($profile !== null) {
                    $resolved = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
                        array_values($consumerTables['imports']),
                        array_values($providerTables['imports']),
                        array_values($providerTables['exports']),
                        $providerPackageName,
                        array_values($consumerTables['exports']),
                        array_values($consumerTables['imports'])
                    );
                    $outcomes = (array)($resolved['source_outcomes'] ?? []);
                }
            }

            $matchedPaths = [];
            $missingPaths = [];
            foreach ($requirements as $importIndex => $requirement) {
                $outcome = (array)($outcomes[(int)$importIndex] ?? []);
                if (($outcome['status'] ?? '') === 'resolved') {
                    $matchedPaths[] = (string)$requirement['path'];
                } else {
                    $missingPaths[] = (string)$requirement['path'];
                }
            }

            $requiredCount = count($requirements);
            $matchedCount = count($matchedPaths);
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

    /** @return array<string,mixed> */
    private function snapshotForFile(int $fileId): array
    {
        if (isset($this->snapshotCache[$fileId])) {
            return $this->snapshotCache[$fileId];
        }
        $statement = $this->db->prepare(
            'SELECT game_id FROM ue_uedb5_files WHERE file_id=? AND format_version=? LIMIT 1'
        );
        $statement->execute([$fileId, Uedb5MetadataContainer::FORMAT_VERSION]);
        $gameId = (int)($statement->fetchColumn() ?: 0);
        if ($gameId < 1) {
            throw new \RuntimeException('Verified file #' . $fileId . ' has no UEDB5 metadata.');
        }
        return $this->snapshotCache[$fileId] = $this->v5Reader->snapshot($gameId, $fileId);
    }

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
                . 'g.name source_game_name,COALESCE(p.engine_key,"") source_engine,v.format_version metadata_format_version '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
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

    private function ue1VerifyImportProfile(int $gameId, int $packageVersion, int $licenseeVersion): ?string
    {
        if ($packageVersion <= 0 || $licenseeVersion !== 0) { return null; }
        try { $sourceKey = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry::sourceKey($gameId); }
        catch (\Throwable) { return null; }
        if ($sourceKey === 'ut99' && $packageVersion <= 68) {
            return PdoUe1VerifyImportProjectionResolver::PROFILE_UT99_V1400;
        }
        if ($sourceKey === 'unrealgold' && $packageVersion < 60) {
            return PdoUe1VerifyImportProjectionResolver::PROFILE_UNREAL_V120;
        }
        return null;
    }

    private function ue2VerifyImportProfile(int $gameId, int $packageVersion): ?string
    {
        if ($packageVersion <= 0) { return null; }
        try { $sourceKey = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry::sourceKey($gameId); }
        catch (\Throwable) { return null; }
        if ($sourceKey === 'unreal2' && $packageVersion >= 60 && $packageVersion <= 69) {
            return PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000;
        }
        if ($sourceKey === 'ut2003' && $packageVersion >= 60 && $packageVersion <= 120) {
            return PdoUe2VerifyImportProjectionResolver::PROFILE_UT2003_V2107;
        }
        if ($sourceKey === 'ut2004' && $packageVersion >= 60 && $packageVersion <= 129) {
            return PdoUe2VerifyImportProjectionResolver::PROFILE_UT2004_V129;
        }
        return null;
    }

    /** @return array{version:int,licensee:int} */
    private function consumerPackageIdentity(int $fileId): array
    {
        $row = \catalog_one(
            $this->db,
            'SELECT package_version,licensee_version FROM ue_files WHERE id=? LIMIT 1',
            [$fileId]
        ) ?: [];
        return [
            'version'=>(int)($row['package_version'] ?? 0),
            'licensee'=>(int)($row['licensee_version'] ?? 0),
        ];
    }

    private function verificationPolicyLabel(array $target): string
    {
        $engine = strtoupper(trim((string)($target['engine_key'] ?? '')));
        if ($engine === 'UE1') { return 'profiled_ue1_verify_import'; }
        if ($engine === 'UE2') { return 'profiled_ue2_verify_import'; }
        if ($engine === 'UE3') {
            return strtolower(trim((string)($target['slug'] ?? ''))) === 'ut3'
                ? 'ut3_v512_verify_import'
                : 'source_profile_unavailable';
        }
        if ($engine === 'UE4') {
            return strtolower(trim((string)($target['slug'] ?? ''))) === 'ut4'
                ? 'ut4_clean_master_v511_verify_import'
                : 'source_profile_unavailable';
        }
        return 'complete_package_object';
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
