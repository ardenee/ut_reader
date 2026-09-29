<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves package/object Imports against verified current-format catalog providers and export projections.
 * Why: Dependency resolution must use current compact metadata and source-backed engine rules.
 * Role: Infrastructure current-metadata dependency resolver.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use PDOException;

final class PdoDependencyResolver
{
    private const MAX_VALUES_PER_QUERY = 500;
    private const UE4_NON_OUTER_PACKAGE_IMPORT_VERSION = 520;

    /** @param list<array<string,mixed>> $imports */
    public static function resolve(
        PDO $db,
        int $gameId,
        int $fileId,
        array $imports,
        ?string $storageRoot = null
    ): array {
        $engineKey = self::engineKey($db, $gameId);
        $legacyPolicy = self::legacyVerifyImportPolicy($db, $gameId);
        $legacyVerifyImport = $legacyPolicy !== null;
        $ue3VerifyImport = $engineKey === 'UE3';
        $ue4VerifyImport = $engineKey === 'UE4';

        $importsByIndex = [];
        foreach ($imports as $fallback => $import) {
            if (is_array($import)) {
                $index = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                $importsByIndex[$index] = $import;
            }
        }
        $ue3RootPackages = [];
        $ue3SourceUnresolved = [];
        if ($ue3VerifyImport) {
            $importsByIndex = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($importsByIndex);
            foreach (array_keys($importsByIndex) as $importIndex) {
                $index = (int)$importIndex;
                $ue3RootPackages[$index] = self::ue3RootPackageName($importsByIndex, $index);
                if (self::hasExportOuterInImportAncestry($importsByIndex, $index)) {
                    $ue3SourceUnresolved[$index] = true;
                }
            }
        }

        $ue4MetadataUnresolved = [];
        if ($ue4VerifyImport
            && self::filePackageVersion($db, $fileId) >= self::UE4_NON_OUTER_PACKAGE_IMPORT_VERSION) {
            foreach (array_keys($importsByIndex) as $importIndex) {
                $index = (int)$importIndex;
                if (self::hasExportOuterInImportAncestry($importsByIndex, $index)) {
                    $ue4MetadataUnresolved[$index] = true;
                }
            }
        }

        if ($ue3VerifyImport) {
            foreach ($imports as $fallback => &$import) {
                if (!is_array($import) || (int)($import['is_common'] ?? 0) === 1) {
                    continue;
                }
                $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                if (strcasecmp(trim((string)($import['root_package'] ?? '')), 'SequenceObjects') === 0
                    && strcasecmp(trim((string)($ue3RootPackages[$importIndex] ?? '')), 'Engine') === 0) {
                    $import['is_common'] = 1;
                }
            }
            unset($import);
        }

        $packageNames = [];
        $objectLookups = [];
        foreach ($imports as $fallback => $import) {
            if (!is_array($import) || self::isCommonImport($import, $engineKey)) {
                continue;
            }
            $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            $rootPackage = $ue3VerifyImport
                ? trim((string)($ue3RootPackages[$importIndex] ?? ''))
                : trim((string)($import['root_package'] ?? ''));
            if ($rootPackage !== '') {
                $key = self::normalizeLookup($rootPackage);
                if ($key !== '' && !isset($packageNames[$key])) {
                    $packageNames[$key] = $rootPackage;
                }
            }
            $relativeObjectPath = trim((string)($import['relative_object_path'] ?? ''));
            $fullPath = trim((string)($import['full_path'] ?? ''));
            if (!$ue3VerifyImport && $rootPackage !== '' && $relativeObjectPath !== '' && $fullPath !== '') {
                $key = self::normalizeLookup($fullPath);
                if ($key !== '' && !isset($objectLookups[$key])) {
                    $objectLookups[$key] = [
                        'lookup_value' => $fullPath,
                        'package_name' => $rootPackage,
                        'local_path' => $relativeObjectPath,
                        'class_package' => trim((string)($import['class_package'] ?? '')),
                        'class_name' => trim((string)($import['class_name'] ?? '')),
                    ];
                }
            }
        }

        $packageMatches = self::loadPackageMatches($db, $gameId, $fileId, array_values($packageNames));
        $classRemaps = $legacyVerifyImport
            ? (new PdoClassRemapRepository($db))->mappingsForGame($gameId)
            : [];

        $packageRequirements = [];
        if ($ue3VerifyImport) {
            foreach ($imports as $fallback => $import) {
                if (!is_array($import) || self::isCommonImport($import, $engineKey)
                    || (int)($import['outer_index'] ?? 0) === 0) {
                    continue;
                }
                $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                $rootPackage = trim((string)($ue3RootPackages[$importIndex] ?? ''));
                $packageKey = self::normalizeLookup($rootPackage);
                if ($packageKey !== '') {
                    $packageRequirements[$packageKey]['package_name'] ??= $rootPackage;
                }
            }
        } else {
            foreach ($objectLookups as $lookup) {
                $packageKey = self::normalizeLookup((string)$lookup['package_name']);
                $packageRequirements[$packageKey]['package_name'] ??= $lookup['package_name'];
                $requirementPath = (string)$lookup['lookup_value'];
                $packageRequirements[$packageKey]['paths'][] = $requirementPath;
                $packageRequirements[$packageKey]['classes'][$requirementPath] = [
                    'class_package' => (string)($lookup['class_package'] ?? ''),
                    'class_name' => (string)($lookup['class_name'] ?? ''),
                ];
            }
        }

        $verifyImportMatches = [];
        if ($legacyVerifyImport) {
            require_once __DIR__ . '/PdoLegacyVerifyImportProjectionResolver.php';
            $legacyCandidates = self::loadPackageCandidates($db, $gameId, $fileId, array_values($packageNames));
            foreach ($packageRequirements as $packageKey => $requirement) {
                $requiredImportIndexes = self::requiredImportIndexes($imports, $packageKey, $engineKey);
                $bestCandidate = null;
                $bestVariants = null;
                $bestMatchCount = -1;
                foreach ($legacyCandidates[$packageKey] ?? [] as $candidate) {
                    $variants = PdoLegacyVerifyImportProjectionResolver::resolveProviderVariants(
                        $db,
                        (int)$candidate['file_id'],
                        $imports,
                        $classRemaps
                    );
                    $matches = (array)($variants[$legacyPolicy] ?? []);
                    $matchCount = 0;
                    foreach ($requiredImportIndexes as $requiredImportIndex) {
                        if (array_key_exists($requiredImportIndex, $matches)) {
                            $matchCount++;
                        }
                    }
                    if ($matchCount > $bestMatchCount) {
                        $bestCandidate = $candidate;
                        $bestVariants = $variants;
                        $bestMatchCount = $matchCount;
                    }
                    if ($matchCount === count($requiredImportIndexes)) {
                        break;
                    }
                }
                if ($bestCandidate !== null && is_array($bestVariants)) {
                    $packageMatches[$packageKey] = $bestCandidate;
                    $verifyImportMatches[$packageKey] = $bestVariants;
                }
            }
        }

        $ue3VerifyImportMatches = [];
        if ($ue3VerifyImport) {
            require_once __DIR__ . '/PdoUe3VerifyImportProjectionResolver.php';
            $ue3Candidates = self::loadPackageCandidates($db, $gameId, $fileId, array_values($packageNames));
            foreach ($packageRequirements as $packageKey => $requirement) {
                $requiredImportIndexes = self::requiredImportIndexes(
                    $imports,
                    $packageKey,
                    $engineKey,
                    $ue3RootPackages
                );
                $bestCandidate = null;
                $bestMatches = [];
                $bestMatchCount = -1;

                // UE3 loads one SourceLinker for the package, then VerifyImport()
                // resolves each Import independently against that linker. Do not
                // discard successful sibling Imports just because one Import in
                // the same package fails. UnrealDB can know several physical
                // variants, so prefer a complete provider when one exists;
                // otherwise retain the single candidate satisfying the greatest
                // number of Imports. Candidate order breaks ties. Never combine
                // matches from multiple physical providers.
                foreach ($ue3Candidates[$packageKey] ?? [] as $candidate) {
                    $matches = PdoUe3VerifyImportProjectionResolver::resolveProvider(
                        $db,
                        (int)$candidate['file_id'],
                        $imports,
                        $requiredImportIndexes,
                        $storageRoot
                    );
                    $matchCount = 0;
                    foreach ($requiredImportIndexes as $requiredImportIndex) {
                        if (array_key_exists($requiredImportIndex, $matches)) {
                            $matchCount++;
                        }
                    }
                    if ($matchCount > $bestMatchCount) {
                        $bestCandidate = $candidate;
                        $bestMatches = $matches;
                        $bestMatchCount = $matchCount;
                    }
                    if ($matchCount === count($requiredImportIndexes)) {
                        break;
                    }
                }
                if ($bestCandidate !== null) {
                    $packageMatches[$packageKey] = $bestCandidate;
                    $ue3VerifyImportMatches[$packageKey] = $bestMatches;
                }
            }
        }

        $ue4VerifyImportMatches = [];
        if ($ue4VerifyImport) {
            require_once __DIR__ . '/PdoUe4VerifyImportProjectionResolver.php';
            $ue4Candidates = self::loadPackageCandidates($db, $gameId, $fileId, array_values($packageNames));
            foreach ($packageRequirements as $packageKey => $requirement) {
                $requiredImportIndexes = self::requiredImportIndexes(
                    $imports,
                    $packageKey,
                    $engineKey,
                    [],
                    $ue4MetadataUnresolved
                );
                $bestCandidate = null;
                $bestMatches = [];
                $bestMatchCount = -1;
                foreach ($ue4Candidates[$packageKey] ?? [] as $candidate) {
                    $matches = PdoUe4VerifyImportProjectionResolver::resolveProvider(
                        $db,
                        (int)$candidate['file_id'],
                        $imports
                    );
                    $matchCount = 0;
                    foreach ($requiredImportIndexes as $requiredImportIndex) {
                        if (array_key_exists($requiredImportIndex, $matches)) {
                            $matchCount++;
                        }
                    }
                    // VerifyImport is per Import. Catalogue duplicates must still
                    // resolve through one physical provider, but a failed sibling
                    // Import does not invalidate successful siblings in that linker.
                    if ($matchCount > $bestMatchCount) {
                        $bestCandidate = $candidate;
                        $bestMatches = $matches;
                        $bestMatchCount = $matchCount;
                    }
                    if ($requiredImportIndexes !== [] && $matchCount === count($requiredImportIndexes)) {
                        break;
                    }
                }
                if ($bestCandidate !== null) {
                    $packageMatches[$packageKey] = $bestCandidate;
                    $ue4VerifyImportMatches[$packageKey] = $bestMatches;
                }
            }
        }

        $completeProviders = [];
        if (!$legacyVerifyImport && !$ue3VerifyImport && !$ue4VerifyImport) {
            foreach ($packageRequirements as $packageKey => $requirement) {
                $provider = PdoPackageObjectCoverageResolver::chooseCompleteProvider(
                    $db,
                    $gameId,
                    (string)$requirement['package_name'],
                    array_values(array_unique((array)$requirement['paths'])),
                    $fileId,
                    (array)($requirement['classes'] ?? []),
                    $engineKey
                );
                if ($provider !== null) {
                    $completeProviders[$packageKey] = $provider;
                }
            }
        }

        $resolved = [];
        foreach ($imports as $fallback => $import) {
            if (!is_array($import)) {
                continue;
            }
            $importId = (int)($import['id'] ?? 0);
            if ($importId < 1) {
                continue;
            }
            $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            $rootPackage = $ue3VerifyImport
                ? (string)($ue3RootPackages[$importIndex] ?? '')
                : (string)($import['root_package'] ?? '');
            $isObjectImport = $ue3VerifyImport
                ? (int)($import['outer_index'] ?? 0) !== 0
                : (string)($import['relative_object_path'] ?? '') !== '';
            $result = self::missing();

            if (self::isCommonImport($import, $engineKey)) {
                $result = [
                    'status' => 'common',
                    'resolved_file_id' => null,
                    'resolved_export_id' => null,
                    'resolved_export_index' => null,
                    'source' => 'common_script',
                    'confidence' => 'common',
                ];
            } elseif ($ue3VerifyImport && isset($ue3SourceUnresolved[$importIndex])) {
                $result = [
                    'status' => 'unresolved',
                    'resolved_file_id' => null,
                    'resolved_export_id' => null,
                    'resolved_export_index' => null,
                    'source' => 'ue3_cooked_export_outer',
                    'confidence' => 'source_unresolved',
                ];
            } elseif ($ue4VerifyImport && isset($ue4MetadataUnresolved[$importIndex])) {
                $result = [
                    'status' => 'unresolved',
                    'resolved_file_id' => null,
                    'resolved_export_id' => null,
                    'resolved_export_index' => null,
                    'source' => 'ue4_v4_missing_package_name',
                    'confidence' => 'metadata_unresolved',
                ];
            } elseif (!$isObjectImport) {
                $packageMatch = $packageMatches[self::normalizeLookup($rootPackage)] ?? null;
                if ($packageMatch !== null) {
                    $result = [
                        'status' => 'package_only',
                        'resolved_file_id' => $packageMatch['file_id'],
                        'resolved_export_id' => null,
                        'resolved_export_index' => null,
                        'source' => $packageMatch['source'],
                        'confidence' => 'exact',
                    ];
                }
            } else {
                $packageKey = self::normalizeLookup($rootPackage);
                $packageMatch = $packageMatches[$packageKey] ?? null;

                if ($legacyVerifyImport) {
                    $variants = $verifyImportMatches[$packageKey] ?? [];
                    $exportIndex = is_array($variants) && $legacyPolicy !== null
                        ? ($variants[$legacyPolicy][$importIndex] ?? null)
                        : null;
                    if ($packageMatch !== null && $exportIndex !== null) {
                        $result = [
                            'status' => 'resolved',
                            'resolved_file_id' => (int)$packageMatch['file_id'],
                            'resolved_export_id' => null,
                            'resolved_export_index' => (int)$exportIndex,
                            'source' => $legacyPolicy === 'unreal2' ? 'ue_verify_import_unreal2' : 'ue_verify_import',
                            'confidence' => 'exact',
                        ];
                    }
                } elseif ($ue3VerifyImport) {
                    $exportIndex = $ue3VerifyImportMatches[$packageKey][$importIndex] ?? null;
                    if ($packageMatch !== null && $exportIndex !== null) {
                        $result = [
                            'status' => 'resolved',
                            'resolved_file_id' => (int)$packageMatch['file_id'],
                            'resolved_export_id' => null,
                            'resolved_export_index' => (int)$exportIndex,
                            'source' => 'ue3_verify_import',
                            'confidence' => 'exact',
                        ];
                    }
                } elseif ($ue4VerifyImport) {
                    $exportIndex = $ue4VerifyImportMatches[$packageKey][$importIndex] ?? null;
                    if ($packageMatch !== null && $exportIndex !== null) {
                        $result = [
                            'status' => 'resolved',
                            'resolved_file_id' => (int)$packageMatch['file_id'],
                            'resolved_export_id' => null,
                            'resolved_export_index' => (int)$exportIndex,
                            'source' => 'ue4_verify_import',
                            'confidence' => 'exact',
                        ];
                    }
                } else {
                    $completeProvider = $completeProviders[$packageKey] ?? null;
                    $relativeKey = self::normalizeLookup((string)($import['relative_object_path'] ?? ''));
                    $exportIndex = is_array($completeProvider)
                        ? ($completeProvider['matched_exports'][$relativeKey] ?? null)
                        : null;
                    if ($exportIndex !== null) {
                        $result = [
                            'status' => 'resolved',
                            'resolved_file_id' => (int)$completeProvider['file_id'],
                            'resolved_export_id' => null,
                            'resolved_export_index' => (int)$exportIndex,
                            'source' => 'complete_package_object',
                            'confidence' => 'exact',
                        ];
                    }
                }
            }
            $resolved[$importId] = $result;
        }

        return $resolved;
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @param array<int,string> $ue3RootPackages
     * @param array<int,bool> $excludedImportIndexes
     * @return list<int>
     */
    private static function requiredImportIndexes(
        array $imports,
        string $packageKey,
        string $engineKey,
        array $ue3RootPackages = [],
        array $excludedImportIndexes = []
    ): array {
        $indexes = [];
        foreach ($imports as $fallback => $import) {
            if (!is_array($import) || self::isCommonImport($import, $engineKey)) {
                continue;
            }
            $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            if (isset($excludedImportIndexes[$importIndex])) {
                continue;
            }
            $rootPackage = $engineKey === 'UE3'
                ? (string)($ue3RootPackages[$importIndex] ?? '')
                : (string)($import['root_package'] ?? '');
            $isObjectImport = $engineKey === 'UE3'
                ? (int)($import['outer_index'] ?? 0) !== 0
                : trim((string)($import['relative_object_path'] ?? '')) !== '';
            if (self::normalizeLookup($rootPackage) !== $packageKey || !$isObjectImport) {
                continue;
            }
            $indexes[] = $importIndex;
        }
        return $indexes;
    }

    private static function missing(): array
    {
        return [
            'status' => 'missing',
            'resolved_file_id' => null,
            'resolved_export_id' => null,
            'resolved_export_index' => null,
            'source' => 'none',
            'confidence' => 'missing',
        ];
    }

    /** @param array<int,array<string,mixed>> $importsByIndex */
    private static function hasExportOuterInImportAncestry(array $importsByIndex, int $importIndex): bool
    {
        $seen = [];
        while (true) {
            if (isset($seen[$importIndex])) {
                return false;
            }
            $seen[$importIndex] = true;
            $import = $importsByIndex[$importIndex] ?? null;
            if (!is_array($import)) {
                return false;
            }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex > 0) {
                return true;
            }
            if ($outerIndex === 0) {
                return false;
            }
            $importIndex = -$outerIndex - 1;
        }
    }

