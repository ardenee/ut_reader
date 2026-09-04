<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finds verified packages in sibling games that satisfy actual missing dependency objects in a target game.
 * Why: Cross-game repair must use the same current compact dependency/export projections as normal dependency resolution.
 * Role: Read model for the cross-game dependency examination admin workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class PdoGameDependencyCrossExamineQuery
{
    private const SOURCE_PACKAGE_CHUNK = 250;
    private const SOURCE_ID_CHUNK = 250;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogPackageAliases.php';
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
            'format2_source_files' => 0,
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
            . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
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
        // Same bytes are only "already present" when the target also exposes the
        // required logical package identity (canonical or alias). If the bytes are
        // present under another package name, keep the source as a repair candidate:
        // the canonical importer can add the missing alias and refresh dependencies.
        $sources = $this->sourceFilesForMissingPackages(
            $targetGameId,
            $sourceIds,
            array_values($packageNames)
        );
        $diagnostics['source_package_files'] = count($sources);
        if ($sources === []) {
            return $this->result($target, $sourceGames, [], $diagnostics);
        }

        $sourceById = [];
        foreach ($sources as $source) {
            $sourceById[(int)$source['id']] = $source;
            if ((int)($source['metadata_format_version'] ?? 0) === 2) {
                $diagnostics['format2_source_files']++;
            }
        }

        $exactByFile = $this->exactProjectionMatches($targetGameId, array_keys($sourceById));
        $rows = [];
        foreach ($exactByFile as $sourceFileId => $exactEvidence) {
            $source = $sourceById[$sourceFileId] ?? null;
            if (!is_array($source)) {
                continue;
            }
            $packageKey = $this->key((string)$source['package_name']);
            $stats = $packageStats[$packageKey] ?? null;
            if (!is_array($stats)) {
                continue;
            }
            $missingCount = max(1, (int)($stats['missing_count'] ?? 0));
            $ownerCount = max(0, (int)($stats['owner_count'] ?? 0));
            $exact = min($missingCount, max(0, (int)($exactEvidence['exact_object_matches'] ?? 0)));
            if ($exact < 1) {
                continue;
            }
            $exactOwners = min($ownerCount, max(0, (int)($exactEvidence['exact_owner_count'] ?? 0)));

            $rows[] = $source + [
                'target_game_id' => $targetGameId,
                'target_game_name' => (string)$target['name'],
                'target_missing_count' => $missingCount,
                'target_owner_count' => $ownerCount,
                'exact_object_matches' => $exact,
                'exact_owner_count' => $exactOwners,
                'coverage_percent' => round(($exact / $missingCount) * 100, 1),
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

        return $this->result($target, $sourceGames, $rows, $diagnostics);
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
            . 'g.name source_game_name,COALESCE(p.engine_key,"") source_engine,m.format_version metadata_format_version '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1',
            [$sourceFileId]
        );
        if (!$source || (int)$source['game_id'] === $targetGameId) {
            return null;
        }

        $target = $this->targetGame($targetGameId);
        if (strcasecmp((string)$source['source_engine'], (string)$target['engine_key']) !== 0) {
            return null;
        }
        if ((int)($source['metadata_format_version'] ?? 0) !== 2) {
            return null;
        }

        $md5 = strtolower(trim((string)($source['md5'] ?? '')));
        if ($md5 !== '') {
            $targetExisting = \catalog_one(
                $this->db,
                'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',
                [$targetGameId, $md5]
            );
            if ($targetExisting) {
                $sourcePackage = trim((string)($source['package_name'] ?? ''));
                $targetProvidesIdentity = strcasecmp(
                    trim((string)($targetExisting['package_name'] ?? '')),
                    $sourcePackage
                ) === 0;
                if (!$targetProvidesIdentity && $sourcePackage !== '') {
                    $targetProvidesIdentity = \catalog_package_alias_row_exists(
                        $this->db,
                        (int)$targetExisting['id'],
                        $targetGameId,
                        $sourcePackage
                    );
                }
                if ($targetProvidesIdentity) {
                    return null;
                }
            }
        }

        $package = trim((string)$source['package_name']);
        if ($package === '') {
            return null;
        }

        PdoDependencyReadSource::sql($this->db);
        $stats = \catalog_one(
            $this->db,
            'SELECT COUNT(DISTINCT l.file_id,l.import_index) missing_count,'
            . 'COUNT(DISTINCT l.file_id) owner_count '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
            . 'JOIN ue_files owner ON owner.id=l.file_id AND owner.scan_status="verified" '
            . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
            . 'WHERE owner.game_id=? AND l.status=0 '
            . 'AND CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci=?',
            [$targetGameId, $package]
        ) ?: [];
        $missingCount = max(0, (int)($stats['missing_count'] ?? 0));
        if ($missingCount < 1) {
            return null;
        }

        $exact = $this->exactProjectionMatches($targetGameId, [$sourceFileId])[$sourceFileId] ?? null;
        if (!is_array($exact) || (int)($exact['exact_object_matches'] ?? 0) < 1) {
            return null;
        }

        $ownerCount = max(0, (int)($stats['owner_count'] ?? 0));
        $exactMatches = min($missingCount, max(0, (int)$exact['exact_object_matches']));
        $exactOwners = min($ownerCount, max(0, (int)($exact['exact_owner_count'] ?? 0)));

        return $source + [
            'target_game_id' => $targetGameId,
            'target_game_name' => (string)$target['name'],
            'target_missing_count' => $missingCount,
            'target_owner_count' => $ownerCount,
            'exact_object_matches' => $exactMatches,
            'exact_owner_count' => $exactOwners,
            'coverage_percent' => round(($exactMatches / $missingCount) * 100, 1),
        ];
    }

    /**
     * Match the target's current missing dependency path hashes against the same
     * ue_export_lookup.path_hash projection used by normal dependency resolution.
     * Package identity is compared separately so an export from an unrelated
     * package cannot satisfy a same-path dependency. Each missing dependency row
     * is counted once even if a source package contains duplicate exports with the
     * same local path hash.
     *
     * @param list<int> $sourceFileIds
     * @return array<int,array{exact_object_matches:int,exact_owner_count:int}>
     */
    private function exactProjectionMatches(int $targetGameId, array $sourceFileIds): array
    {
        $sourceFileIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceFileIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($sourceFileIds === []) {
            return [];
        }

        $matches = [];
        foreach (array_chunk($sourceFileIds, self::SOURCE_ID_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = \catalog_all(
                $this->db,
                'SELECT source.id source_file_id,'
                . 'COUNT(DISTINCT l.file_id,l.import_index) exact_object_matches,'
                . 'COUNT(DISTINCT l.file_id) exact_owner_count '
                . 'FROM ue_dependency_links l '
                . 'JOIN ue_file_metadata owner_meta ON owner_meta.file_id=l.file_id AND owner_meta.format_version=2 '
                . 'JOIN ue_files owner ON owner.id=l.file_id AND owner.scan_status="verified" '
                . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
                . 'JOIN ue_files source ON source.id IN (' . $placeholders . ') '
                . 'AND source.scan_status="verified" '
                . 'AND source.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci '
                . 'JOIN ue_file_metadata source_meta ON source_meta.file_id=source.id AND source_meta.format_version=2 '
                . 'JOIN ue_export_lookup exports ON exports.file_id=source.id AND exports.path_hash=l.required_path_hash '
                . 'WHERE owner.game_id=? AND l.status=0 '
                . 'GROUP BY source.id',
                array_merge($chunk, [$targetGameId])
            );
            foreach ($rows as $row) {
                $fileId = (int)($row['source_file_id'] ?? 0);
                if ($fileId < 1) {
                    continue;
                }
                $matches[$fileId] = [
                    'exact_object_matches' => max(0, (int)($row['exact_object_matches'] ?? 0)),
                    'exact_owner_count' => max(0, (int)($row['exact_owner_count'] ?? 0)),
                ];
            }
        }
        return $matches;
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
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM ue_files target_existing '
                . 'WHERE target_existing.game_id=? AND target_existing.scan_status="verified" '
                . 'AND f.md5<>"" AND target_existing.md5=f.md5 '
                . 'AND (target_existing.package_name=f.package_name OR EXISTS ('
                . 'SELECT 1 FROM ue_file_package_aliases target_alias '
                . 'WHERE target_alias.file_id=target_existing.id AND target_alias.game_id=target_existing.game_id '
                . 'AND target_alias.package_name=f.package_name'
                . '))'
                . ') '
                . 'ORDER BY f.package_name,g.name,f.id',
                array_merge($sourceGameIds, $packageChunk, [$targetGameId])
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

    private function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
