<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the authoritative current-format metadata snapshot directly from parser output.
 * Why: Newly verified packages already have Names/Imports/Exports in memory and must not write those rows only to read
 *      them back through the retired SQL metadata tables before publishing compact metadata.
 * Role: Infrastructure metadata builder shared by verified import and compact publication.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

final class CatalogParsedPackageMetadataSnapshotBuilder
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/Scanner/CatalogScannerPath.php';
        require_once $root . '/lib/Scanner/CatalogScannerSupport.php';
        require_once __DIR__ . '/CatalogUnrealIdentityHash.php';
        require_once __DIR__ . '/CatalogLegacyNameMapPreprocessor.php';
    }

    /**
     * Build a complete compact snapshot, including dependency resolution.
     *
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @return array<string,mixed>
     */
    public function build(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        array $names,
        array $imports,
        array $exports
    ): array {
        return $this->withDependencies($this->buildParsedSections(
            $fileId,
            $gameId,
            $packageName,
            $originalName,
            $names,
            $imports,
            $exports
        ));
    }

    /**
     * Normalize only the package-owned parser output.
     *
     * Full Sync can compare these sections with its already validated compact
     * snapshot before doing dependency resolution or rewriting the .uedb4 file.
     * Dependencies are deliberately excluded because their resolution can change
     * independently and Full Sync owns a separate bounded dependency phase.
     *
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @return array<string,mixed>
     */
    public function buildParsedSections(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        array $names,
        array $imports,
        array $exports
    ): array {
        if ($fileId < 1 || $gameId < 1 || trim($packageName) === '') {
            throw new RuntimeException('Parsed compact metadata requires valid file, game and package identities.');
        }

        $engineRow = \catalog_one(
            $this->db,
            'SELECT p.engine_key FROM ue_games g'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
            . ' WHERE g.id=? LIMIT 1',
            [$gameId]
        );
        $engineKey = strtoupper(trim((string)($engineRow['engine_key'] ?? '')));
        $sourceKey = '';
        try {
            $sourceKey = Uedb5GameSourceRegistry::sourceKey($gameId);
        } catch (RuntimeException) {
            // Non-UEDB5/custom game IDs retain the existing raw metadata path.
        }
        $legacyAllContextNameMap = $engineKey === 'UE1' && $sourceKey === 'ut99';
        $legacyNameMaxCharacters = null;
        if ($engineKey === 'UE2' && in_array($sourceKey, ['unreal2','ut2003'], true)) {
            $versionRow = \catalog_one(
                $this->db,
                'SELECT package_version FROM ue_files WHERE id=? LIMIT 1',
                [$fileId]
            );
            $sourceVersion = isset($versionRow['package_version'])
                ? (int)$versionRow['package_version']
                : null;
            if ($sourceKey === 'unreal2'
                && $sourceVersion !== null && $sourceVersion >= 60 && $sourceVersion <= 126) {
                $legacyAllContextNameMap = true;
                $legacyNameMaxCharacters = $sourceVersion >= 70 ? 63 : null;
            } elseif ($sourceKey === 'ut2003'
                && $sourceVersion !== null && $sourceVersion >= 60 && $sourceVersion <= 120) {
                $legacyAllContextNameMap = true;
                $legacyNameMaxCharacters = null;
            }
        }

        $nameRows = [];
        foreach ($names as $index => $name) {
            $row = is_array($name) ? $name : [];
            $nameRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'name_index' => (int)$index,
                'name_text' => (string)($row['name'] ?? $row['text'] ?? ''),
                'flags' => isset($row['flags']) ? (int)$row['flags'] : null,
            ];
        }

        if ($legacyAllContextNameMap) {
            $effectiveNames = CatalogLegacyNameMapPreprocessor::effectiveNameMap(
                $nameRows,
                CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS,
                $legacyNameMaxCharacters
            );
            foreach ($imports as &$import) {
                if (!is_array($import)) { continue; }
                foreach ([
                    ['classPackage','ClassPackage','classPackageText'],
                    ['className','ClassName','classNameText'],
                    ['objectName','ObjectName','objectNameText'],
                    ['objectPackage','ObjectPackage','objectPackageText'],
                ] as [$indexKey,$objectKey,$textKey]) {
                    if (!array_key_exists($indexKey, $import) && !array_key_exists($objectKey, $import)) {
                        continue;
                    }
                    $nameIndex = $this->fnameIndex($import[$indexKey] ?? $import[$objectKey] ?? null);
                    $fallback = (string)($import[$textKey] ?? (
                        is_array($import[$objectKey] ?? null) ? ($import[$objectKey]['text'] ?? '') : ''
                    ));
                    $text = CatalogLegacyNameMapPreprocessor::effectiveText($effectiveNames, $nameIndex, $fallback);
                    $import[$textKey] = $text;
                    if (is_array($import[$objectKey] ?? null)) {
                        $import[$objectKey]['text'] = $text;
                    }
                }
            }
            unset($import);
            foreach ($exports as &$export) {
                if (!is_array($export)) { continue; }
                $nameIndex = $this->fnameIndex(
                    $export['objectName'] ?? $export['ObjectName'] ?? $export['nameIndex'] ?? null
                );
                $fallback = (string)($export['objectNameText'] ?? (
                    is_array($export['ObjectName'] ?? null) ? ($export['ObjectName']['text'] ?? '') : ''
                ));
                $text = CatalogLegacyNameMapPreprocessor::effectiveText($effectiveNames, $nameIndex, $fallback);
                $export['objectNameText'] = $text;
                if (is_array($export['ObjectName'] ?? null)) {
                    $export['ObjectName']['text'] = $text;
                }
            }
            unset($export);
        }

        $nameUsage = [];
        foreach ($nameRows as $nameRow) {
            $nameUsage[(int)$nameRow['name_index']] = [
                'imports_count' => 0,
                'exports_count' => 0,
                'first_import_index' => null,
                'first_export_index' => null,
            ];
        }

        $common = array_map(
            'strtolower',
            array_values((array)($this->config['common_packages'] ?? []))
        );
        $cache = [];
        $importRows = [];
        $importPaths = [];
        foreach ($imports as $index => $import) {
            $row = is_array($import) ? $import : [];
            $objectPackagePresent = array_key_exists('objectPackage', $row) && $row['objectPackage'] !== null;
            $objectPackage = $objectPackagePresent
                ? (string)($row['objectPackageText'] ?? ($row['ObjectPackage']['text'] ?? ''))
                : '';
            if ($objectPackagePresent) {
                $objectNameText = (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? ''));
                $rootPackage = strcasecmp($objectPackage, 'None') === 0 ? '' : $objectPackage;
                $relativeObjectPath = $objectNameText;
                $fullPath = $rootPackage !== ''
                    ? \scanner_join_path_parts([$rootPackage, $objectNameText])
                    : $objectNameText;
            } else {
                $fullPath = \scanner_ref_path(-((int)$index + 1), $imports, $exports, $cache);
                $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
                $rootPackage = (string)($parts[0] ?? '');
                $relativeObjectPath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            }
            $classPackageNameIndex = $this->fnameIndex($row['classPackage'] ?? ($row['ClassPackage'] ?? null));
            $classNameIndex = $this->fnameIndex($row['className'] ?? ($row['ClassName'] ?? null));
            $objectPackageNameIndex = $objectPackagePresent
                ? $this->fnameIndex($row['objectPackage'] ?? ($row['ObjectPackage'] ?? null))
                : null;
            $objectNameIndex = $this->fnameIndex($row['objectName'] ?? ($row['ObjectName'] ?? null));
            foreach (array_unique([$classPackageNameIndex, $classNameIndex, $objectPackageNameIndex, $objectNameIndex]) as $nameIndex) {
                if ($nameIndex !== null && isset($nameUsage[$nameIndex])) {
                    $nameUsage[$nameIndex]['imports_count']++;
                    $nameUsage[$nameIndex]['first_import_index'] ??= (int)$index;
                }
            }
            $importRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'import_index' => (int)$index,
                'class_package' => (string)($row['classPackageText'] ?? ($row['ClassPackage']['text'] ?? '')),
                'class_package_name_index' => $classPackageNameIndex,
                'class_name' => (string)($row['classNameText'] ?? ($row['ClassName']['text'] ?? '')),
                'class_name_index' => $classNameIndex,
                'object_name' => (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? '')),
                'object_name_index' => $objectNameIndex,
                'object_package_present' => $objectPackagePresent ? 1 : 0,
                'object_package' => $objectPackage,
                'object_package_name_index' => $objectPackageNameIndex,
                'outer_index' => (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0),
                'full_path' => $fullPath,
                'root_package' => $rootPackage,
                'relative_object_path' => $relativeObjectPath,
                'is_common' => in_array(strtolower($rootPackage), $common, true) ? 1 : 0,
            ];
            $importPaths[(int)$index] = [
                'full' => $fullPath,
                'root' => $rootPackage,
                'relative' => $relativeObjectPath,
            ];
        }

        $exportRows = [];
        $exportPaths = [];
        foreach ($exports as $index => $export) {
            $row = is_array($export) ? $export : [];
            $localPath = \scanner_ref_path((int)$index + 1, $imports, $exports, $cache);
            $classReference = (int)($row['classIndex'] ?? $row['class'] ?? 0);
            $className = $classReference !== 0
                ? \scanner_ref_path($classReference, $imports, $exports, $cache)
                : '';
            $fullPath = \scanner_join_path_parts([$packageName, $localPath]);
            $objectNameIndex = $this->fnameIndex($row['objectName'] ?? ($row['ObjectName'] ?? ($row['nameIndex'] ?? null)));
            if ($objectNameIndex !== null && isset($nameUsage[$objectNameIndex])) {
                $nameUsage[$objectNameIndex]['exports_count']++;
                $nameUsage[$objectNameIndex]['first_export_index'] ??= (int)$index;
            }
            $exportRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'export_index' => (int)$index,
                'class_name' => $className,
                'class_index' => $classReference,
                'super_index' => (int)($row['superIndex'] ?? $row['super'] ?? 0),
                'template_index' => (int)($row['templateIndex'] ?? $row['archetype'] ?? $row['archetypeIndexRef'] ?? 0),
                'object_name' => (string)($row['objectNameText'] ?? ''),
                'object_name_index' => $objectNameIndex,
                'outer_index' => (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0),
                'local_path' => $localPath,
                'full_path' => $fullPath,
                'object_flags' => isset($row['objectFlags']) ? (int)$row['objectFlags'] : null,
                'serial_size' => isset($row['serialSize']) ? (int)$row['serialSize'] : null,
                'serial_offset' => isset($row['serialOffset']) ? (int)$row['serialOffset'] : null,
            ];
            $exportPaths[(int)$index] = [
                'local' => $localPath,
                'full' => $fullPath,
            ];
        }

        foreach ($nameRows as &$nameRow) {
            $usage = $nameUsage[(int)$nameRow['name_index']];
            $nameRow['imports_count'] = $usage['imports_count'];
            $nameRow['exports_count'] = $usage['exports_count'];
            $nameRow['first_import_index'] = $usage['first_import_index'];
            $nameRow['first_export_index'] = $usage['first_export_index'];
        }
        unset($nameRow);

        $snapshot = [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
                'original_name' => $originalName,
                'name_count' => count($nameRows),
                'import_count' => count($importRows),
                'export_count' => count($exportRows),
                'scan_status' => 'verified',
            ],
            'names' => $nameRows,
            'imports' => $importRows,
            'exports' => $exportRows,
            'dependencies' => [],
            'paths' => [
                'imports' => $importPaths,
                'exports' => $exportPaths,
            ],
            'source_format' => 'parsed-package-current-no-dependencies',
        ];

        require_once __DIR__ . '/CatalogCompactIdentityEnricher.php';
        return CatalogCompactIdentityEnricher::enrich($snapshot, $engineKey);
    }

    /**
     * Complete a parsed snapshot without provider matching.
     *
     * Full Sync uses this during its source pass so every package publishes
     * current parser-owned metadata before any dependency is resolved. The
     * second pass then resolves these rows against the complete selected-game
     * provider set without reopening the Unreal package.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function withUnresolvedDependencies(array $snapshot): array
    {
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Parsed compact dependency staging requires valid file and game identities.');
        }

        $dependencies = [];
        foreach (array_values((array)($snapshot['imports'] ?? [])) as $import) {
            if (!is_array($import)) {
                throw new RuntimeException('Parsed compact Import snapshot contains a non-row value.');
            }
            $dependencies[] = [
                'file_id' => $fileId,
                'import_index' => (int)($import['import_index'] ?? -1),
                'required_package' => (string)($import['root_package'] ?? ''),
                'required_object_path' => (string)($import['full_path'] ?? ''),
                'resolved_file_id' => null,
                'resolved_export_index' => null,
                'status' => (int)($import['is_common'] ?? 0) === 1 ? 'common' : 'missing',
                'resolution_source' => (int)($import['is_common'] ?? 0) === 1 ? 'common_script' : 'none',
                'resolution_confidence' => (int)($import['is_common'] ?? 0) === 1 ? 'common' : 'missing',
            ];
        }
        if (count($dependencies) !== count((array)($snapshot['imports'] ?? []))) {
            throw new RuntimeException('Parsed compact dependency staging did not produce one row per Import.');
        }

        $snapshot['dependencies'] = $dependencies;
        $snapshot['source_format'] = 'parsed-package-current-dependencies-deferred';
        return $snapshot;
    }

    private static function ue1VerifyImportProfile(int $gameId, ?int $packageVersion, int $licenseeVersion): ?string
    {
        if ($packageVersion === null || $packageVersion <= 0 || $licenseeVersion !== 0) { return null; }
        try {
            $sourceKey = Uedb5GameSourceRegistry::sourceKey($gameId);
        } catch (\Throwable) {
            return null;
        }
        if ($sourceKey === 'ut99' && $packageVersion <= 68) {
            return \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver::PROFILE_UT99_V1400;
        }
        if ($sourceKey === 'unrealgold' && $packageVersion < 60) {
            return \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver::PROFILE_UNREAL_V120;
        }
        return null;
    }

    private static function ue2VerifyImportProfile(int $gameId, ?int $packageVersion): ?string
    {
        if ($packageVersion === null || $packageVersion <= 0) { return null; }
        try {
            $sourceKey = Uedb5GameSourceRegistry::sourceKey($gameId);
        } catch (\Throwable) {
            return null;
        }
        if ($sourceKey === 'unreal2' && $packageVersion >= 60 && $packageVersion <= 69) {
            return \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000;
        }
        if ($sourceKey === 'ut2003' && $packageVersion >= 60 && $packageVersion <= 120) {
            return \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver::PROFILE_UT2003_V2107;
        }
        if ($sourceKey === 'ut2004' && $packageVersion >= 60 && $packageVersion <= 129) {
            return \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver::PROFILE_UT2004_V129;
        }
        return null;
    }

    /**
     * Resolve dependencies for an already normalized parsed snapshot.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function withDependencies(array $snapshot): array
    {
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        $importRows = array_values((array)($snapshot['imports'] ?? []));
        $exportRows = array_values((array)($snapshot['exports'] ?? []));
        $packageName = trim((string)($file['package_name'] ?? ''));
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Parsed compact dependency resolution requires valid file and game identities.');
        }

        $engineRow = \catalog_one(
            $this->db,
            'SELECT p.engine_key,f.package_version,f.licensee_version,g.slug game_slug FROM ue_files f'
            . ' JOIN ue_games g ON g.id=f.game_id'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
            . ' WHERE f.id=? LIMIT 1',
            [$fileId]
        );
        $engineKey = strtoupper(trim((string)($engineRow['engine_key'] ?? '')));
        $packageVersion = isset($engineRow['package_version']) ? (int)$engineRow['package_version'] : null;
        $licenseeVersion = isset($engineRow['licensee_version']) ? (int)$engineRow['licensee_version'] : 0;
        $ue1VerifyImport = $engineKey === 'UE1';
        $ue2VerifyImport = $engineKey === 'UE2';
        $legacyVerifyImport = $ue1VerifyImport || $ue2VerifyImport;
        $ue1Profile = $ue1VerifyImport
            ? self::ue1VerifyImportProfile($gameId, $packageVersion, $licenseeVersion)
            : null;
        $ue2Profile = $ue2VerifyImport
            ? self::ue2VerifyImportProfile($gameId, $packageVersion)
            : null;
        $ue3Engine = $engineKey === 'UE3';
        $ue3Profile = $ue3Engine
            && strtolower(trim((string)($engineRow['game_slug'] ?? ''))) === 'ut3'
            && $packageVersion === 512
            && $licenseeVersion === 0
                ? \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver::PROFILE_UT3_V512
                : null;
        $ue3VerifyImport = $ue3Profile !== null;
        $ue4Engine = $engineKey === 'UE4';
        $ue4Profile = $ue4Engine
            && strtolower(trim((string)($engineRow['game_slug'] ?? ''))) === 'ut4'
                ? \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver::PROFILE_UT4_4272
                : null;
        $ue4VerifyImport = $ue4Profile !== null;
        $ue3ImportsByIndex = [];
        if ($ue3VerifyImport) {
            foreach ($importRows as $fallback => $import) {
                if (is_array($import)) {
                    $index = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                    $ue3ImportsByIndex[$index] = $import;
                }
            }
            $ue3ImportsByIndex = CatalogCompactIdentityEnricher::ue3FixupImportMap($ue3ImportsByIndex);
        }
        $effectiveIdentity = static function (array $import) use ($ue3Engine, $ue3VerifyImport, $ue3ImportsByIndex): array {
            if (!$ue3Engine || !$ue3VerifyImport) {
                return [
                    'root' => (string)($import['root_package'] ?? ''),
                    'full' => (string)($import['full_path'] ?? ''),
                ];
            }
            $path = CatalogCompactIdentityEnricher::ue3EffectiveImportPath(
                $ue3ImportsByIndex,
                (int)($import['import_index'] ?? -1)
            );
            return [
                'root' => $path['root'] !== '' ? $path['root'] : (string)($import['root_package'] ?? ''),
                'full' => $path['full'] !== '' ? $path['full'] : (string)($import['full_path'] ?? ''),
            ];
        };

        $localExports = [];
        $localUe1VerifyImportOutcomes = [];
        $localUe2VerifyImportOutcomes = [];
        $localUe3VerifyImportOutcomes = [];
        $localUe4VerifyImportOutcomes = [];
        if ($ue1VerifyImport && $ue1Profile !== null) {
            require_once dirname(__DIR__) . '/Persistence/PdoUe1VerifyImportProjectionResolver.php';
            $localUe1VerifyImportOutcomes =
                \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver::resolveInMemoryOutcome(
                    $ue1Profile,
                    $importRows,
                    $importRows,
                    $exportRows,
                    $packageName,
                    $packageVersion,
                    $packageVersion
                );
        } elseif ($ue2Profile !== null) {
            require_once dirname(__DIR__) . '/Persistence/PdoUe2VerifyImportProjectionResolver.php';
            $localUe2VerifyImportOutcomes =
                \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver::resolveInMemoryOutcome(
                    $ue2Profile,
                    $importRows,
                    $importRows,
                    $exportRows,
                    $packageName
                );
        } elseif ($ue3VerifyImport) {
            require_once dirname(__DIR__) . '/Persistence/PdoUe3VerifyImportProjectionResolver.php';
            $localUe3VerifyImportOutcomes =
                \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver::resolveInMemoryOutcome(
                    $ue3Profile,
                    $importRows,
                    $importRows,
                    $exportRows,
                    $packageName,
                    $packageVersion,
                    $exportRows
                );
        } elseif ($ue4VerifyImport) {
            require_once dirname(__DIR__) . '/Persistence/PdoUe4VerifyImportProjectionResolver.php';
            $ue4TableOutcome = \UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
                $importRows,
                $importRows,
                $exportRows,
                $packageName,
                $exportRows,
                $importRows
            );
            $localUe4VerifyImportOutcomes = (array)($ue4TableOutcome['source_outcomes'] ?? []);
        } else {
            foreach ($exportRows as $export) {
                if (!is_array($export)) {
                    continue;
                }
                $lookupKey = $this->lookupKey((string)($export['full_path'] ?? ''));
                if ($lookupKey !== '' && !isset($localExports[$lookupKey])) {
                    $localExports[$lookupKey] = (int)($export['export_index'] ?? 0);
                }
            }
        }

        $resolutions = PdoDependencyResolver::resolve(
            $this->db,
            $gameId,
            $fileId,
            $importRows,
            trim((string)($this->config['storage_path'] ?? '')) ?: null,
            $exportRows
        );
        $dependencies = [];
        foreach ($importRows as $import) {
            if (!is_array($import)) {
                continue;
            }
            $importId = (int)$import['id'];
            $resolution = $resolutions[$importId] ?? [
                'status' => 'missing',
                'resolved_file_id' => null,
                'resolved_export_index' => null,
                'source' => 'none',
                'confidence' => 'missing',
            ];

            // The file being published does not have lookup projections yet.
            // UE1/UE2, UT3 and UT4 use their source-specific in-memory
            // VerifyImport semantics. Unprofiled engines must not be certified
            // by generic local path coverage.
            $localExportIndex = null;
            $localUnreal2OnlyIndex = null;
            $localUe1Reason = null;
            $localUe2Reason = null;
            $localUe3Outcome = [];
            $localUe4Outcome = [];
            if ($ue1VerifyImport
                && $ue1Profile !== null
                && $this->lookupKey((string)($import['root_package'] ?? '')) === $this->lookupKey($packageName)) {
                $importIndex = (int)($import['import_index'] ?? -1);
                $ue1Outcome = (array)($localUe1VerifyImportOutcomes[$importIndex] ?? []);
                if (($ue1Outcome['status'] ?? '') === 'resolved') {
                    $localExportIndex = $ue1Outcome['export_index'] ?? null;
                    $localUe1Reason = (string)($ue1Outcome['reason'] ?? 'exact_verify_import_match');
                }
            } elseif ($ue2Profile !== null
                && $this->lookupKey((string)($import['root_package'] ?? '')) === $this->lookupKey($packageName)) {
                $importIndex = (int)($import['import_index'] ?? -1);
                $ue2Outcome = (array)($localUe2VerifyImportOutcomes[$importIndex] ?? []);
                if (($ue2Outcome['status'] ?? '') === 'resolved') {
                    $localExportIndex = $ue2Outcome['export_index'] ?? null;
                    $localUe2Reason = (string)($ue2Outcome['reason'] ?? 'exact_verify_import_match');
                } elseif (($ue2Outcome['status'] ?? '') === 'private_export') {
                    $localUnreal2OnlyIndex = $ue2Outcome['candidate_export_index'] ?? null;
                }
            } elseif ($ue3VerifyImport
                && $this->lookupKey((string)$effectiveIdentity($import)['root']) === $this->lookupKey($packageName)) {
                $importIndex = (int)($import['import_index'] ?? -1);
                $localUe3Outcome = (array)($localUe3VerifyImportOutcomes[$importIndex] ?? []);
                if (($localUe3Outcome['status'] ?? '') === 'resolved') {
                    $localExportIndex = $localUe3Outcome['export_index'] ?? null;
                }
            } elseif ($ue4VerifyImport
                && $this->lookupKey((string)($import['root_package'] ?? '')) === $this->lookupKey($packageName)) {
                $importIndex = (int)($import['import_index'] ?? -1);
                $localUe4Outcome = (array)($localUe4VerifyImportOutcomes[$importIndex] ?? []);
                if (($localUe4Outcome['status'] ?? '') === 'resolved') {
                    $localExportIndex = $localUe4Outcome['export_index'] ?? null;
                }
            } elseif (!$legacyVerifyImport && !$ue3Engine && !$ue4Engine) {
                $localExportIndex = $localExports[$this->lookupKey((string)$import['full_path'])] ?? null;
            }
            if ($localExportIndex !== null && (int)$import['is_common'] !== 1) {
                $resolution = [
                    'status' => 'resolved',
                    'resolved_file_id' => $fileId,
                    'resolved_export_index' => (int)$localExportIndex,
                    'source' => $ue1VerifyImport
                        ? 'ue1_verify_import_local_' . ($localUe1Reason ?? 'exact')
                        : ($ue2Profile !== null
                            ? 'ue2_verify_import_local_' . ($localUe2Reason ?? 'exact')
                            : ($ue3VerifyImport
                                ? 'ue3_verify_import_local_' . (string)($localUe3Outcome['reason'] ?? 'exact')
                                : ($ue4VerifyImport
                                    ? 'ue4_verify_import_local_' . (string)($localUe4Outcome['reason'] ?? 'exact')
                                    : 'exact_object'))),
                    'confidence' => 'exact',
                ];
            } elseif ($ue3Engine && (int)$import['is_common'] !== 1) {
                if ($ue3Profile === null) {
                    $resolution = [
                        'status'=>'unresolved','resolved_file_id'=>null,'resolved_export_index'=>null,
                        'source'=>'ue3_verify_import_source_implementation_unavailable','confidence'=>'source_unresolved',
                    ];
                } elseif ($localUe3Outcome !== []) {
                    $status=(string)($localUe3Outcome['status'] ?? '');
                    if ($status === 'private_export') {
                        $resolution = [
                            'status'=>'missing','resolved_file_id'=>null,'resolved_export_index'=>null,
                            'source'=>'ue3_'.(string)($localUe3Outcome['reason'] ?? 'private_export'),'confidence'=>'source_rejected',
                        ];
                    } elseif (in_array($status,['runtime_only','unresolved','invalid','ignored'],true)) {
                        $resolution = [
                            'status'=>'unresolved','resolved_file_id'=>null,'resolved_export_index'=>null,
                            'source'=>'ue3_'.(string)($localUe3Outcome['reason'] ?? 'runtime_context_unavailable'),
                            'confidence'=>$status==='runtime_only'?'runtime_unavailable':'source_unresolved',
                        ];
                    }
                }
            } elseif ($ue4Engine && (int)$import['is_common'] !== 1) {
                if ($ue4Profile === null) {
                    $resolution = [
                        'status'=>'unresolved','resolved_file_id'=>null,'resolved_export_index'=>null,
                        'source'=>'ue4_verify_import_source_implementation_unavailable','confidence'=>'source_unresolved',
                    ];
                } elseif ($localUe4Outcome !== []) {
                    $status=(string)($localUe4Outcome['status'] ?? '');
                    $reason=(string)($localUe4Outcome['reason'] ?? 'runtime_context_unavailable');
                    if ($status === 'private_export') {
                        $resolution = [
                            'status'=>'missing','resolved_file_id'=>null,'resolved_export_index'=>null,
                            'source'=>'ue4_'.$reason,'confidence'=>'source_rejected',
                        ];
                    } elseif (in_array($status,['runtime_only','unresolved','invalid','ignored'],true)) {
                        $resolution = [
                            'status'=>'unresolved','resolved_file_id'=>null,'resolved_export_index'=>null,
                            'source'=>'ue4_'.$reason,
                            'confidence'=>$status==='runtime_only'?'runtime_unavailable':'source_unresolved',
                        ];
                    }
                }
            } elseif ($localUnreal2OnlyIndex !== null && (int)$import['is_common'] !== 1) {
                $resolution = [
                    'status' => 'missing',
                    'resolved_file_id' => null,
                    'resolved_export_index' => null,
                    'source' => $ue2Profile !== null
                        ? 'ue2_verify_import_private_export'
                        : 'ue2_verify_import_private_export',
                    'confidence' => $ue2Profile !== null ? 'source_rejected' : 'unreal2_candidate',
                ];
            }

            $identity = $effectiveIdentity($import);
            $dependencies[] = [
                'file_id' => $fileId,
                'import_index' => (int)$import['import_index'],
                'required_package' => (string)$identity['root'],
                'required_object_path' => (string)$identity['full'],
                'resolved_file_id' => $resolution['resolved_file_id'] !== null
                    ? (int)$resolution['resolved_file_id']
                    : null,
                'resolved_export_index' => $resolution['resolved_export_index'] !== null
                    ? (int)$resolution['resolved_export_index']
                    : null,
                'status' => (string)($resolution['status'] ?? 'missing'),
                'resolution_source' => (string)($resolution['source'] ?? 'none'),
                'resolution_confidence' => (string)($resolution['confidence'] ?? 'missing'),
            ];
        }

        if (count($dependencies) !== count($importRows)) {
            throw new RuntimeException('Parsed compact metadata did not produce one dependency row per Import.');
        }

        $snapshot['dependencies'] = $dependencies;
        $snapshot['source_format'] = 'parsed-package-current';
        return $snapshot;
    }

    /**
     * Binary-safe semantic fingerprint of the package-owned metadata sections.
     *
     * Virtual/physical row IDs and dependency resolution are intentionally omitted.
     * Loader JSON numeric strings are normalized to the same representation as
     * current parser integers so an unchanged package compares equal without a
     * rewrite.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function parsedContentFingerprint(array $snapshot): string
    {
        $file = (array)($snapshot['file'] ?? []);
        $canonical = [
            'file' => [
                (int)($file['id'] ?? 0),
                (int)($file['game_id'] ?? 0),
                (string)($file['package_name'] ?? ''),
                (string)($file['original_name'] ?? ''),
                (int)($file['name_count'] ?? count((array)($snapshot['names'] ?? []))),
                (int)($file['import_count'] ?? count((array)($snapshot['imports'] ?? []))),
                (int)($file['export_count'] ?? count((array)($snapshot['exports'] ?? []))),
                (string)($file['scan_status'] ?? 'verified'),
            ],
            'names' => [],
            'imports' => [],
            'exports' => [],
        ];

        foreach ((array)($snapshot['names'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['names'][] = [
                (int)($row['name_index'] ?? 0),
                (string)($row['name_text'] ?? ''),
                self::nullableScalarString($row['flags'] ?? null),
                (int)($row['imports_count'] ?? 0),
                (int)($row['exports_count'] ?? 0),
                self::nullableScalarString($row['first_import_index'] ?? null),
                self::nullableScalarString($row['first_export_index'] ?? null),
            ];
        }
        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['imports'][] = [
                (int)($row['import_index'] ?? 0),
                trim((string)($row['class_package'] ?? '')),
                trim((string)($row['class_name'] ?? '')),
                trim((string)($row['verify_class_package'] ?? '')),
                trim((string)($row['verify_class_name'] ?? '')),
                (string)($row['verify_identity_hash'] ?? ''),
                (string)($row['path_hash_ci'] ?? ''),
                (string)($row['object_name'] ?? ''),
                (int)($row['outer_index'] ?? 0),
                (string)($row['full_path'] ?? ''),
                (string)($row['root_package'] ?? ''),
                (string)($row['relative_object_path'] ?? ''),
                (string)($row['verify_identity_hash'] ?? ''),
                (string)($row['path_hash_ci'] ?? ''),
                (int)($row['is_common'] ?? 0),
                self::nullableScalarString($row['class_package_name_index'] ?? null),
                self::nullableScalarString($row['class_name_index'] ?? null),
                self::nullableScalarString($row['object_name_index'] ?? null),
            ];
        }
        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['exports'][] = [
                (int)($row['export_index'] ?? 0),
                trim((string)($row['class_name'] ?? '')),
                (string)($row['object_name'] ?? ''),
                (int)($row['outer_index'] ?? 0),
                (string)($row['local_path'] ?? ''),
                (string)($row['full_path'] ?? ''),
                self::nullableScalarString($row['object_flags'] ?? null),
                self::nullableScalarString($row['serial_size'] ?? null),
                self::nullableScalarString($row['serial_offset'] ?? null),
                (int)($row['class_index'] ?? 0),
                (int)($row['super_index'] ?? 0),
                (int)($row['template_index'] ?? 0),
                self::nullableScalarString($row['object_name_index'] ?? null),
            ];
        }

        return hash('sha256', serialize($canonical));
    }

    /**
     * Fingerprint of parser/search structure used only by the UE3 Full Sync
     * identity-refresh fast path. ObjectFlags and derived hash/VerifyImport
     * fields are excluded because they are exactly what that repair replaces.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function parsedStructureFingerprint(array $snapshot): string
    {
        $file = (array)($snapshot['file'] ?? []);
        $canonical = [
            'file' => [
                (int)($file['id'] ?? 0),
                (int)($file['game_id'] ?? 0),
                (string)($file['package_name'] ?? ''),
                (string)($file['original_name'] ?? ''),
                (int)($file['name_count'] ?? count((array)($snapshot['names'] ?? []))),
                (int)($file['import_count'] ?? count((array)($snapshot['imports'] ?? []))),
                (int)($file['export_count'] ?? count((array)($snapshot['exports'] ?? []))),
                (string)($file['scan_status'] ?? 'verified'),
            ],
            'names' => [],
            'imports' => [],
            'exports' => [],
        ];

        foreach ((array)($snapshot['names'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['names'][] = [
                (int)($row['name_index'] ?? 0),
                (string)($row['name_text'] ?? ''),
                self::nullableScalarString($row['flags'] ?? null),
                (int)($row['imports_count'] ?? 0),
                (int)($row['exports_count'] ?? 0),
                self::nullableScalarString($row['first_import_index'] ?? null),
                self::nullableScalarString($row['first_export_index'] ?? null),
            ];
        }
        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['imports'][] = [
                (int)($row['import_index'] ?? 0),
                trim((string)($row['class_package'] ?? '')),
                trim((string)($row['class_name'] ?? '')),
                (string)($row['object_name'] ?? ''),
                (int)($row['outer_index'] ?? 0),
                (string)($row['full_path'] ?? ''),
                (string)($row['root_package'] ?? ''),
                (string)($row['relative_object_path'] ?? ''),
                (int)($row['is_common'] ?? 0),
                self::nullableScalarString($row['class_package_name_index'] ?? null),
                self::nullableScalarString($row['class_name_index'] ?? null),
                self::nullableScalarString($row['object_name_index'] ?? null),
            ];
        }
        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['exports'][] = [
                (int)($row['export_index'] ?? 0),
                trim((string)($row['class_name'] ?? '')),
                (string)($row['object_name'] ?? ''),
                (int)($row['outer_index'] ?? 0),
                (string)($row['local_path'] ?? ''),
                (string)($row['full_path'] ?? ''),
                self::nullableScalarString($row['serial_size'] ?? null),
                self::nullableScalarString($row['serial_offset'] ?? null),
                (int)($row['class_index'] ?? 0),
                (int)($row['super_index'] ?? 0),
                (int)($row['template_index'] ?? 0),
                self::nullableScalarString($row['object_name_index'] ?? null),
            ];
        }
        return hash('sha256', serialize($canonical));
    }

    private function fnameIndex(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value['index'] ?? null;
        }
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private static function nullableScalarString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    private function virtualId(int $fileId, int $index): int
    {
        return ($fileId * 4294967296) + $index + 1;
    }

    private function lookupKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
