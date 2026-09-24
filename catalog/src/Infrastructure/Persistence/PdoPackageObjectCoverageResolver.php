<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/**
 * Evaluates every current-format provider of a package against a complete set
 * of required object paths.
 *
 * This is deliberately separate from PdoDependencyResolver: normal Import
 * resolution still records one result per Import, while this service answers
 * the stronger question "which single package version satisfies this whole
 * requirement set?".
 */
final class PdoPackageObjectCoverageResolver
{
    private const MAX_PATHS_PER_QUERY = 250;

    /**
     * @param list<string> $requiredObjectPaths Full or package-relative object paths.
     * @return list<array{
     *   file_id:int,
     *   source:string,
     *   required_count:int,
     *   matched_count:int,
     *   missing_count:int,
     *   status:string,
     *   matched_paths:list<string>,
     *   missing_paths:list<string>,
     *   matched_exports:array<string,int>
     * }>
     */
    public static function evaluate(
        PDO $db,
        int $gameId,
        string $packageName,
        array $requiredObjectPaths,
        int $preferredFileId = 0,
        array $requiredClassesByPath = []
    ): array {
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '') {
            return [];
        }

        $requirements = self::requirements($packageName, $requiredObjectPaths);
        $requiredClasses = self::requiredClasses($packageName, $requiredClassesByPath);
        $providers = self::providers($db, $gameId, $packageName, $preferredFileId);
        if ($providers === []) {
            return [];
        }

        $matched = [];
        $matchedExports = [];
        $reader = self::metadataReader($db);
        $config = self::catalogConfig();
        foreach (array_keys($providers) as $fileId) {
            $matched[$fileId] = [];
            $matchedExports[$fileId] = [];
        }

        if ($requirements !== []) {
            foreach (array_chunk($requirements, self::MAX_PATHS_PER_QUERY, true) as $chunk) {
                $hashes = [];
                foreach ($chunk as $key => $path) {
                    $hash = md5($path, true);
                    $hex = bin2hex($hash);
                    $hashes[$hex] = ['hash' => $hash, 'key' => $key];
                }
                $providerIds = array_keys($providers);
                $sql = 'SELECT l.file_id,l.export_index,l.path_hash FROM ue_export_lookup l'
                    . ' JOIN ue_files f ON f.id=l.file_id'
                    . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
                    . ' WHERE f.game_id=? AND f.scan_status="verified"'
                    . ' AND l.file_id IN (' . self::placeholders(count($providerIds)) . ')'
                    . ' AND l.path_hash IN (' . self::placeholders(count($hashes)) . ')';
                $rows = \catalog_all(
                    $db,
                    $sql,
                    array_merge(
                        [$gameId],
                        $providerIds,
                        array_map(static fn(array $entry): string => $entry['hash'], array_values($hashes))
                    )
                );
                foreach ($rows as $row) {
                    $fileId = (int)$row['file_id'];
                    $entry = $hashes[bin2hex((string)$row['path_hash'])] ?? null;
                    if (!is_array($entry) || !isset($providers[$fileId])) {
                        continue;
                    }
                    // path_hash is only an index accelerator. Confirm the actual
                    // v3 Export path so a hash collision can never satisfy an Import.
                    try {
                        $pathMatches = self::exportMatchesRequirement(
                            $reader,
                            $fileId,
                            (int)$row['export_index'],
                            (string)$entry['key'],
                            $requiredClasses[(string)$entry['key']] ?? null
                        );
                    } catch (\Throwable $error) {
                        \UnrealDb\Catalog\Infrastructure\Metadata\VerifiedCompactMetadataHealth::queueRepair(
                            $db,
                            $config,
                            $fileId,
                            null,
                            $error
                        );
                        continue;
                    }
                    if (!$pathMatches) {
                        continue;
                    }
                    $matched[$fileId][$entry['key']] = true;
                    $matchedExports[$fileId][$entry['key']] = (int)$row['export_index'];
                }
            }
        }

