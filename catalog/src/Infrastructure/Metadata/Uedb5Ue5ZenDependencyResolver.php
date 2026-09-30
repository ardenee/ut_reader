<?php
/**
 * Resolves deterministic UE5 5.8.3 Zen dependencies from source-shaped UEDB5 staging snapshots.
 * PackageImport identity is FPackageId + PublicExportHash; package names are never a fallback key.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5Ue5ZenDependencyResolver
{
    public const RESOLVER_POLICY = 'ue5-5.8.3-zen-iostore-dependency-v1';

    /**
     * @param array<string,mixed> $consumer
     * @param list<array{snapshot:array<string,mixed>,provider_id?:int|string}> $selectedProviders
     * @return list<array<string,mixed>>
     */
    public static function resolve(array $consumer, array $selectedProviders): array
    {
        self::assertZenSnapshot($consumer, 'consumer');
        $providers = self::providers($selectedProviders);
        $rows = [];

        foreach ((array)$consumer['sections']['imports'] as $index => $import) {
            $result = self::resolveObjectIndex((array)$import, $providers, 'imports', (int)$index, null, false);
            if ($result !== null) { $rows[] = $result; }
        }
        foreach ((array)$consumer['sections']['cell_imports'] as $index => $import) {
            $result = self::resolveObjectIndex((array)$import, $providers, 'cell_imports', (int)$index, null, true);
            if ($result !== null) { $rows[] = $result; }
        }
        foreach ((array)$consumer['sections']['soft_package_references'] as $index => $soft) {
            $soft = (array)$soft;
            $packageId = self::u64((string)($soft['package_id'] ?? ''), 'soft package FPackageId');
            $provider = $providers[$packageId] ?? null;
            $rows[] = self::row(
                'SoftPackageReference',
                'soft_package_references',
                (int)$index,
                'soft',
                $packageId,
                null,
                $provider === null ? 'missing' : 'package_only',
                $provider,
                null,
                $provider === null ? 'soft_package_provider_missing' : 'soft_package_provider_known'
            );
        }

        foreach ((array)$consumer['sections']['dependency_bundle_entries'] as $index => $edge) {
            $edge = (array)$edge;
            $kind = (string)($edge['kind'] ?? '');
            if ($kind === 'Export') {
                $rows[] = self::row(
                    'DependencyBundle', 'dependency_bundle_entries', (int)$index, 'load_order',
                    null, null, 'resolved', null,
                    ['local_export_index' => (int)($edge['local_export_index'] ?? -1)],
                    'local_export_load_order_edge'
                );
                continue;
            }
            if ($kind === 'Import') {
                $combinedIndex = (int)($edge['combined_import_index'] ?? -1);
                [$object, $cellTarget] = self::combinedImport($consumer, $combinedIndex);
                $edgeObject = (array)($edge['object_index'] ?? []);
                if ($edgeObject !== [] && $edgeObject !== $object) {
                    throw new RuntimeException('Zen dependency bundle object_index does not match the combined import map.');
                }
                $result = self::resolveObjectIndex($object, $providers, 'dependency_bundle_entries', (int)$index, 'load_order', $cellTarget);
                if ($result !== null) { $rows[] = $result; }
            }
        }
        return $rows;
    }
    /** @return array<string,array<string,mixed>> */
    private static function providers(array $selectedProviders): array
    {
        $providers = [];
        foreach ($selectedProviders as $selected) {
            if (!is_array($selected) || !is_array($selected['snapshot'] ?? null)) {
                throw new RuntimeException('Zen provider selection requires a snapshot row.');
            }
            $snapshot = (array)$selected['snapshot'];
            self::assertZenSnapshot($snapshot, 'provider');
            $summary = (array)(($snapshot['sections']['package_summary'] ?? [])[0] ?? []);
            $packageId = self::u64((string)($summary['package_id'] ?? ''), 'provider FPackageId');
            if (isset($providers[$packageId])) {
                throw new RuntimeException('Zen resolver requires exactly one selected physical provider per FPackageId.');
            }
            $exportsByHash = [];
            foreach ((array)$snapshot['sections']['exports'] as $index => $export) {
                $export = (array)$export;
                $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider PublicExportHash');
                if ($hash === '0000000000000000') { continue; }
                $exportsByHash[$hash][] = ['index' => (int)$index] + $export;
            }
            $cellExportsByHash = [];
            foreach ((array)$snapshot['sections']['cell_exports'] as $index => $export) {
                $export = (array)$export;
                $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider Cell PublicExportHash');
                if ($hash === '0000000000000000') { continue; }
                $cellExportsByHash[$hash][] = ['index' => (int)$index] + $export;
            }
            $providers[$packageId] = [
                'package_id' => $packageId,
                'provider_id' => $selected['provider_id'] ?? null,
                'snapshot' => $snapshot,
                'exports_by_hash' => $exportsByHash,
                'cell_exports_by_hash' => $cellExportsByHash,
            ];
        }
        return $providers;
    }

    /** @return array<string,mixed>|null */
    private static function resolveObjectIndex(
        array $object,
        array $providers,
        string $sourceSection,
        int $sourceIndex,
        ?string $classificationOverride = null,
        bool $cellTarget = false
    ): ?array {
        $type = (string)($object['type'] ?? '');
        $classification = $classificationOverride ?? (string)($object['dependency_class'] ?? 'runtime_derived');
        if ($type === 'ScriptImport') {
            return self::row(
                'ScriptImport', $sourceSection, $sourceIndex, $classification,
                null, (string)($object['script_import_hash'] ?? ''), 'unresolved', null, null,
                'script_object_runtime_context_unavailable'
            );
        }
        if ($type !== 'PackageImport') {
            return null;
        }

        $packageId = self::u64((string)($object['provider_package_id'] ?? ''), 'PackageImport provider FPackageId');
        $publicHash = self::u64((string)($object['provider_public_export_hash'] ?? ''), 'PackageImport PublicExportHash');
        $provider = $providers[$packageId] ?? null;
        if ($provider === null) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'missing', null, null, 'package_import_provider_missing'
            );
        }

        $hashMap = $cellTarget ? (array)$provider['cell_exports_by_hash'] : (array)$provider['exports_by_hash'];
        $matches = (array)($hashMap[$publicHash] ?? []);
        if ($matches === []) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'missing', $provider, null,
                $cellTarget ? 'public_cell_export_hash_missing' : 'public_export_hash_missing'
            );
        }
        $hasDuplicateHash = count($matches) > 1;
        $match = (array)$matches[0];
        if (!$cellTarget && (int)($match['filter_flags'] ?? 0) !== 0) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'unresolved', $provider, null,
                'export_filter_runtime_state_required'
            );
        }
        $providerObject = [
            $cellTarget ? 'cell_export_index' : 'export_index' => (int)$match['index'],
            'public_export_hash' => $publicHash,
        ];
        if (!$cellTarget) {
            $providerObject['object_name'] = $match['object_name'] ?? null;
        } else {
            $providerObject['cpp_class_info'] = $match['cpp_class_info'] ?? null;
        }
        return self::row(
            'PackageImport', $sourceSection, $sourceIndex, $classification,
            $packageId, $publicHash, 'resolved', $provider, $providerObject,
            $cellTarget
                ? ($hasDuplicateHash ? 'package_id_public_cell_export_hash_first_source_order_match' : 'package_id_public_cell_export_hash_match')
                : ($hasDuplicateHash ? 'package_id_public_export_hash_first_source_order_match' : 'package_id_public_export_hash_match')
        );
    }

    /** @return array{0:array<string,mixed>,1:bool} */
    private static function combinedImport(array $snapshot, int $combinedIndex): array
    {
        if ($combinedIndex < 0) {
            throw new RuntimeException('Zen dependency bundle import index is negative.');
        }
        $imports = array_values((array)$snapshot['sections']['imports']);
        if (isset($imports[$combinedIndex])) {
            return [(array)$imports[$combinedIndex], false];
        }
        $cellIndex = $combinedIndex - count($imports);
        $cellImports = array_values((array)$snapshot['sections']['cell_imports']);
        if (!isset($cellImports[$cellIndex])) {
            throw new RuntimeException('Zen dependency bundle import index is outside ordinary/cell import maps.');
        }
        return [(array)$cellImports[$cellIndex], true];
    }
    /** @return array<string,mixed> */
    private static function row(
        string $kind,
        string $sourceSection,
        int $sourceIndex,
        string $classification,
        ?string $requiredPackageId,
        ?string $requiredObjectIdentity,
        string $outcome,
        ?array $provider,
        ?array $providerObject,
        string $reasonCode
    ): array {
        $hard = in_array($classification, ['hard', 'cell_verse'], true);
        return [
            'dependency_kind' => $kind,
            'source_section' => $sourceSection,
            'source_index' => $sourceIndex,
            'required_package_id' => $requiredPackageId,
            'required_object_identity' => $requiredObjectIdentity,
            'dependency_class' => $classification,
            'hard' => $hard,
            'outcome' => $outcome,
            'selected_provider_file_id' => $provider['provider_id'] ?? null,
            'selected_provider_package_id' => $provider['package_id'] ?? null,
            'selected_provider_object' => $providerObject,
            'resolver_policy' => self::RESOLVER_POLICY,
            'source_policy' => Uedb5ZenPackageReader::SOURCE_POLICY,
            'reason_code' => $reasonCode,
        ];
    }

    private static function assertZenSnapshot(array $snapshot, string $label): void
    {
        if (($snapshot['package_family'] ?? null) !== Uedb5ZenPackageReader::PACKAGE_FAMILY) {
            throw new RuntimeException('Zen resolver ' . $label . ' snapshot has the wrong package_family.');
        }
        if (($snapshot['source_policy'] ?? null) !== Uedb5ZenPackageReader::SOURCE_POLICY) {
            throw new RuntimeException('Zen resolver ' . $label . ' snapshot has the wrong source_policy.');
        }
        $sections = $snapshot['sections'] ?? null;
        if (!is_array($sections)) {
            throw new RuntimeException('Zen resolver ' . $label . ' snapshot has no sections.');
        }
        foreach (['package_summary', 'imports', 'exports', 'cell_imports', 'cell_exports', 'soft_package_references', 'dependency_bundle_entries'] as $section) {
            if (!isset($sections[$section]) || !is_array($sections[$section])) {
                throw new RuntimeException('Zen resolver ' . $label . ' snapshot is missing section ' . $section . '.');
            }
        }
    }

    private static function u64(string $value, string $label): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^[0-9A-F]{16}$/', $value) !== 1) {
            throw new RuntimeException($label . ' must be fixed-width 16-digit hexadecimal.');
        }
        return $value;
    }
}
