<?php
/** Resolves UE1/UE2/UE3/UE4 classic dependencies from source-shaped UEDB5 snapshots only. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

final class Uedb5ClassicDependencyResolver
{
    /**
     * @param list<array{package_name:string,snapshot:array<string,mixed>,provider_id?:int|string}> $selectedProviders
     * @param array{common_packages?:list<string>} $options
     * @return array<int,array<string,mixed>>
     */
    public static function resolve(array $consumerSnapshot, array $selectedProviders, array $options = []): array
    {
        $engine = self::engine($consumerSnapshot);
        if (!in_array($engine, ['ue1', 'ue2', 'ue3', 'ue4'], true)) {
            throw new RuntimeException('Classic UEDB5 resolver received unsupported engine family: ' . $engine . '.');
        }

        $consumer = self::tables($consumerSnapshot);
        if ($engine === 'ue3') {
            $consumer['imports'] = CatalogCompactIdentityEnricher::ue3FixupImportMap($consumer['imports']);
        }

        $providers = [];
        foreach ($selectedProviders as $provider) {
            if (!is_array($provider)) {
                throw new RuntimeException('Classic UEDB5 provider selection contains a non-row value.');
            }
            $lookupPackage = (string)($provider['package_name'] ?? '');
            $snapshot = $provider['snapshot'] ?? null;
            if ($lookupPackage === '' || !is_array($snapshot)) {
                throw new RuntimeException('Classic UEDB5 provider selection requires package_name and snapshot.');
            }
            if (self::engine($snapshot) !== $engine) {
                throw new RuntimeException('Classic UEDB5 provider engine does not match the consumer engine.');
            }
            $key = self::key($lookupPackage);
            if (isset($providers[$key])) {
                throw new RuntimeException('Classic UEDB5 resolver requires one physical provider per package key.');
            }
            $tables = self::tables($snapshot);
            if ($engine === 'ue3') {
                $tables['imports'] = CatalogCompactIdentityEnricher::ue3FixupImportMap($tables['imports']);
            }
            $providers[$key] = [
                'lookup_package' => $lookupPackage,
                'physical_package' => (string)($snapshot['file']['package_name'] ?? $lookupPackage),
                'provider_id' => $provider['provider_id'] ?? null,
                'snapshot' => $snapshot,
                'tables' => $tables,
            ];
        }

        $common = [];
        foreach ((array)($options['common_packages'] ?? []) as $package) {
            $package = trim((string)$package);
            if ($package !== '') {
                $common[self::key($package)] = true;
            }
        }
        $ue2Profile = $engine === 'ue2' ? self::ue2VerifyImportProfile($consumerSnapshot) : null;
        $ue3Profile = $engine === 'ue3' ? self::ue3VerifyImportProfile($consumerSnapshot) : null;
        $ue4Profile = $engine === 'ue4' ? self::ue4VerifyImportProfile($consumerSnapshot) : null;
        $ue1Profile = $engine === 'ue1' ? self::ue1VerifyImportProfile($consumerSnapshot) : null;
        if ($engine === 'ue1'
            && $ue1Profile === PdoUe1VerifyImportProjectionResolver::PROFILE_UT99_V1400) {
            $consumer = self::applyLegacyAllContextNameMap($consumer);
            foreach ($providers as &$provider) {
                $provider['tables'] = self::applyLegacyAllContextNameMap((array)$provider['tables']);
            }
            unset($provider);
        } elseif ($engine === 'ue2') {
            $consumerNameLimit = self::ue2NameMapMaxCharacters($consumerSnapshot);
            if ($consumerNameLimit !== false) {
                $consumer = self::applyLegacyAllContextNameMap($consumer, $consumerNameLimit);
                foreach ($providers as &$provider) {
                    $providerNameLimit = self::ue2NameMapMaxCharacters((array)$provider['snapshot']);
                    if ($providerNameLimit !== false) {
                        $provider['tables'] = self::applyLegacyAllContextNameMap(
                            (array)$provider['tables'],
                            $providerNameLimit
                        );
                    }
                }
                unset($provider);
            }
        }
        $consumerVersion = self::packageVersion($consumerSnapshot);
        $providerOutcomes = [];

        foreach ($providers as $packageKey => $provider) {
            $providerTables = (array)$provider['tables'];
            if ($engine === 'ue1') {
                $providerOutcomes[$packageKey] = [
                    'ue1' => $ue1Profile !== null
                        ? PdoUe1VerifyImportProjectionResolver::resolveInMemoryOutcome(
                            $ue1Profile,
                            array_values($consumer['imports']),
                            array_values($providerTables['imports']),
                            array_values($providerTables['exports']),
                            (string)$provider['physical_package'],
                            $consumerVersion,
                            self::packageVersion((array)$provider['snapshot'])
                        )
                        : [],
                    'redirectors' => [],
                    'redirector_ancestry' => [],
                ];
            } elseif ($engine === 'ue2') {
                if ($ue2Profile !== null) {
                    $providerOutcomes[$packageKey] = [
                        'ue2' => PdoUe2VerifyImportProjectionResolver::resolveInMemoryOutcome(
                            $ue2Profile,
                            array_values($consumer['imports']),
                            array_values($providerTables['imports']),
                            array_values($providerTables['exports']),
                            (string)$provider['physical_package']
                        ),
                        'redirectors' => [],
                        'redirector_ancestry' => [],
                    ];
                } else {
                    $providerOutcomes[$packageKey] = ['redirectors'=>[],'redirector_ancestry'=>[]];
                }
            } elseif ($engine === 'ue3') {
                $providerOutcomes[$packageKey] = [
                    'ue3' => $ue3Profile !== null
                        ? PdoUe3VerifyImportProjectionResolver::resolveInMemoryOutcome(
                            $ue3Profile,
                            array_values($consumer['imports']),
                            array_values($providerTables['imports']),
                            array_values($providerTables['exports']),
                            (string)$provider['physical_package'],
                            self::packageVersion((array)$provider['snapshot']),
                            array_values($consumer['exports'])
                        )
                        : [],
                    'redirectors' => [],
                    'redirector_ancestry' => [],
                ];
            } else {
                $providerOutcomes[$packageKey] = $ue4Profile !== null
                    ? PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
                        array_values($consumer['imports']),
                        array_values($providerTables['imports']),
                        array_values($providerTables['exports']),
                        (string)$provider['physical_package'],
                        array_values($consumer['exports']),
                        array_values($consumer['imports'])
                    )
                    : ['matches'=>[],'redirectors'=>[],'redirector_ancestry'=>[],'source_outcomes'=>[]];
            }
        }

        $resolved = [];
        foreach ($consumer['imports'] as $importIndex => $import) {
            $root = self::rootPackage($consumer['imports'], (int)$importIndex, $engine);
            if ((($engine === 'ue1' && $ue1Profile !== null)
                    || ($engine === 'ue2' && $ue2Profile !== null)
                    || ($engine === 'ue3' && $ue3Profile !== null)
                    || ($engine === 'ue4' && $ue4Profile !== null))
                && self::isSourceIrrelevantNoneImport($import)) {
                $resolved[(int)$importIndex] = self::result(
                    'unresolved', '', null, null, 'source_irrelevant_name_none', $engine, 'runtime_derived'
                );
                continue;
            }
            if ($root !== '' && self::isCommon($root, $engine, $common)) {
                $resolved[(int)$importIndex] = self::result(
                    'common', $root, null, null, 'common_or_script_package', $engine
                );
                continue;
            }

            if ($root === '') {
                $reason = $engine === 'ue3' && self::hasExportOuter($consumer['imports'], (int)$importIndex)
                    ? 'ue3_cooked_export_outer'
                    : 'package_root_unavailable';
                $identityRoot = '';
                if ($engine === 'ue3' && $reason === 'ue3_cooked_export_outer') {
                    $identityPath = CatalogCompactIdentityEnricher::ue3EffectiveImportPath(
                        $consumer['imports'], (int)$importIndex, $consumer['exports']
                    );
                    $identityRoot = (string)($identityPath['root'] ?? '');
                }
                $resolved[(int)$importIndex] = self::result(
                    'unresolved', $identityRoot, null, null, $reason, $engine
                );
                continue;
            }

            $providerKey = self::key($root);
            $provider = $providers[$providerKey] ?? null;
            $isPackageImport = empty($import['object_package_present'])
                && (int)($import['outer_index'] ?? 0) === 0
                && self::key((string)($import['class_name'] ?? '')) === self::key('Package');

            if ($isPackageImport) {
                $resolved[(int)$importIndex] = is_array($provider)
                    ? self::result(
                        'package_only', $root, $provider['provider_id'] ?? null, null,
                        'top_level_package_import', $engine
                    )
                    : self::result('missing', $root, null, null, 'package_provider_unavailable', $engine);
                continue;
            }

            if (!is_array($provider)) {
                $resolved[(int)$importIndex] = self::result(
                    'missing', $root, null, null, 'package_provider_unavailable', $engine
                );
                continue;
            }

            $outcome = (array)($providerOutcomes[$providerKey] ?? []);
            if ($engine === 'ue1') {
                if ($ue1Profile === null) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        'ue1_verify_import_source_implementation_unavailable', $engine, 'runtime_derived'
                    );
                    continue;
                }
                $ue1 = (array)(($outcome['ue1'] ?? [])[(int)$importIndex] ?? []);
                $ue1Status = (string)($ue1['status'] ?? '');
                if ($ue1Status === 'resolved') {
                    $resolved[(int)$importIndex] = self::result(
                        'resolved', $root, $provider['provider_id'] ?? null, (int)($ue1['export_index'] ?? -1),
                        (string)($ue1['reason'] ?? 'verify_import_match'), $engine
                    );
                    continue;
                }
                if ($ue1Status === 'private_export') {
                    $resolved[(int)$importIndex] = self::result(
                        'missing', $root, $provider['provider_id'] ?? null, null,
                        'verify_import_private_export', $engine, 'hard', self::ue1Detail($ue1)
                    );
                    continue;
                }
                if ($ue1Status === 'runtime_only') {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        (string)($ue1['reason'] ?? 'runtime_only_fallback_unavailable'), $engine,
                        'runtime_derived', self::ue1Detail($ue1)
                    );
                    continue;
                }
                if ($ue1Status === 'invalid') {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        (string)($ue1['reason'] ?? 'invalid_import_graph'), $engine, 'runtime_derived', self::ue1Detail($ue1)
                    );
                    continue;
                }
            }

            if ($engine === 'ue2') {
                if ($ue2Profile === null) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        'ue2_verify_import_source_implementation_unavailable', $engine, 'runtime_derived'
                    );
                    continue;
                }
                if ($ue2Profile !== null) {
                    $ue2 = (array)(($outcome['ue2'] ?? [])[(int)$importIndex] ?? []);
                    $ue2Status = (string)($ue2['status'] ?? '');
                    if ($ue2Status === 'resolved') {
                        $resolved[(int)$importIndex] = self::result(
                            'resolved', $root, $provider['provider_id'] ?? null, (int)($ue2['export_index'] ?? -1),
                            (string)($ue2['reason'] ?? 'verify_import_match'), $engine
                        );
                        continue;
                    }
                    if ($ue2Status === 'private_export') {
                        $resolved[(int)$importIndex] = self::result(
                            'missing', $root, $provider['provider_id'] ?? null, null,
                            'verify_import_private_export', $engine, 'hard', self::ue1Detail($ue2)
                        );
                        continue;
                    }
                    if (in_array($ue2Status, ['runtime_only','unresolved','invalid'], true)) {
                        $resolved[(int)$importIndex] = self::result(
                            'unresolved', $root, $provider['provider_id'] ?? null, null,
                            (string)($ue2['reason'] ?? 'runtime_only_fallback_unavailable'), $engine,
                            'runtime_derived', self::ue1Detail($ue2)
                        );
                        continue;
                    }
                }
            }

            if ($engine === 'ue3') {
                if ($ue3Profile === null) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        'ue3_verify_import_source_implementation_unavailable', $engine, 'runtime_derived'
                    );
                    continue;
                }
                $ue3 = (array)(($outcome['ue3'] ?? [])[(int)$importIndex] ?? []);
                $ue3Status = (string)($ue3['status'] ?? '');
                if ($ue3Status === 'resolved') {
                    $resolved[(int)$importIndex] = self::result(
                        'resolved', $root, $provider['provider_id'] ?? null, (int)($ue3['export_index'] ?? -1),
                        (string)($ue3['reason'] ?? 'exact_verify_import_match'), $engine
                    );
                    continue;
                }
                if ($ue3Status === 'private_export') {
                    $resolved[(int)$importIndex] = self::result(
                        'missing', $root, $provider['provider_id'] ?? null, null,
                        (string)($ue3['reason'] ?? 'private_export'), $engine, 'hard', self::ue1Detail($ue3)
                    );
                    continue;
                }
                if (in_array($ue3Status, ['runtime_only','unresolved','invalid','ignored'], true)) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        (string)($ue3['reason'] ?? 'runtime_context_unavailable'), $engine,
                        'runtime_derived', self::ue1Detail($ue3)
                    );
                    continue;
                }
            }

            if ($engine === 'ue4') {
                if ($ue4Profile === null) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, $provider['provider_id'] ?? null, null,
                        'ue4_verify_import_source_implementation_unavailable', $engine, 'runtime_derived'
                    );
                    continue;
                }
                $ue4 = (array)(($outcome['source_outcomes'] ?? [])[(int)$importIndex] ?? []);
                $ue4Status = (string)($ue4['status'] ?? '');
                $ue4Reason = (string)($ue4['reason'] ?? 'runtime_context_unavailable');
                if ($ue4Status === 'resolved') {
                    $resolved[(int)$importIndex] = self::result(
                        'resolved', $root, $provider['provider_id'] ?? null, (int)($ue4['export_index'] ?? -1),
                        $ue4Reason, $engine
                    );
                    continue;
                }
                if ($ue4Status === 'private_export') {
                    $resolved[(int)$importIndex] = self::result(
                        'missing', $root, null, null, $ue4Reason, $engine, 'hard', self::ue1Detail($ue4)
                    );
                    continue;
                }
                if (in_array($ue4Status, ['runtime_only','unresolved','invalid','ignored'], true)) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, null, null, $ue4Reason, $engine,
                        $ue4Status === 'runtime_only' ? 'runtime_derived' : 'source_unresolved', self::ue1Detail($ue4)
                    );
                    continue;
                }
            }

            $exportIndex = $outcome['matches'][(int)$importIndex] ?? null;
            if ($exportIndex !== null) {
                $resolved[(int)$importIndex] = self::result(
                    'resolved', $root, $provider['provider_id'] ?? null, (int)$exportIndex,
                    'verify_import_match', $engine
                );
                continue;
            }

            $resolved[(int)$importIndex] = self::result(
                'missing', $root, null, null, 'verify_import_missing', $engine
            );
        }

        ksort($resolved, SORT_NUMERIC);
        return $resolved;
    }

    /** @return array{names:array<int,array<string,mixed>>,imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} */
    private static function tables(array $snapshot): array
    {
        $sections = (array)($snapshot['sections'] ?? []);
        $names = [];
        foreach ((array)($sections['names'] ?? []) as $fallback => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Classic UEDB5 name section contains a non-row value.');
            }
            $index = array_key_exists('index', $row) ? (int)$row['index'] : (int)$fallback;
            if ($index < 0 || isset($names[$index])) {
                throw new RuntimeException('Classic UEDB5 name section contains an invalid or duplicate index.');
            }
            $names[$index] = [
                'name_index' => $index,
                'name_text' => (string)($row['text'] ?? ''),
                'flags' => $row['flags'] ?? 0,
            ];
        }

        $imports = [];
        foreach ((array)($sections['imports'] ?? []) as $fallback => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Classic UEDB5 import section contains a non-row value.');
            }
            $index = array_key_exists('index', $row) ? (int)$row['index'] : (int)$fallback;
            if ($index < 0 || isset($imports[$index])) {
                throw new RuntimeException('Classic UEDB5 import section contains an invalid or duplicate index.');
            }
            $imports[$index] = [
                'import_index' => $index,
                'class_package' => self::fnameText($row['class_package'] ?? null),
                'class_package_name_index' => self::fnameIndex($row['class_package'] ?? null),
                'class_package_is_none' => self::fnameIsNone($row['class_package'] ?? null),
                'class_name' => self::fnameText($row['class_name'] ?? null),
                'class_name_index' => self::fnameIndex($row['class_name'] ?? null),
                'class_name_is_none' => self::fnameIsNone($row['class_name'] ?? null),
                'object_name' => self::fnameText($row['object_name'] ?? null),
                'object_name_index' => self::fnameIndex($row['object_name'] ?? null),
                'object_name_is_none' => self::fnameIsNone($row['object_name'] ?? null),
                'outer_index' => (int)($row['outer_index'] ?? 0),
                'object_package_present' => !empty($row['object_package_present']),
                'object_package' => self::fnameText($row['object_package'] ?? null),
                'object_package_name_index' => self::fnameIndex($row['object_package'] ?? null),
                'package_name_present' => !empty($row['package_name_present'])
                    || !empty($row['serialized_package_name_present']),
                'package_name' => self::fnameText(
                    $row['package_name'] ?? $row['serialized_package_name'] ?? null
                ),
            ];
        }

        $exports = [];
        foreach ((array)($sections['exports'] ?? []) as $fallback => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Classic UEDB5 export section contains a non-row value.');
            }
            $index = array_key_exists('index', $row) ? (int)$row['index'] : (int)$fallback;
            if ($index < 0 || isset($exports[$index])) {
                throw new RuntimeException('Classic UEDB5 export section contains an invalid or duplicate index.');
            }
            $exports[$index] = [
                'export_index' => $index,
                'class_index' => (int)($row['class_index'] ?? 0),
                'super_index' => (int)($row['super_index'] ?? 0),
                'outer_index' => (int)($row['outer_index'] ?? 0),
                'archetype_index' => (int)($row['archetype_index'] ?? 0),
                'object_name' => self::fnameText($row['object_name'] ?? null),
                'object_name_index' => self::fnameIndex($row['object_name'] ?? null),
                'object_flags' => self::flagsInt($row['object_flags'] ?? 0),
            ];
        }
        ksort($names, SORT_NUMERIC);
        ksort($imports, SORT_NUMERIC);
        ksort($exports, SORT_NUMERIC);
        return ['names' => $names, 'imports' => $imports, 'exports' => $exports];
    }

    /** @param array{names:array<int,array<string,mixed>>,imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} $tables */
    private static function applyLegacyAllContextNameMap(array $tables, ?int $maxCharacters = null): array
    {
        // Synthetic/unit snapshots may already contain effective FName text and
        // omit the serialized name section. Real UEDB5 snapshots always retain it.
        if ($tables['names'] === []) {
            return $tables;
        }
        $effective = CatalogLegacyNameMapPreprocessor::effectiveNameMap(
            array_values($tables['names']),
            CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS,
            $maxCharacters
        );
        foreach ($tables['imports'] as &$import) {
            foreach ([
                ['class_package','class_package_name_index','class_package_is_none'],
                ['class_name','class_name_index','class_name_is_none'],
                ['object_name','object_name_index','object_name_is_none'],
                ['object_package','object_package_name_index',null],
            ] as [$textKey,$indexKey,$noneKey]) {
                if (!array_key_exists($indexKey, $import) || $import[$indexKey] === null) {
                    continue;
                }
                $text = CatalogLegacyNameMapPreprocessor::effectiveText(
                    $effective,
                    (int)$import[$indexKey],
                    (string)($import[$textKey] ?? '')
                );
                $import[$textKey] = $text;
                if ($noneKey !== null) {
                    $import[$noneKey] = self::key($text) === self::key('None');
                }
            }
        }
        unset($import);
        foreach ($tables['exports'] as &$export) {
            if (($export['object_name_index'] ?? null) === null) {
                continue;
            }
            $export['object_name'] = CatalogLegacyNameMapPreprocessor::effectiveText(
                $effective,
                (int)$export['object_name_index'],
                (string)($export['object_name'] ?? '')
            );
        }
        unset($export);
        return $tables;
    }

    private static function ue2NameMapMaxCharacters(array $snapshot): int|false|null
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        $version = self::packageVersion($snapshot);
        if (str_starts_with($schema, 'ue2.unreal2.')
            && $version !== null && $version >= 60 && $version <= 126) {
            return $version >= 70 ? 63 : null;
        }
        if (str_starts_with($schema, 'ue2.ut2003.')
            && $policy === strtolower(Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY)
            && $version !== null && $version >= 60 && $version <= 120) {
            return null;
        }
        if (str_starts_with($schema, 'ue2.ut2004.')
            && $version !== null && $version >= 60 && $version <= 129
            && in_array($policy, [
                strtolower(Uedb5Ut2004SnapshotBuilder::POLICY_V128),
                strtolower(Uedb5Ut2004SnapshotBuilder::POLICY_V129),
            ], true)) {
            return $version >= 64 ? 63 : null;
        }
        return false;
    }

    private static function engine(array $snapshot): string
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        foreach (['ue1', 'ue2', 'ue3', 'ue4', 'ue5'] as $engine) {
            if (str_starts_with($schema, $engine . '.')) {
                return $engine;
            }
        }
        throw new RuntimeException('Classic UEDB5 snapshot has no recognized engine import schema.');
    }

    private static function ue1VerifyImportProfile(array $snapshot): ?string
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        if (str_starts_with($schema, 'ue1.unreal.')
            && $policy === strtolower(Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY)) {
            return PdoUe1VerifyImportProjectionResolver::PROFILE_UNREAL_V120;
        }
        if (str_starts_with($schema, 'ue1.ut99.')
            && $policy === strtolower(Uedb5Ut99SnapshotBuilder::POLICY_RETAIL)) {
            return PdoUe1VerifyImportProjectionResolver::PROFILE_UT99_V1400;
        }
        return null;
    }

    private static function ue3VerifyImportProfile(array $snapshot): ?string
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        $version = self::packageVersion($snapshot);
        $licensee = self::licenseeVersion($snapshot);
        if (str_starts_with($schema, 'ue3.ut3.')
            && $policy === strtolower(Uedb5Ut3SnapshotBuilder::SOURCE_POLICY)
            && $version === 512
            && $licensee === 0) {
            return PdoUe3VerifyImportProjectionResolver::PROFILE_UT3_V512;
        }
        return null;
    }

    private static function ue4VerifyImportProfile(array $snapshot): ?string
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        return str_starts_with($schema, 'ue4.ut4.')
            && $policy === strtolower(Uedb5Ut4SnapshotBuilder::SOURCE_POLICY)
                ? PdoUe4VerifyImportProjectionResolver::PROFILE_UT4_4272
                : null;
    }

    private static function ue2VerifyImportProfile(array $snapshot): ?string
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        if (str_starts_with($schema, 'ue2.unreal2.')
            && $policy === strtolower(Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000)) {
            return PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000;
        }
        if (str_starts_with($schema, 'ue2.ut2003.')
            && $policy === strtolower(Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY)) {
            return PdoUe2VerifyImportProjectionResolver::PROFILE_UT2003_V2107;
        }
        $version = self::packageVersion($snapshot);
        if (str_starts_with($schema, 'ue2.ut2004.')
            && $version >= 60 && $version <= 129
            && in_array($policy, [
                strtolower(Uedb5Ut2004SnapshotBuilder::POLICY_V128),
                strtolower(Uedb5Ut2004SnapshotBuilder::POLICY_V129),
            ], true)) {
            return PdoUe2VerifyImportProjectionResolver::PROFILE_UT2004_V129;
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $imports */
    private static function rootPackage(array $imports, int $importIndex, string $engine): string
    {
        $seen = [];
        while (isset($imports[$importIndex]) && !isset($seen[$importIndex])) {
            $seen[$importIndex] = true;
            $row = $imports[$importIndex];
            if ($engine === 'ue1' && !empty($row['object_package_present'])) {
                $objectPackage = (string)($row['object_package'] ?? '');
                return self::key($objectPackage) === self::key('None') ? '' : $objectPackage;
            }
            if ($engine === 'ue4' && !empty($row['package_name_present'])) {
                $explicit = (string)($row['package_name'] ?? '');
                if ($explicit !== '') {
                    return $explicit;
                }
            }
            $outer = (int)($row['outer_index'] ?? 0);
            if ($outer === 0) {
                return self::key((string)($row['class_name'] ?? '')) === self::key('Package')
                    ? (string)($row['object_name'] ?? '')
                    : '';
            }
            if ($outer > 0) {
                return '';
            }
            $importIndex = -$outer - 1;
        }
        return '';
    }

    /** @param array<int,array<string,mixed>> $imports */
    private static function hasExportOuter(array $imports, int $importIndex): bool
    {
        $seen = [];
        while (isset($imports[$importIndex]) && !isset($seen[$importIndex])) {
            $seen[$importIndex] = true;
            $outer = (int)($imports[$importIndex]['outer_index'] ?? 0);
            if ($outer > 0) {
                return true;
            }
            if ($outer === 0) {
                return false;
            }
            $importIndex = -$outer - 1;
        }
        return false;
    }

    /** @param array<string,mixed> $import */
    private static function isSourceIrrelevantNoneImport(array $import): bool
    {
        foreach (['class_package','class_name','object_name'] as $field) {
            if (!empty($import[$field . '_is_none'])) {
                return true;
            }
            if (!array_key_exists($field . '_is_none', $import)
                && self::key((string)($import[$field] ?? '')) === self::key('None')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $imports */
    private static function sourceIrrelevantNoneAncestor(array $imports, int $importIndex): ?int
    {
        $seen = [];
        while (isset($imports[$importIndex]) && !isset($seen[$importIndex])) {
            $seen[$importIndex] = true;
            $outer = (int)($imports[$importIndex]['outer_index'] ?? 0);
            if ($outer >= 0) {
                return null;
            }
            $importIndex = -$outer - 1;
            $parent = $imports[$importIndex] ?? null;
            if (!is_array($parent)) {
                return null;
            }
            if (self::isSourceIrrelevantNoneImport($parent)) {
                return $importIndex;
            }
        }
        return null;
    }

    /** @param array<string,true> $common */
    private static function isCommon(string $root, string $engine, array $common): bool
    {
        if (isset($common[self::key($root)])) {
            return true;
        }
        return $engine === 'ue4' && strncasecmp($root, '/Script/', 8) === 0;
    }

    private static function packageVersion(array $snapshot): ?int
    {
        $summary = (array)($snapshot['sections']['summary'][0] ?? []);
        return array_key_exists('package_version', $summary) ? (int)$summary['package_version'] : null;
    }

    private static function licenseeVersion(array $snapshot): int
    {
        $summary = (array)($snapshot['sections']['summary'][0] ?? []);
        return (int)($summary['licensee_version'] ?? 0);
    }

    /** @return array<string,mixed> */
    private static function result(
        string $status,
        string $providerPackage,
        int|string|null $providerId,
        ?int $exportIndex,
        string $reason,
        string $engine,
        ?string $dependencyClass = null,
        array $detail = []
    ): array {
        return array_merge([
            'status' => $status,
            'provider_package' => $providerPackage,
            'provider_id' => $providerId,
            'export_index' => $exportIndex,
            'reason' => $reason,
            'resolver_policy' => match ($engine) {
                'ue1', 'ue2' => 'legacy-verify-import-source-v1',
                'ue3' => 'ue3-verify-import-inner-source-v1',
                'ue4' => 'ue4-verify-import-inner-source-v1',
                default => 'classic-source-v1',
            },
            'dependency_class' => $dependencyClass ?? (
                $status === 'common' && strncasecmp($providerPackage, '/Script/', 8) === 0
                    ? 'script'
                    : 'hard'
            ),
        ], $detail);
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private static function ue1Detail(array $detail): array
    {
        if (array_key_exists('status', $detail)) {
            $detail['source_status'] = (string)$detail['status'];
            unset($detail['status']);
        }
        if (array_key_exists('reason', $detail)) {
            $detail['source_reason'] = (string)$detail['reason'];
            unset($detail['reason']);
        }
        return $detail;
    }

    private static function fnameIndex(mixed $value): ?int
    {
        if (!is_array($value)) {
            return null;
        }
        $index = $value['name_index'] ?? $value['index'] ?? null;
        return $index === null || $index === '' ? null : (int)$index;
    }

    private static function fnameIsNone(mixed $value): bool
    {
        if (!is_array($value)) {
            return self::key((string)$value) === self::key('None');
        }
        return (int)($value['number'] ?? 0) === 0
            && self::key((string)($value['text'] ?? '')) === self::key('None');
    }

    private static function fnameText(mixed $value): string
    {
        // FName text is identity data. Epic compares FNames; it does not trim
        // their serialized text. A literal whitespace FName is therefore not
        // equivalent to NAME_None/an empty name.
        return is_array($value) ? (string)($value['text'] ?? '') : (string)$value;
    }

    private static function flagsInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        $hex = strtoupper(trim((string)$value));
        if ($hex === '') {
            return 0;
        }
        if (preg_match('/^[0-9A-F]{1,16}$/', $hex) !== 1) {
            throw new RuntimeException('Classic UEDB5 ObjectFlags is not canonical hexadecimal data.');
        }
        $hex = str_pad($hex, 16, '0', STR_PAD_LEFT);
        $high = (int)hexdec(substr($hex, 0, 8));
        $low = (int)hexdec(substr($hex, 8, 8));
        return ($high << 32) | $low;
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