        // Preserve historical case-insensitive Unreal object matching only for
        // requirements missed by the byte-sensitive path_hash fast path.
        foreach ($providers as $fileId => $_provider) {
            $missingForProvider = [];
            foreach ($requirements as $key => $path) {
                if (!isset($matched[$fileId][$key])) {
                    $missingForProvider[$key] = $path;
                }
            }
            if ($missingForProvider === []) {
                continue;
            }
            $fallback = PdoCompactCaseInsensitiveExportResolver::matchProviderPaths(
                $db,
                (int)$fileId,
                array_values($missingForProvider)
            );
            foreach ($fallback as $key => $exportIndex) {
                if (!isset($missingForProvider[$key])) {
                    continue;
                }
                try {
                    if (!self::exportMatchesRequirement(
                        $reader,
                        (int)$fileId,
                        (int)$exportIndex,
                        (string)$key,
                        $requiredClasses[(string)$key] ?? null
                    )) {
                        continue;
                    }
                } catch (\Throwable $error) {
                    \UnrealDb\Catalog\Infrastructure\Metadata\VerifiedCompactMetadataHealth::queueRepair(
                        $db,
                        $config,
                        (int)$fileId,
                        null,
                        $error
                    );
                    continue;
                }
                $matched[$fileId][$key] = true;
                $matchedExports[$fileId][$key] = (int)$exportIndex;
            }
        }

