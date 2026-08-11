<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finds verified packages in sibling games that can satisfy missing dependencies in a target game.
 * Why: A package already assigned to one game may be byte-for-byte usable by another compatible game and should be discoverable without relying on package-name guesses alone.
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
    private readonly CompressedFileMetadataReader $metadata;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
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
     * @return array{target:array<string,mixed>,source_games:list<array<string,mixed>>,rows:list<array<string,mixed>>}
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

        $summaryRows = \catalog_all(
            $this->db,
            'SELECT required_package,SUM(missing_count) missing_count,COUNT(DISTINCT file_id) owner_count '
            . 'FROM ue_dependency_package_summaries '
            . 'WHERE game_id=? AND missing_count>0 AND required_package IS NOT NULL AND required_package<>"" '
            . 'GROUP BY required_package ORDER BY missing_count DESC LIMIT 500',
            [$targetGameId]
        );
        if ($summaryRows === []) {
            return ['target' => $target, 'source_games' => $sourceGames, 'rows' => []];
        }

        $packageSummary = [];
        foreach ($summaryRows as $row) {
            $packageSummary[$this->key((string)$row['required_package'])] = [
                'required_package' => (string)$row['required_package'],
                'missing_count' => (int)$row['missing_count'],
                'owner_count' => (int)$row['owner_count'],
            ];
        }
        $packageNames = array_values(array_map(
            static fn(array $row): string => (string)$row['required_package'],
            $packageSummary
        ));

        $sourceSql = 'SELECT f.id,f.game_id,f.package_name,f.original_name,f.relative_path,f.extension,f.file_size,'
            . 'f.md5,f.sha1,f.package_guid,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
            . 'g.name source_game_name,p.engine_key source_engine '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE f.scan_status="verified" AND UPPER(p.engine_key)=? AND f.game_id<>? '
            . 'AND f.package_name IN (' . implode(',', array_fill(0, count($packageNames), '?')) . ')';
        $sourceArgs = array_merge([$engine, $targetGameId], $packageNames);
        if ($sourceGameId > 0) {
            $sourceSql .= ' AND f.game_id=?';
            $sourceArgs[] = $sourceGameId;
        }
        $sourceSql .= ' ORDER BY f.package_name,g.name,f.id LIMIT 3000';
        $sources = \catalog_all($this->db, $sourceSql, $sourceArgs);
        if ($sources === []) {
            return ['target' => $target, 'source_games' => $sourceGames, 'rows' => []];
        }

        $dependencySource = PdoDependencyReadSource::sql($this->db);
        $dependencyRows = \catalog_all(
            $this->db,
            'SELECT d.id,d.file_id owner_file_id,d.required_package,d.required_object_path '
            . 'FROM ' . $dependencySource . ' d '
            . 'JOIN ue_files owner ON owner.id=d.file_id AND owner.scan_status="verified" '
            . 'WHERE owner.game_id=? AND d.status="missing" '
            . 'AND d.required_package IN (' . implode(',', array_fill(0, count($packageNames), '?')) . ')',
            array_merge([$targetGameId], $packageNames)
        );
        $dependenciesByPackage = [];
        foreach ($dependencyRows as $row) {
            $dependenciesByPackage[$this->key((string)$row['required_package'])][] = $row;
        }

        $rows = [];
        $seenIdentity = [];
        foreach ($sources as $source) {
            $identityKey = strtolower(trim((string)($source['md5'] ?? '')));
            if ($identityKey === '') {
                $identityKey = 'file:' . (int)$source['id'];
            }
            if ($sourceGameId === 0 && isset($seenIdentity[$identityKey])) {
                continue;
            }

            $packageKey = $this->key((string)$source['package_name']);
            $dependencies = $dependenciesByPackage[$packageKey] ?? [];
            if ($dependencies === []) {
                continue;
            }
            if (!$this->compatibleWithTarget($target, $source)) {
                continue;
            }

            try {
                $exports = $this->metadata->exports((int)$source['id']);
            } catch (Throwable) {
                continue;
            }
            $exportPaths = [];
            foreach ($exports as $export) {
                $path = trim((string)($export['full_path'] ?? ''));
                if ($path !== '') {
                    $exportPaths[$this->key($path)] = true;
                }
            }
            if ($exportPaths === []) {
                continue;
            }

            $exact = 0;
            $owners = [];
            foreach ($dependencies as $dependency) {
                if (isset($exportPaths[$this->key((string)$dependency['required_object_path'])])) {
                    $exact++;
                    $owners[(int)$dependency['owner_file_id']] = true;
                }
            }
            if ($exact < 1) {
                continue;
            }

            $targetExisting = \catalog_one(
                $this->db,
                'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',
                [$targetGameId, (string)$source['md5']]
            );
            if ($targetExisting) {
                continue;
            }

            $summary = $packageSummary[$packageKey] ?? [
                'missing_count' => count($dependencies),
                'owner_count' => count($owners),
            ];
            $missingCount = max(1, (int)($summary['missing_count'] ?? count($dependencies)));
            $rows[] = $source + [
                'target_game_id' => $targetGameId,
                'target_game_name' => (string)$target['name'],
                'target_missing_count' => $missingCount,
                'target_owner_count' => (int)($summary['owner_count'] ?? count($owners)),
                'exact_object_matches' => $exact,
                'exact_owner_count' => count($owners),
                'coverage_percent' => round(($exact / $missingCount) * 100, 1),
            ];
            $seenIdentity[$identityKey] = true;
        }

        usort($rows, static function (array $left, array $right): int {
            return ($right['exact_object_matches'] <=> $left['exact_object_matches'])
                ?: ($right['coverage_percent'] <=> $left['coverage_percent'])
                ?: ($right['target_owner_count'] <=> $left['target_owner_count'])
                ?: strcasecmp((string)$left['package_name'], (string)$right['package_name']);
        });
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return ['target' => $target, 'source_games' => $sourceGames, 'rows' => $rows];
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
            . 'g.name source_game_name,p.engine_key source_engine '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1',
            [$sourceFileId]
        );
        if (!$source || (int)$source['game_id'] === $targetGameId) {
            return null;
        }
        $target = $this->targetGame($targetGameId);
        if (strcasecmp((string)$source['source_engine'], (string)$target['engine_key']) !== 0
            || !$this->compatibleWithTarget($target, $source)) {
            return null;
        }
        if (\catalog_one(
            $this->db,
            'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',
            [$targetGameId, (string)$source['md5']]
        )) {
            return null;
        }

        $dependencySource = PdoDependencyReadSource::sql($this->db);
        $dependencies = \catalog_all(
            $this->db,
            'SELECT d.id,d.file_id owner_file_id,d.required_object_path '
            . 'FROM ' . $dependencySource . ' d '
            . 'JOIN ue_files owner ON owner.id=d.file_id AND owner.scan_status="verified" '
            . 'WHERE owner.game_id=? AND d.status="missing" AND d.required_package=?',
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
        $exact = 0;
        $owners = [];
        foreach ($dependencies as $dependency) {
            if (isset($exportPaths[$this->key((string)$dependency['required_object_path'])])) {
                $exact++;
                $owners[(int)$dependency['owner_file_id']] = true;
            }
        }
        if ($exact < 1) {
            return null;
        }
        return $source + [
            'target_game_id' => $targetGameId,
            'target_game_name' => (string)$target['name'],
            'target_missing_count' => count($dependencies),
            'target_owner_count' => count(array_unique(array_map(
                static fn(array $row): int => (int)$row['owner_file_id'],
                $dependencies
            ))),
            'exact_object_matches' => $exact,
            'exact_owner_count' => count($owners),
            'coverage_percent' => round(($exact / max(1, count($dependencies))) * 100, 1),
        ];
    }

    /** @return array<string,mixed> */
    private function targetGame(int $gameId): array
    {
        if ($gameId < 1) {
            throw new \InvalidArgumentException('Choose a target game.');
        }
        $row = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.profile_id,p.profile_name,p.engine_key,p.allowed_extensions_json,'
            . 'p.compatibility_rules_json,p.package_version_min,p.package_version_max,'
            . 'p.licensee_version_min,p.licensee_version_max,p.confidence_policy '
            . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?',
            [$gameId]
        );
        if (!$row) {
            throw new \RuntimeException('Target game or active profile was not found.');
        }
        return $row;
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function compatibleWithTarget(array $target, array $source): bool
    {
        $extension = \catalog_clean_unreal_extension((string)($source['extension'] ?? ''));
        $allowedExtensions = \gp_extensions($target);
        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        $detectedEngine = strtoupper(trim((string)($source['detected_engine_key'] ?? '')));
        $profileEngine = strtoupper(trim((string)($target['engine_key'] ?? '')));
        $packageVersion = $source['detected_package_version'] !== null
            ? (int)$source['detected_package_version'] : null;
        $licenseeVersion = $source['detected_licensee_version'] !== null
            ? (int)$source['detected_licensee_version'] : null;
        $compatibility = \gp_compatibility_for_file(
            $target,
            $extension,
            $packageVersion,
            $licenseeVersion,
            $detectedEngine
        );
        if ($detectedEngine !== $profileEngine && $compatibility === null) {
            return false;
        }
        if ($packageVersion !== null && $packageVersion >= 0 && $compatibility === null) {
            if ($target['package_version_min'] !== null && $packageVersion < (int)$target['package_version_min']) {
                return false;
            }
            if ($target['package_version_max'] !== null && $packageVersion > (int)$target['package_version_max']) {
                return false;
            }
        }
        if ($licenseeVersion !== null && $compatibility === null) {
            if ($target['licensee_version_min'] !== null && $licenseeVersion < (int)$target['licensee_version_min']) {
                return false;
            }
            if ($target['licensee_version_max'] !== null && $licenseeVersion > (int)$target['licensee_version_max']) {
                return false;
            }
        }
        return true;
    }

    private function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