    /** @param array<int,array<string,mixed>> $importsByIndex */
    private static function ue3RootPackageName(array $importsByIndex, int $importIndex): string
    {
        $seen = [];
        while (true) {
            if (isset($seen[$importIndex])) {
                return '';
            }
            $seen[$importIndex] = true;
            $import = $importsByIndex[$importIndex] ?? null;
            if (!is_array($import)) {
                return '';
            }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex === 0) {
                if (strcasecmp(trim((string)($import['class_name'] ?? '')), 'Package') !== 0
                    || strcasecmp(trim((string)($import['class_package'] ?? '')), 'Core') !== 0) {
                    return '';
                }
                return trim((string)($import['object_name'] ?? ''));
            }
            if ($outerIndex > 0) {
                // VerifyImportInner deliberately does not establish a provider
                // SourceLinker through a cooked import->export outer.
                return '';
            }
            $importIndex = -$outerIndex - 1;
        }
    }

    private static function isCommonImport(array $import, string $engineKey): bool
    {
        if ((int)($import['is_common'] ?? 0) === 1) {
            return true;
        }
        return $engineKey === 'UE4'
            && strncasecmp(trim((string)($import['root_package'] ?? '')), '/Script/', 8) === 0;
    }

    private static function legacyVerifyImportPolicy(PDO $db, int $gameId): ?string
    {
        $row = \catalog_one(
            $db,
            'SELECT p.engine_key,p.profile_name,p.notes,g.name game_name,g.slug game_slug '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=? LIMIT 1',
            [$gameId]
        );
        $engine = strtoupper(trim((string)($row['engine_key'] ?? '')));
        if (!in_array($engine, ['UE1', 'UE2'], true)) {
            return null;
        }
        if ($engine === 'UE2') {
            $identity = strtolower(implode(' ', [
                (string)($row['profile_name'] ?? ''),
                (string)($row['notes'] ?? ''),
                (string)($row['game_name'] ?? ''),
                (string)($row['game_slug'] ?? ''),
            ]));
            if (preg_match('/\bunreal[ _-]*ii\b|\bunreal[ _-]*2\b/', $identity) === 1) {
                return 'unreal2';
            }
        }
        return 'standard';
    }

    private static function filePackageVersion(PDO $db, int $fileId): int
    {
        if ($fileId < 1) {
            return 0;
        }
        $statement = $db->prepare('SELECT package_version FROM ue_files WHERE id=? LIMIT 1');
        $statement->execute([$fileId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    private static function engineKey(PDO $db, int $gameId): string
    {
        $row = \catalog_one(
            $db,
            'SELECT p.engine_key FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=? LIMIT 1',
            [$gameId]
        );
        return strtoupper(trim((string)($row['engine_key'] ?? '')));
    }

    /** @param list<string> $packageNames @return array<string,array{file_id:int,source:string}> */
    private static function loadPackageMatches(PDO $db, int $gameId, int $fileId, array $packageNames): array
    {
        $matches = [];
        foreach (array_chunk($packageNames, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = self::placeholders(count($chunk));
            try {
                $rows = \catalog_all(
                    $db,
                    'SELECT p.package_name lookup_value,p.file_id,p.source_kind FROM ue_package_providers p '
                    . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
                    . 'LEFT JOIN ue_file_package_aliases a ON p.source_kind="alias" AND a.id=p.source_id '
                    . 'AND a.file_id=p.file_id AND a.game_id=p.game_id AND a.package_name=p.package_name '
                    . 'WHERE p.game_id=? AND f.scan_status="verified" '
                    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                    . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
                    . 'AND p.package_name IN (' . $placeholders . ') '
                    . 'AND ((p.source_kind="primary" AND f.package_name=p.package_name) '
                    . 'OR (p.source_kind="alias" AND a.id IS NOT NULL)) '
                    . 'ORDER BY p.package_name,(p.source_kind="primary") DESC,(p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC',
                    array_merge([$gameId], $chunk, [$fileId])
                );
            } catch (PDOException) {
                $rows = [];
            }
            foreach ($rows as $row) {
                self::collectPackageMatch($row, $matches);
            }

            $missing = self::missingLookupValues($chunk, $matches);
            if ($missing !== []) {
                $rows = \catalog_all(
                    $db,
                    'SELECT f.package_name lookup_value,f.id file_id,"primary" source_kind FROM ue_files f '
                    . 'WHERE f.game_id=? AND f.scan_status="verified" '
                    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                    . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
                    . 'AND f.package_name IN (' . self::placeholders(count($missing)) . ') '
                    . 'ORDER BY f.package_name,(f.id=?) DESC,f.uploaded_at DESC',
                    array_merge([$gameId], $missing, [$fileId])
                );
                foreach ($rows as $row) {
                    self::collectPackageMatch($row, $matches);
                }
            }

            $missing = self::missingLookupValues($missing, $matches);
            if ($missing !== []) {
                $rows = \catalog_all(
                    $db,
                    'SELECT a.package_name lookup_value,a.file_id,"alias" source_kind FROM ue_file_package_aliases a '
                    . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                    . 'WHERE a.game_id=? AND f.scan_status="verified" '
                    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                    . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
                    . 'AND a.package_name IN (' . self::placeholders(count($missing)) . ') '
                    . 'ORDER BY a.package_name,(f.id=?) DESC,f.uploaded_at DESC,a.id ASC',
                    array_merge([$gameId], $missing, [$fileId])
                );
                foreach ($rows as $row) {
                    self::collectPackageMatch($row, $matches);
                }
            }
        }
        return $matches;
    }

    /** @param list<string> $packageNames @return array<string,list<array{file_id:int,source:string}>> */
    private static function loadPackageCandidates(PDO $db, int $gameId, int $fileId, array $packageNames): array
    {
        $candidates = [];
        foreach (array_chunk($packageNames, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = self::placeholders(count($chunk));
            try {
                $rows = \catalog_all(
                    $db,
                    'SELECT p.package_name lookup_value,p.file_id,p.source_kind FROM ue_package_providers p '
                    . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
                    . 'LEFT JOIN ue_file_package_aliases a ON p.source_kind="alias" AND a.id=p.source_id '
                    . 'AND a.file_id=p.file_id AND a.game_id=p.game_id AND a.package_name=p.package_name '
                    . 'WHERE p.game_id=? AND f.scan_status="verified" '
                    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                    . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
                    . 'AND p.package_name IN (' . $placeholders . ') '
                    . 'AND ((p.source_kind="primary" AND f.package_name=p.package_name) '
                    . 'OR (p.source_kind="alias" AND a.id IS NOT NULL)) '
                    . 'ORDER BY p.package_name,(p.source_kind="primary") DESC,(p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC',
                    array_merge([$gameId], $chunk, [$fileId])
                );
            } catch (PDOException) {
                $rows = [];
            }
            foreach ($rows as $row) {
                self::collectPackageCandidate($row, $candidates);
            }

            $rows = \catalog_all(
                $db,
                'SELECT f.package_name lookup_value,f.id file_id,"primary" source_kind FROM ue_files f '
                . 'WHERE f.game_id=? AND f.scan_status="verified" '
                . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
                . 'AND f.package_name IN (' . $placeholders . ') '
                . 'ORDER BY f.package_name,(f.id=?) DESC,f.uploaded_at DESC',
                array_merge([$gameId], $chunk, [$fileId])
            );
            foreach ($rows as $row) {
                self::collectPackageCandidate($row, $candidates);
            }

            $rows = \catalog_all(
                $db,
                'SELECT a.package_name lookup_value,a.file_id,"alias" source_kind FROM ue_file_package_aliases a '
                . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                . 'WHERE a.game_id=? AND f.scan_status="verified" '
                . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
                . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
                . 'AND a.package_name IN (' . $placeholders . ') '
                . 'ORDER BY a.package_name,(f.id=?) DESC,f.uploaded_at DESC,a.id ASC',
                array_merge([$gameId], $chunk, [$fileId])
            );
            foreach ($rows as $row) {
                self::collectPackageCandidate($row, $candidates);
            }
        }
        return $candidates;
    }

    private static function collectPackageCandidate(array $row, array &$candidates): void
    {
        $key = self::normalizeLookup((string)($row['lookup_value'] ?? ''));
        $fileId = (int)($row['file_id'] ?? 0);
        if ($key === '' || $fileId < 1) {
            return;
        }
        foreach ($candidates[$key] ?? [] as $candidate) {
            if ((int)$candidate['file_id'] === $fileId) {
                return;
            }
        }
        $candidates[$key][] = [
            'file_id' => $fileId,
            'source' => (string)($row['source_kind'] ?? '') === 'alias' ? 'exact_package_alias' : 'exact_package',
        ];
    }

    private static function collectPackageMatch(array $row, array &$matches): void
    {
        $key = self::normalizeLookup((string)($row['lookup_value'] ?? ''));
        if ($key === '' || isset($matches[$key])) {
            return;
        }
        $matches[$key] = [
            'file_id' => (int)$row['file_id'],
            'source' => (string)($row['source_kind'] ?? '') === 'alias' ? 'exact_package_alias' : 'exact_package',
        ];
    }

    private static function missingLookupValues(array $values, array $matches): array
    {
        $missing = [];
        foreach ($values as $value) {
            $value = (string)$value;
            if (!isset($matches[self::normalizeLookup($value)])) {
                $missing[] = $value;
            }
        }
        return $missing;
    }

    private static function normalizeLookup(string|int $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        // PHP coerces numeric-looking string array keys (for example package
        // name "123") to integers. Prefix all internal lookup keys so the
        // resolver's typed string policy cannot be bypassed by package names.
        return 'k:' . $normalized;
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