        $providerOrder = [];
        foreach (array_keys($providers) as $position => $providerFileId) {
            $providerOrder[(int)$providerFileId] = $position;
        }
        $result = [];
        foreach ($providers as $fileId => $provider) {
            $matchedPaths = [];
            $missingPaths = [];
            foreach ($requirements as $key => $path) {
                if (isset($matched[$fileId][$key])) {
                    $matchedPaths[] = $path;
                } else {
                    $missingPaths[] = $path;
                }
            }
            $requiredCount = count($requirements);
            $matchedCount = count($matchedPaths);
            $missingCount = count($missingPaths);
            $status = $requiredCount === 0 || $missingCount === 0
                ? 'fully_satisfies'
                : ($matchedCount > 0 ? 'partially_satisfies' : 'does_not_satisfy');

            $result[] = [
                'file_id' => $fileId,
                'source' => $provider['source'],
                'required_count' => $requiredCount,
                'matched_count' => $matchedCount,
                'missing_count' => $missingCount,
                'status' => $status,
                'matched_paths' => $matchedPaths,
                'missing_paths' => $missingPaths,
                'matched_exports' => $matchedExports[$fileId],
            ];
        }
        usort($result, static function (array $a, array $b) use ($preferredFileId, $providerOrder): int {
            $rank = ['fully_satisfies' => 0, 'partially_satisfies' => 1, 'does_not_satisfy' => 2];
            $status = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
            if ($status !== 0) {
                return $status;
            }
            if ($preferredFileId > 0) {
                $preferred = ((int)$b['file_id'] === $preferredFileId) <=> ((int)$a['file_id'] === $preferredFileId);
                if ($preferred !== 0) {
                    return $preferred;
                }
            }
            return ($providerOrder[(int)$a['file_id']] ?? PHP_INT_MAX)
                <=> ($providerOrder[(int)$b['file_id']] ?? PHP_INT_MAX);
        });
        return $result;
    }

    /**
     * Choose one provider only when that provider satisfies the entire object set.
     * The preferred file is a tie-breaker among complete providers, never a reason
     * to choose a partial provider.
     *
     * @param list<string> $requiredObjectPaths
     * @return array<string,mixed>|null
     */
    public static function chooseCompleteProvider(
        PDO $db,
        int $gameId,
        string $packageName,
        array $requiredObjectPaths,
        int $preferredFileId = 0,
        array $requiredClassesByPath = []
    ): ?array {
        return self::selectCompleteCoverage(
            self::evaluate(
                $db,
                $gameId,
                $packageName,
                $requiredObjectPaths,
                $preferredFileId,
                $requiredClassesByPath
            ),
            $preferredFileId
        );
    }

    /**
     * Pure selection boundary used by contracts and callers: partial providers
     * can never be combined or promoted into a complete provider.
     *
     * @param list<array<string,mixed>> $coverage
     * @return array<string,mixed>|null
     */
    public static function selectCompleteCoverage(array $coverage, int $preferredFileId = 0): ?array
    {
        $complete = array_values(array_filter(
            $coverage,
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'fully_satisfies'
        ));
        if ($complete === []) {
            return null;
        }
        if ($preferredFileId > 0) {
            foreach ($complete as $row) {
                if ((int)($row['file_id'] ?? 0) === $preferredFileId) {
                    return $row;
                }
            }
        }
        return $complete[0];
    }

    /**
     * @param list<string> $paths
     * @return array<string,string> normalized key => package-relative path
     */
    private static function requirements(string $packageName, array $paths): array
    {
        $requirements = [];
        $packagePrefix = self::key($packageName) . '.';
        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path === '') {
                continue;
            }
            $key = self::key($path);
            if (str_starts_with($key, $packagePrefix)) {
                $path = substr($path, strlen($packageName) + 1);
            }
            $path = trim($path, '.');
            $key = self::key($path);
            if ($key !== '' && !isset($requirements[$key])) {
                $requirements[$key] = $path;
            }
        }
        return $requirements;
    }

    /**
     * @param array<string,mixed> $classesByPath
     * @return array<string,array{class_package:string,class_name:string}>
     */
    private static function requiredClasses(string $packageName, array $classesByPath): array
    {
        $result = [];
        foreach ($classesByPath as $path => $class) {
            $requirements = self::requirements($packageName, [(string)$path]);
            $key = array_key_first($requirements);
            if ($key === null || !is_array($class)) {
                continue;
            }
            $className = trim((string)($class['class_name'] ?? ''));
            if ($className === '') {
                continue;
            }
            $result[(string)$key] = [
                'class_package' => trim((string)($class['class_package'] ?? '')),
                'class_name' => $className,
            ];
        }
        return $result;
    }

    /** @return array<int,array{source:string}> */
    private static function providers(PDO $db, int $gameId, string $packageName, int $preferredFileId): array
    {
        $providers = [];
        try {
            $rows = \catalog_all(
                $db,
                'SELECT p.file_id,p.source_kind FROM ue_package_providers p'
                . ' JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id'
                . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
                . ' LEFT JOIN ue_file_package_aliases a ON p.source_kind="alias"'
                . ' AND a.id=p.source_id AND a.file_id=p.file_id AND a.game_id=p.game_id'
                . ' AND a.package_name=p.package_name'
                . ' WHERE p.game_id=? AND p.package_name=? AND f.scan_status="verified"'
                . ' AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad'
                . ' WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1))'
                . ' AND ((p.source_kind="primary" AND f.package_name=p.package_name)'
                . ' OR (p.source_kind="alias" AND a.id IS NOT NULL))'
                . ' ORDER BY (p.source_kind="primary") DESC,p.provider_created_at DESC,p.source_id ASC',
                [$gameId, $packageName]
            );
        } catch (\PDOException) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $fileId = (int)$row['file_id'];
            if ($fileId > 0 && !isset($providers[$fileId])) {
                $providers[$fileId] = [
                    'source' => (string)$row['source_kind'] === 'alias'
                        ? 'package_alias'
                        : 'package_primary',
                ];
            }
        }

        // Preserve the resolver's fallback behavior if the provider projection is
        // not yet populated for this package.
        if ($providers === []) {
            $rows = \catalog_all(
                $db,
                'SELECT f.id file_id FROM ue_files f'
                . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
                . ' WHERE f.game_id=? AND f.scan_status="verified" AND f.package_name=?'
                . ' AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad'
                . ' WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1))'
                . ' ORDER BY f.uploaded_at DESC',
                [$gameId, $packageName]
            );
            foreach ($rows as $row) {
                $fileId = (int)$row['file_id'];
                if ($fileId > 0) {
                    $providers[$fileId] = ['source' => 'package_primary'];
                }
            }
        }

        return $providers;
    }

    /** @return array<string,mixed> */
    private static function catalogConfig(): array
    {
        $root = dirname(__DIR__, 3);
        return require $root . '/config.php';
    }

    private static function metadataReader(PDO $db): \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php';
        $config = self::catalogConfig();
        $storageRoot = (string)($config['storage_path'] ?? ($root . '/storage'));
        return new \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader($db, $storageRoot);
    }

    /** @param array{class_package:string,class_name:string}|null $requiredClass */
    private static function exportMatchesRequirement(
        \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader $reader,
        int $fileId,
        int $exportIndex,
        string $requiredKey,
        ?array $requiredClass
    ): bool {
        $rows = $reader->page($fileId, 'exports', $exportIndex, 1);
        foreach ($rows as $row) {
            if ((int)($row['export_index'] ?? -1) !== $exportIndex
                || self::key((string)($row['local_path'] ?? '')) !== $requiredKey) {
                continue;
            }
            if ($requiredClass === null) {
                return true;
            }
            $actual = self::key((string)($row['class_name'] ?? ''));
            $name = self::key($requiredClass['class_name']);
            $package = self::key($requiredClass['class_package']);
            if ($actual === '' || $name === '') {
                return true;
            }
            $qualified = $package !== '' ? $package . '.' . $name : $name;
            if (str_contains($actual, '.')) {
                return $actual === $qualified || $actual === $name;
            }
            return $actual === $name;
        }
        return false;
    }

    private static function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
