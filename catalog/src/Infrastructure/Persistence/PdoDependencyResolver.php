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
        ?string $storageRoot = null,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        $engineKey = self::engineKey($db, $gameId);
        $legacyVerifyImport = in_array($engineKey, ['UE1', 'UE2'], true);
        $fileIdentity = in_array($engineKey, ['UE1','UE2'], true)
            ? self::filePackageIdentity($db, $fileId)
            : ['version'=>0,'licensee'=>0];
        $ue1Profile = $engineKey === 'UE1'
            ? self::ue1VerifyImportProfile($gameId, (int)$fileIdentity['version'], (int)$fileIdentity['licensee'])
            : null;
        $ue2Profile = $engineKey === 'UE2'
            ? self::ue2VerifyImportProfile($gameId, (int)$fileIdentity['version'], (int)$fileIdentity['licensee'])
            : null;
        $ue3VerifyImport = $engineKey === 'UE3';
        $ue4VerifyImport = $engineKey === 'UE4';
        $sourceFnameLookup = $legacyVerifyImport || $ue3VerifyImport || $ue4VerifyImport;

        $importsByIndex = [];
        foreach ($imports as $fallback => $import) {
            if (is_array($import)) {
                $index = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                $importsByIndex[$index] = $import;
            }
        }
        $legacySourceIrrelevant = $engineKey === 'UE1'
            ? ($ue1Profile !== null ? self::legacySourceIrrelevantIndexes($importsByIndex, false) : [])
            : ($engineKey === 'UE2'
                ? ($ue2Profile !== null
                    ? self::legacySourceIrrelevantIndexes($importsByIndex, false)
                    : [])
                : []);
        $legacyRootPackages = [];
        if ($legacyVerifyImport) {
            foreach (array_keys($importsByIndex) as $importIndex) {
                $legacyRootPackages[(int)$importIndex] = self::legacyRootPackageName($importsByIndex, (int)$importIndex);
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

        $ue4RootPackages = [];
        if ($ue4VerifyImport) {
            foreach (array_keys($importsByIndex) as $importIndex) {
                $ue4RootPackages[(int)$importIndex] = self::ue4RootPackageName($importsByIndex, (int)$importIndex);
            }
        }

        if ($ue4VerifyImport) {
            foreach ($imports as $fallback => &$import) {
                if (!is_array($import)) { continue; }
                $index = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                $rawRoot = (string)($ue4RootPackages[$index] ?? '');
                if ($rawRoot !== '') {
                    $import['root_package'] = $rawRoot;
                    $import['is_common'] = strncasecmp($rawRoot, '/Script/', 8) === 0 ? 1 : 0;
                }
            }
            unset($import);
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
                if (strcasecmp((string)($import['root_package'] ?? ''), 'SequenceObjects') === 0
                    && strcasecmp((string)($ue3RootPackages[$importIndex] ?? ''), 'Engine') === 0) {
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
            if ($legacyVerifyImport && isset($legacySourceIrrelevant[$importIndex])) {
                continue;
            }
            $rootPackage = $ue3VerifyImport
                ? (string)($ue3RootPackages[$importIndex] ?? '')
                : ($legacyVerifyImport
                    ? (string)($legacyRootPackages[$importIndex] ?? '')
                    : ($ue4VerifyImport
                        ? self::ue4ProviderPackageName($ue4RootPackages, $import, $importIndex)
                        : trim((string)($import['root_package'] ?? ''))));
            if ($rootPackage !== '') {
                $key = self::lookupKey($rootPackage, $sourceFnameLookup);
                if ($key !== '' && !isset($packageNames[$key])) {
                    $packageNames[$key] = $rootPackage;
                }
            }
            $relativeObjectPath = trim((string)($import['relative_object_path'] ?? ''));
            $fullPath = trim((string)($import['full_path'] ?? ''));
            if (!$ue3VerifyImport && !$ue4VerifyImport && $rootPackage !== '' && $relativeObjectPath !== '' && $fullPath !== '') {
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

        $packageMatches = self::loadPackageMatches($db, $gameId, $fileId, array_values($packageNames), $sourceFnameLookup);
        $ambiguousPackageProviders = [];
        if ($legacyVerifyImport || $ue3VerifyImport || $ue4VerifyImport) {
            $physicalCandidates = self::loadPackageCandidates($db, $gameId, $fileId, array_values($packageNames), $sourceFnameLookup);
            foreach ($physicalCandidates as $packageKey => $candidates) {
                if (count($candidates) <= 1) { continue; }
                $ambiguousPackageProviders[$packageKey] = array_values(array_map(
                    static fn(array $candidate): int => (int)$candidate['file_id'],
                    $candidates
                ));
                unset($packageMatches[$packageKey]);
            }
        }

        if (($engineKey === 'UE1' && $ue1Profile === PdoUe1VerifyImportProjectionResolver::PROFILE_UT99_V1400)
            || ($engineKey === 'UE2' && $ue2Profile === PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000)) {
            $unrealIKey = self::sourceFnameLookup('UnrealI');
            if (isset($packageNames[$unrealIKey])
                && !isset($packageMatches[$unrealIKey])
                && !isset($ambiguousPackageProviders[$unrealIKey])) {
                // UT99 v1.400 retries a failed UnrealI package load as
                // UnrealShare. Keep the requirement keyed as UnrealI while the
                // selected physical provider is the source-defined fallback.
                $fallbackCandidates = self::loadPackageCandidates(
                    $db, $gameId, $fileId, ['UnrealShare'], true
                );
                $unrealShareKey = self::sourceFnameLookup('UnrealShare');
                $fallbackRows = (array)($fallbackCandidates[$unrealShareKey] ?? []);
                if (count($fallbackRows) > 1) {
                    $ambiguousPackageProviders[$unrealIKey] = array_values(array_map(
                        static fn(array $candidate): int => (int)$candidate['file_id'],
                        $fallbackRows
                    ));
                } elseif (count($fallbackRows) === 1) {
                    $packageMatches[$unrealIKey] = [
                        'file_id' => (int)$fallbackRows[0]['file_id'],
                        'source' => $engineKey === 'UE1' ? 'ut99_unreali_to_unrealshare' : 'unreal2_v69_unreali_to_unrealshare',
                    ];
                }
            }
        }
        $packageRequirements = [];
        if ($legacyVerifyImport) {
            // UE1/UE2 VerifyImport decides object-vs-package semantics from the
            // serialized PackageIndex/outer graph. Derived display paths may be
            // empty for valid FNames (for example a literal whitespace name).
            foreach ($imports as $fallback => $import) {
                if (!is_array($import) || self::isCommonImport($import, $engineKey)
                    || !self::isSourceObjectImport($import, $engineKey)) {
                    continue;
                }
                $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                if (isset($legacySourceIrrelevant[$importIndex])) {
                    continue;
                }
                $rootPackage = (string)($legacyRootPackages[$importIndex] ?? '');
                $packageKey = self::sourceFnameLookup($rootPackage);
                if ($packageKey !== '') {
                    $packageRequirements[$packageKey]['package_name'] ??= $rootPackage;
                }
            }
        } elseif ($ue3VerifyImport) {
            foreach ($imports as $fallback => $import) {
                if (!is_array($import) || self::isCommonImport($import, $engineKey)
                    || !self::isSourceObjectImport($import, $engineKey)) {
                    continue;
                }
                $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                $rootPackage = (string)($ue3RootPackages[$importIndex] ?? '');
                $packageKey = self::sourceFnameLookup($rootPackage);
                if ($packageKey !== '') {
                    $packageRequirements[$packageKey]['package_name'] ??= $rootPackage;
                }
            }
        } elseif ($ue4VerifyImport) {
            foreach ($imports as $fallback => $import) {
                if (!is_array($import) || self::isCommonImport($import, $engineKey)
                    || !self::isSourceObjectImport($import, $engineKey)) {
                    continue;
                }
                $importIndex = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
                $rootPackage = self::ue4ProviderPackageName($ue4RootPackages, $import, $importIndex);
                $packageKey = self::sourceFnameLookup($rootPackage);
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

        // Epic selects one physical package/linker before import verification.
        // packageMatches is used only when the physical provider set is unique;
        // duplicate environments were removed above and remain unresolved because
        // their historical runtime package search/mount order is unavailable.
        // Provider contents must never choose a different physical package.
        $verifyImportMatches = [];
        $ue1VerifyImportOutcomes = [];
        $ue2VerifyImportOutcomes = [];
        if ($engineKey === 'UE1' && $ue1Profile !== null) {
            require_once __DIR__ . '/PdoUe1VerifyImportProjectionResolver.php';
            $consumerVersion = self::filePackageVersion($db, $fileId);
            foreach ($packageRequirements as $packageKey => $requirement) {
                $candidate = $packageMatches[$packageKey] ?? null;
                if (!is_array($candidate)) { continue; }
                $ue1VerifyImportOutcomes[$packageKey] = PdoUe1VerifyImportProjectionResolver::resolveProviderOutcome(
                    $db,
                    (int)$candidate['file_id'],
                    $imports,
                    $ue1Profile,
                    $consumerVersion
                );
            }
        } elseif ($engineKey === 'UE2' && $ue2Profile !== null) {
            require_once __DIR__ . '/PdoUe2VerifyImportProjectionResolver.php';
            foreach ($packageRequirements as $packageKey => $requirement) {
                $candidate = $packageMatches[$packageKey] ?? null;
                if (!is_array($candidate)) { continue; }
                $ue2VerifyImportOutcomes[$packageKey] = PdoUe2VerifyImportProjectionResolver::resolveProviderOutcome(
                    $db,
                    (int)$candidate['file_id'],
                    $imports,
                    $ue2Profile
                );
            }
        }

        $ue3VerifyImportMatches= [];
        if ($ue3VerifyImport) {
            require_once __DIR__ . '/PdoUe3VerifyImportProjectionResolver.php';
            foreach ($packageRequirements as $packageKey => $requirement) {
                $candidate = $packageMatches[$packageKey] ?? null;
                if (!is_array($candidate)) { continue; }
                $requiredImportIndexes = self::requiredImportIndexes(
                    $imports,
                    $packageKey,
                    $engineKey,
                    $ue3RootPackages
                );
                $ue3VerifyImportMatches[$packageKey] = PdoUe3VerifyImportProjectionResolver::resolveProvider(
                    $db,
                    (int)$candidate['file_id'],
                    $imports,
                    $requiredImportIndexes,
                    $storageRoot
                );
            }
        }

        $ue4VerifyImportMatches = [];
        $ue4VerifyImportRedirectors = [];
        $ue4VerifyImportRedirectorAncestry = [];
        if ($ue4VerifyImport) {
            require_once __DIR__ . '/PdoUe4VerifyImportProjectionResolver.php';
            foreach ($packageRequirements as $packageKey => $requirement) {
                $candidate = $packageMatches[$packageKey] ?? null;
                if (!is_array($candidate)) { continue; }
                $outcome = PdoUe4VerifyImportProjectionResolver::resolveProviderOutcome(
                    $db,
                    (int)$candidate['file_id'],
                    $imports,
                    $consumerExports,
                    $consumerGraphImports
                );
                $ue4VerifyImportMatches[$packageKey] = (array)($outcome['matches'] ?? []);
                $ue4VerifyImportRedirectors[$packageKey] = (array)($outcome['redirectors'] ?? []);
                $ue4VerifyImportRedirectorAncestry[$packageKey] = (array)($outcome['redirector_ancestry'] ?? []);
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
                : ($legacyVerifyImport
                    ? (string)($legacyRootPackages[$importIndex] ?? '')
                    : ($ue4VerifyImport
                        ? self::ue4ProviderPackageName($ue4RootPackages, $import, $importIndex)
                        : (string)($import['root_package'] ?? '')));
            $isObjectImport = self::isSourceObjectImport($import, $engineKey);
            $result = self::missing();

            if ($engineKey === 'UE1' && isset($legacySourceIrrelevant[$importIndex])) {
                $result = [
                    'status' => 'unresolved',
                    'resolved_file_id' => null,
                    'resolved_export_id' => null,
                    'resolved_export_index' => null,
                    'source' => 'source_irrelevant_name_none',
                    'confidence' => 'source_irrelevant',
                ];
            } elseif (self::isCommonImport($import, $engineKey)) {
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
            } elseif (isset($ambiguousPackageProviders[self::lookupKey($rootPackage, $sourceFnameLookup)])) {
                $result = [
                    'status' => 'unresolved',
                    'resolved_file_id' => null,
                    'resolved_export_id' => null,
                    'resolved_export_index' => null,
                    'source' => 'provider_environment_ambiguous',
                    'confidence' => 'source_unresolved',
                    'candidate_file_ids' => $ambiguousPackageProviders[self::lookupKey($rootPackage, $sourceFnameLookup)],
                ];
            } elseif (!$isObjectImport) {
                $packageMatch = $packageMatches[self::lookupKey($rootPackage, $sourceFnameLookup)] ?? null;
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
                $packageKey = self::lookupKey($rootPackage, $sourceFnameLookup);
                $packageMatch = $packageMatches[$packageKey] ?? null;

                if ($engineKey === 'UE1') {
                    if ($ue1Profile === null) {
                        $result = [
                            'status' => 'unresolved',
                            'resolved_file_id' => $packageMatch !== null ? (int)$packageMatch['file_id'] : null,
                            'resolved_export_id' => null,
                            'resolved_export_index' => null,
                            'source' => 'ue1_verify_import_source_implementation_unavailable',
                            'confidence' => 'source_unresolved',
                        ];
                    } else {
                        $ue1 = (array)(($ue1VerifyImportOutcomes[$packageKey] ?? [])[$importIndex] ?? []);
                        $status = (string)($ue1['status'] ?? '');
                        if ($packageMatch !== null && $status === 'resolved') {
                            $result = [
                                'status' => 'resolved',
                                'resolved_file_id' => (int)$packageMatch['file_id'],
                                'resolved_export_id' => null,
                                'resolved_export_index' => (int)($ue1['export_index'] ?? -1),
                                'source' => 'ue1_verify_import_' . (string)($ue1['reason'] ?? 'exact'),
                                'confidence' => 'exact',
                            ];
                        } elseif ($packageMatch !== null && $status === 'private_export') {
                            $result = [
                                'status' => 'missing',
                                'resolved_file_id' => null,
                                'resolved_export_id' => null,
                                'resolved_export_index' => null,
                                'source' => 'ue1_verify_import_private_export',
                                'confidence' => 'source_rejected',
                            ];
                        } elseif ($packageMatch !== null && $status === 'runtime_only') {
                            $result = [
                                'status' => 'unresolved',
                                'resolved_file_id' => null,
                                'resolved_export_id' => null,
                                'resolved_export_index' => null,
                                'source' => 'ue1_' . (string)($ue1['reason'] ?? 'runtime_only_fallback_unavailable'),
                                'confidence' => 'runtime_unavailable',
                            ];
                        } elseif ($packageMatch !== null && $status === 'invalid') {
                            $result = [
                                'status' => 'unresolved',
                                'resolved_file_id' => null,
                                'resolved_export_id' => null,
                                'resolved_export_index' => null,
                                'source' => 'ue1_' . (string)($ue1['reason'] ?? 'invalid_import_graph'),
                                'confidence' => 'source_invalid',
                            ];
                        }
                    }
                } elseif ($engineKey === 'UE2') {
                    if ($ue2Profile === null) {
                        $result = [
                            'status' => 'unresolved',
                            'resolved_file_id' => $packageMatch !== null ? (int)$packageMatch['file_id'] : null,
                            'resolved_export_id' => null,
                            'resolved_export_index' => null,
                            'source' => 'ue2_verify_import_source_implementation_unavailable',
                            'confidence' => 'source_unresolved',
                        ];
                    } elseif ($ue2Profile !== null) {
                        $ue2 = (array)(($ue2VerifyImportOutcomes[$packageKey] ?? [])[$importIndex] ?? []);
                        $status = (string)($ue2['status'] ?? '');
                        if ($packageMatch !== null && $status === 'resolved') {
                            $result = [
                                'status' => 'resolved','resolved_file_id'=>(int)$packageMatch['file_id'],
                                'resolved_export_id'=>null,'resolved_export_index'=>(int)($ue2['export_index'] ?? -1),
                                'source'=>'ue2_verify_import_'.(string)($ue2['reason'] ?? 'exact'),'confidence'=>'exact',
                            ];
                        } elseif ($packageMatch !== null && $status === 'private_export') {
                            $result = ['status'=>'missing','resolved_file_id'=>null,'resolved_export_id'=>null,'resolved_export_index'=>null,'source'=>'ue2_verify_import_private_export','confidence'=>'source_rejected'];
                        } elseif ($packageMatch !== null && in_array($status,['runtime_only','unresolved','invalid'],true)) {
                            $result = ['status'=>'unresolved','resolved_file_id'=>null,'resolved_export_id'=>null,'resolved_export_index'=>null,'source'=>'ue2_'.(string)($ue2['reason'] ?? 'runtime_only_fallback_unavailable'),'confidence'=>$status==='runtime_only'?'runtime_unavailable':'source_unresolved'];
                        }
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
                    $redirectorIndex = $ue4VerifyImportRedirectors[$packageKey][$importIndex] ?? null;
                    $redirectorAncestorIndex = $ue4VerifyImportRedirectorAncestry[$packageKey][$importIndex] ?? null;
                    if ($packageMatch !== null && $exportIndex !== null) {
                        $result = [
                            'status' => 'resolved',
                            'resolved_file_id' => (int)$packageMatch['file_id'],
                            'resolved_export_id' => null,
                            'resolved_export_index' => (int)$exportIndex,
                            'source' => 'ue4_verify_import',
                            'confidence' => 'exact',
                        ];
                    } elseif ($packageMatch !== null && $redirectorIndex !== null) {
                        $result = [
                            'status' => 'unresolved',
                            'resolved_file_id' => null,
                            'resolved_export_id' => null,
                            'resolved_export_index' => null,
                            'source' => 'ue4_object_redirector_target_unavailable',
                            'confidence' => 'payload_unresolved',
                        ];
                    } elseif ($packageMatch !== null && $redirectorAncestorIndex !== null) {
                        $result = [
                            'status' => 'unresolved',
                            'resolved_file_id' => null,
                            'resolved_export_id' => null,
                            'resolved_export_index' => null,
                            'source' => 'ue4_object_redirector_ancestor_target_unavailable',
                            'confidence' => 'payload_unresolved',
                        ];
                    }
                } else {
                    // No source-backed resolver is registered for this engine in
                    // the live V4 path. Do not substitute catalog object coverage.
                    $result = [
                        'status' => 'unresolved',
                        'resolved_file_id' => null,
                        'resolved_export_id' => null,
                        'resolved_export_index' => null,
                        'source' => 'source_profile_not_implemented',
                        'confidence' => 'source_unresolved',
                    ];
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
                : ($engineKey === 'UE4'
                    ? (($raw=self::ue4RootPackageName(self::indexRowsForRootLookup($imports), $importIndex)) !== ''
                    ? $raw : (string)($import['root_package'] ?? ''))
                    : (string)($import['root_package'] ?? ''));
            $isObjectImport = self::isSourceObjectImport($import, $engineKey);
            $exactFname = in_array($engineKey, ['UE1', 'UE2', 'UE3', 'UE4'], true);
            if (self::lookupKey($rootPackage, $exactFname) !== $packageKey || !$isObjectImport) {
                continue;
            }
            $indexes[] = $importIndex;
        }
        return $indexes;
    }

    /** @param array<int,array<string,mixed>> $importsByIndex @return array<int,array{reason:string,ancestor_index:int}> */
    private static function legacySourceIrrelevantIndexes(array $importsByIndex, bool $includeAncestors): array
    {
        $irrelevant = [];
        foreach ($importsByIndex as $index => $import) {
            if (self::legacyImportHasNameNone((array)$import)) {
                $irrelevant[(int)$index] = ['reason'=>'name_none','ancestor_index'=>(int)$index];
            }
        }
        if (!$includeAncestors) { return $irrelevant; }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($importsByIndex as $index => $import) {
                $index = (int)$index;
                if (isset($irrelevant[$index])) { continue; }
                $outer = (int)($import['outer_index'] ?? 0);
                if ($outer >= 0) { continue; }
                $parentIndex = -$outer - 1;
                if (!isset($irrelevant[$parentIndex])) { continue; }
                $irrelevant[$index] = [
                    'reason'=>'name_none_ancestor',
                    'ancestor_index'=>(int)$irrelevant[$parentIndex]['ancestor_index'],
                ];
                $changed = true;
            }
        }
        return $irrelevant;
    }

    private static function legacyImportHasNameNone(array $import): bool
    {
        foreach (['class_package','class_name','object_name'] as $field) {
            $value = (string)($import[$field] ?? '');
            $key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
            if ($key === 'none') { return true; }
        }
        return false;
    }

    private static function isSourceObjectImport(array $import, string $engineKey): bool
    {
        if ($engineKey === 'UE1' && !empty($import['object_package_present'])) {
            $objectPackage = (string)($import['object_package'] ?? '');
            return $objectPackage !== '' && strcasecmp($objectPackage, 'None') !== 0;
        }
        if (in_array($engineKey, ['UE1', 'UE2', 'UE3', 'UE4'], true)) {
            return (int)($import['outer_index'] ?? 0) !== 0;
        }
        return trim((string)($import['relative_object_path'] ?? '')) !== '';
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

    /** Source-shaped UE1/UE2 PackageIndex traversal; never use derived/trimmed display paths. */
    private static function legacyRootPackageName(array $importsByIndex, int $importIndex): string
    {
        $seen = [];
        while (true) {
            if (isset($seen[$importIndex])) { return ''; }
            $seen[$importIndex] = true;
            $import = $importsByIndex[$importIndex] ?? null;
            if (!is_array($import)) { return ''; }
            if (!empty($import['object_package_present'])) {
                $objectPackage = (string)($import['object_package'] ?? '');
                return strcasecmp($objectPackage, 'None') === 0 ? '' : $objectPackage;
            }
            $outerIndex = (int)($import['outer_index'] ?? $import['package_index'] ?? 0);
            if ($outerIndex === 0) {
                if (strcasecmp((string)($import['class_name'] ?? ''), 'Package') !== 0
                    || strcasecmp((string)($import['class_package'] ?? ''), 'Core') !== 0) {
                    return '';
                }
                return (string)($import['object_name'] ?? '');
            }
            if ($outerIndex > 0) { return ''; }
            $importIndex = -$outerIndex - 1;
        }
    }

    /** Use source-shaped UE4 root when reconstructible; retain the legacy derived root only for mixed/export-outer V4 boundaries. */
    private static function ue4ProviderPackageName(array $ue4RootPackages, array $import, int $importIndex): string
    {
        $raw = (string)($ue4RootPackages[$importIndex] ?? '');
        return $raw !== '' ? $raw : (string)($import['root_package'] ?? '');
    }

    /** UE4 serialized OuterIndex package-root traversal when PackageName is unavailable in UEDB4. */
    private static function ue4RootPackageName(array $importsByIndex, int $importIndex): string
    {
        $seen = [];
        while (true) {
            if (isset($seen[$importIndex])) { return ''; }
            $seen[$importIndex] = true;
            $import = $importsByIndex[$importIndex] ?? null;
            if (!is_array($import)) { return ''; }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex === 0) {
                if (strcasecmp((string)($import['class_name'] ?? ''), 'Package') !== 0) { return ''; }
                return (string)($import['object_name'] ?? '');
            }
            if ($outerIndex > 0) { return ''; }
            $importIndex = -$outerIndex - 1;
        }
    }

    /** @param list<array<string,mixed>> $imports @return array<int,array<string,mixed>> */
    private static function indexRowsForRootLookup(array $imports): array
    {
        $rows=[];
        foreach ($imports as $fallback=>$row) {
            if (!is_array($row)) { continue; }
            $index=isset($row['import_index'])?(int)$row['import_index']:(int)$fallback;
            $rows[$index]=$row;
        }
        return $rows;
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
                if (strcasecmp((string)($import['class_name'] ?? ''), 'Package') !== 0
                    || strcasecmp((string)($import['class_package'] ?? ''), 'Core') !== 0) {
                    return '';
                }
                return (string)($import['object_name'] ?? '');
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
            && strncasecmp((string)($import['root_package'] ?? ''), '/Script/', 8) === 0;
    }

    private static function ue1VerifyImportProfile(int $gameId, int $packageVersion, int $licenseeVersion): ?string
    {
        try {
            $sourceKey = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry::sourceKey($gameId);
        } catch (\Throwable) {
            return null;
        }
        if ($sourceKey === 'ut99' && $packageVersion > 0 && $packageVersion <= 68 && $licenseeVersion === 0) {
            return PdoUe1VerifyImportProjectionResolver::PROFILE_UT99_V1400;
        }
        if ($sourceKey === 'unrealgold' && $packageVersion > 0 && $packageVersion < 60 && $licenseeVersion === 0) {
            return PdoUe1VerifyImportProjectionResolver::PROFILE_UNREAL_V120;
        }
        return null;
    }

    private static function ue2VerifyImportProfile(int $gameId, int $packageVersion, int $licenseeVersion): ?string
    {
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

    private static function filePackageVersion(PDO $db, int $fileId): int
    {
        if ($fileId < 1) {
            return 0;
        }
        $statement = $db->prepare('SELECT package_version FROM ue_files WHERE id=? LIMIT 1');
        $statement->execute([$fileId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    /** @return array{version:int,licensee:int} */
    private static function filePackageIdentity(PDO $db, int $fileId): array
    {
        if ($fileId < 1) { return ['version'=>0,'licensee'=>0]; }
        $statement = $db->prepare('SELECT package_version,licensee_version FROM ue_files WHERE id=? LIMIT 1');
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['version'=>(int)($row['package_version'] ?? 0),'licensee'=>(int)($row['licensee_version'] ?? 0)]
            : ['version'=>0,'licensee'=>0];
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
    private static function loadPackageMatches(PDO $db, int $gameId, int $fileId, array $packageNames, bool $exactFname): array
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
                self::collectPackageMatch($row, $matches, $exactFname);
            }

            $missing = self::missingLookupValues($chunk, $matches, $exactFname);
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
                    self::collectPackageMatch($row, $matches, $exactFname);
                }
            }

            $missing = self::missingLookupValues($missing, $matches, $exactFname);
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
                    self::collectPackageMatch($row, $matches, $exactFname);
                }
            }
        }
        return $matches;
    }

    /** @param list<string> $packageNames @return array<string,list<array{file_id:int,source:string}>> */
    private static function loadPackageCandidates(PDO $db, int $gameId, int $fileId, array $packageNames, bool $exactFname): array
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
                self::collectPackageCandidate($row, $candidates, $exactFname);
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
                self::collectPackageCandidate($row, $candidates, $exactFname);
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
                self::collectPackageCandidate($row, $candidates, $exactFname);
            }
        }
        return $candidates;
    }

    private static function collectPackageCandidate(array $row, array &$candidates, bool $exactFname): void
    {
        $key = self::lookupKey((string)($row['lookup_value'] ?? ''), $exactFname);
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

    private static function collectPackageMatch(array $row, array &$matches, bool $exactFname): void
    {
        $key = self::lookupKey((string)($row['lookup_value'] ?? ''), $exactFname);
        if ($key === '' || isset($matches[$key])) {
            return;
        }
        $matches[$key] = [
            'file_id' => (int)$row['file_id'],
            'source' => (string)($row['source_kind'] ?? '') === 'alias' ? 'exact_package_alias' : 'exact_package',
        ];
    }

    private static function missingLookupValues(array $values, array $matches, bool $exactFname): array
    {
        $missing = [];
        foreach ($values as $value) {
            $value = (string)$value;
            if (!isset($matches[self::lookupKey($value, $exactFname)])) {
                $missing[] = $value;
            }
        }
        return $missing;
    }

    private static function lookupKey(string|int $value, bool $exactFname): string
    {
        return $exactFname ? self::sourceFnameLookup($value) : self::normalizeLookup($value);
    }

    private static function sourceFnameLookup(string|int $value): string
    {
        $value = (string)$value;
        if ($value === '') { return ''; }
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return 'k:' . $normalized;
    }

    private static function normalizeLookup(string|int $value): string
    {
        $value = trim((string)$value);
        if ($value === '') { return ''; }
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
