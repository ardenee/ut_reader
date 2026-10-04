<?php
/** Resolves UE1/UE2/UE3/UE4 classic dependencies from source-shaped UEDB5 snapshots only. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoLegacyVerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

final class Uedb5ClassicDependencyResolver
{
    /**
     * @param list<array{package_name:string,snapshot:array<string,mixed>,provider_id?:int|string}> $selectedProviders
     * @param array{common_packages?:list<string>,class_remaps?:array<string,string>} $options
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
            $lookupPackage = trim((string)($provider['package_name'] ?? ''));
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
                'physical_package' => trim((string)($snapshot['file']['package_name'] ?? $lookupPackage)),
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
        $classRemaps = (array)($options['class_remaps'] ?? []);
        $legacyPolicy = self::legacyPolicy($consumerSnapshot);
        $providerOutcomes = [];

        foreach ($providers as $packageKey => $provider) {
            $providerTables = (array)$provider['tables'];
            if ($engine === 'ue1' || $engine === 'ue2') {
                $variants = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
                    array_values($consumer['imports']),
                    array_values($providerTables['imports']),
                    array_values($providerTables['exports']),
                    (string)$provider['physical_package'],
                    $classRemaps
                );
                $providerOutcomes[$packageKey] = [
                    'matches' => (array)($variants[$legacyPolicy] ?? []),
                    'redirectors' => [],
                    'redirector_ancestry' => [],
                ];
            } elseif ($engine === 'ue3') {
                $providerOutcomes[$packageKey] = [
                    'matches' => PdoUe3VerifyImportProjectionResolver::resolveInMemory(
                        array_values($consumer['imports']),
                        array_values($providerTables['imports']),
                        array_values($providerTables['exports']),
                        (string)$provider['physical_package'],
                        self::packageVersion((array)$provider['snapshot']),
                        true
                    ),
                    'redirectors' => [],
                    'redirector_ancestry' => [],
                ];
            } else {
                $providerOutcomes[$packageKey] = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
                    array_values($consumer['imports']),
                    array_values($providerTables['imports']),
                    array_values($providerTables['exports']),
                    (string)$provider['physical_package'],
                    array_values($consumer['exports']),
                    array_values($consumer['imports'])
                );
            }
        }

        $resolved = [];
        foreach ($consumer['imports'] as $importIndex => $import) {
            $root = self::rootPackage($consumer['imports'], (int)$importIndex, $engine);
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
                    $identityRoot = trim((string)($identityPath['root'] ?? ''));
                }
                $resolved[(int)$importIndex] = self::result(
                    'unresolved', $identityRoot, null, null, $reason, $engine
                );
                continue;
            }

            $providerKey = self::key($root);
            $provider = $providers[$providerKey] ?? null;
            $isPackageImport = (int)($import['outer_index'] ?? 0) === 0
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
            $exportIndex = $outcome['matches'][(int)$importIndex] ?? null;
            if ($exportIndex !== null) {
                $resolved[(int)$importIndex] = self::result(
                    'resolved', $root, $provider['provider_id'] ?? null, (int)$exportIndex,
                    'verify_import_match', $engine
                );
                continue;
            }

            if ($engine === 'ue4') {
                if (array_key_exists((int)$importIndex, (array)($outcome['redirectors'] ?? []))) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, null, null, 'object_redirector_target_unavailable', $engine
                    );
                    continue;
                }
                if (array_key_exists((int)$importIndex, (array)($outcome['redirector_ancestry'] ?? []))) {
                    $resolved[(int)$importIndex] = self::result(
                        'unresolved', $root, null, null, 'object_redirector_ancestor_target_unavailable', $engine
                    );
                    continue;
                }
            }

            $resolved[(int)$importIndex] = self::result(
                'missing', $root, null, null, 'verify_import_missing', $engine
            );
        }

        ksort($resolved, SORT_NUMERIC);
        return $resolved;
    }

    /** @return array{imports:array<int,array<string,mixed>>,exports:array<int,array<string,mixed>>} */
    private static function tables(array $snapshot): array
    {
        $sections = (array)($snapshot['sections'] ?? []);
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
                'class_name' => self::fnameText($row['class_name'] ?? null),
                'object_name' => self::fnameText($row['object_name'] ?? null),
                'outer_index' => (int)($row['outer_index'] ?? 0),
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
                'object_name' => self::fnameText($row['object_name'] ?? null),
                'object_flags' => self::flagsInt($row['object_flags'] ?? 0),
            ];
        }
        ksort($imports, SORT_NUMERIC);
        ksort($exports, SORT_NUMERIC);
        return ['imports' => $imports, 'exports' => $exports];
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

    private static function legacyPolicy(array $snapshot): string
    {
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        return str_starts_with($schema, 'ue2.unreal2.') ? 'unreal2' : 'standard';
    }

    /** @param array<int,array<string,mixed>> $imports */
    private static function rootPackage(array $imports, int $importIndex, string $engine): string
    {
        $seen = [];
        while (isset($imports[$importIndex]) && !isset($seen[$importIndex])) {
            $seen[$importIndex] = true;
            $row = $imports[$importIndex];
            if ($engine === 'ue4' && !empty($row['package_name_present'])) {
                $explicit = trim((string)($row['package_name'] ?? ''));
                if ($explicit !== '') {
                    return $explicit;
                }
            }
            $outer = (int)($row['outer_index'] ?? 0);
            if ($outer === 0) {
                return self::key((string)($row['class_name'] ?? '')) === self::key('Package')
                    ? trim((string)($row['object_name'] ?? ''))
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

    /** @return array<string,mixed> */
    private static function result(
        string $status,
        string $providerPackage,
        int|string|null $providerId,
        ?int $exportIndex,
        string $reason,
        string $engine
    ): array {
        return [
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
            'dependency_class' => $status === 'common' && strncasecmp($providerPackage, '/Script/', 8) === 0
                ? 'script'
                : 'hard',
        ];
    }

    private static function fnameText(mixed $value): string
    {
        return is_array($value) ? trim((string)($value['text'] ?? '')) : trim((string)$value);
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
        return CatalogUnrealIdentityHash::nameKey(trim($value));
    }
}
