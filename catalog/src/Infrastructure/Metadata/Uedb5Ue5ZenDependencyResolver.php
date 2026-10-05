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
    public const RESOLVER_POLICY = 'ue5-5.8.3-zen-iostore-dependency-v2';

    /**
     * @param array<string,mixed> $consumer
     * @param list<array{snapshot:array<string,mixed>,provider_id?:int|string}> $selectedProviders
     * @return list<array<string,mixed>>
     */
    public static function resolve(array $consumer, array $selectedProviders, array $runtimeContext = []): array
    {
        self::assertZenSnapshot($consumer, 'consumer');
        $providers = self::providers($selectedProviders);
        $packageStoreContext = self::packageStoreContext($consumer, $runtimeContext);
        $rows = [];

        foreach ((array)$consumer['sections']['imports'] as $index => $import) {
            $result = self::resolveObjectIndex((array)$import, $providers, $packageStoreContext, 'imports', (int)$index, null, false);
            if ($result !== null) { $rows[] = $result; }
        }
        foreach ((array)$consumer['sections']['cell_imports'] as $index => $import) {
            $result = self::resolveObjectIndex((array)$import, $providers, $packageStoreContext, 'cell_imports', (int)$index, null, true);
            if ($result !== null) { $rows[] = $result; }
        }
        foreach ((array)$consumer['sections']['soft_package_references'] as $index => $soft) {
            $soft = (array)$soft;
            $packageId = self::u64((string)($soft['package_id'] ?? ''), 'soft package FPackageId');
            $provider = $providers[self::mapKey($packageId)] ?? null;
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
                $result = self::resolveObjectIndex($object, $providers, $packageStoreContext, 'dependency_bundle_entries', (int)$index, 'load_order', $cellTarget);
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
            if (isset($providers[self::mapKey($packageId)])) {
                throw new RuntimeException('Zen resolver requires exactly one selected physical provider per FPackageId.');
            }
            $exportsByHash = [];
            foreach ((array)$snapshot['sections']['exports'] as $index => $export) {
                $export = (array)$export;
                $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider PublicExportHash');
                if ($hash === '0000000000000000') { continue; }
                $exportsByHash[self::mapKey($hash)][] = ['index' => (int)$index] + $export;
            }
            $cellExportsByHash = [];
            foreach ((array)$snapshot['sections']['cell_exports'] as $index => $export) {
                $export = (array)$export;
                $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider Cell PublicExportHash');
                if ($hash === '0000000000000000') { continue; }
                $cellExportsByHash[self::mapKey($hash)][] = ['index' => (int)$index] + $export;
            }
            $providers[self::mapKey($packageId)] = [
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
        array $packageStoreContext,
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
        $lookupPackageId = $packageId;
        $redirect = $packageStoreContext['redirects'][self::mapKey($packageId)] ?? null;
        $localized = $packageStoreContext['localized'][self::mapKey($packageId)] ?? null;
        $extra = ['provider_lookup_package_id' => $lookupPackageId];

        // FFilePackageStoreBackend::GetPackageRedirectInfo checks explicit package redirects first.
        // They are container/package-store identity, not CoreRedirects and are not gated by
        // s.AllowPackageRedirectorSupport.
        if (is_array($redirect)) {
            $lookupPackageId = self::u64((string)($redirect['target_package_id'] ?? ''), 'PackageRedirect target FPackageId');
            $extra['provider_lookup_package_id'] = $lookupPackageId;
            $extra['package_store_redirect'] = [
                'source_package_id' => $packageId,
                'target_package_id' => $lookupPackageId,
                'source_package_name' => self::mappedNameText($redirect['source_package_name'] ?? null),
                'container_index' => isset($redirect['container_index']) ? (int)$redirect['container_index'] : null,
            ];
        } elseif (is_array($localized) && $packageStoreContext['is_editor'] !== true) {
            // The cooked backend resolves localization only outside the editor, by consulting the
            // active culture and then proving that the resulting localized FPackageId exists in the
            // mounted package store. A static package snapshot has no authoritative active culture.
            $extra['localized_package'] = [
                'source_package_id' => $packageId,
                'source_package_name' => self::mappedNameText($localized['source_package_name'] ?? null),
                'container_index' => isset($localized['container_index']) ? (int)$localized['container_index'] : null,
            ];
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'unresolved', null, null,
                'localized_package_runtime_culture_required', $extra
            );
        }

        $provider = $providers[self::mapKey($lookupPackageId)] ?? null;
        if ($provider === null) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'missing', null, null,
                is_array($redirect) ? 'package_store_redirect_target_missing' : 'package_import_provider_missing',
                $extra
            );
        }

        $hashMap = $cellTarget ? (array)$provider['cell_exports_by_hash'] : (array)$provider['exports_by_hash'];
        $matches = (array)($hashMap[self::mapKey($publicHash)] ?? []);
        if ($matches === []) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'missing', $provider, null,
                is_array($redirect)
                    ? ($cellTarget ? 'package_store_redirect_public_cell_export_hash_missing' : 'package_store_redirect_public_export_hash_missing')
                    : ($cellTarget ? 'public_cell_export_hash_missing' : 'public_export_hash_missing'),
                $extra
            );
        }
        $hasDuplicateHash = count($matches) > 1;
        $match = (array)$matches[0];
        if (!$cellTarget && (int)($match['filter_flags'] ?? 0) !== 0) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'unresolved', $provider, null,
                'export_filter_runtime_state_required', $extra
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
        $reason = $cellTarget
            ? ($hasDuplicateHash ? 'package_id_public_cell_export_hash_first_source_order_match' : 'package_id_public_cell_export_hash_match')
            : ($hasDuplicateHash ? 'package_id_public_export_hash_first_source_order_match' : 'package_id_public_export_hash_match');
        if (is_array($redirect)) {
            $reason = 'package_store_redirect_' . $reason;
        }
        return self::row(
            'PackageImport', $sourceSection, $sourceIndex, $classification,
            $packageId, $publicHash, 'resolved', $provider, $providerObject, $reason, $extra
        );
    }

    /** @return array{redirects:array<string,array<string,mixed>>,localized:array<string,array<string,mixed>>,is_editor:?bool} */
    private static function packageStoreContext(array $consumer, array $runtimeContext): array
    {
        $sections = (array)($consumer['sections'] ?? []);
        $redirectRows = array_key_exists('package_redirects', $runtimeContext)
            ? (array)$runtimeContext['package_redirects']
            : (array)($sections['package_redirects'] ?? []);
        $localizedRows = array_key_exists('localized_packages', $runtimeContext)
            ? (array)$runtimeContext['localized_packages']
            : (array)($sections['localized_packages'] ?? []);

        $redirects = [];
        foreach ($redirectRows as $row) {
            $row = (array)$row;
            $source = self::u64((string)($row['source_package_id'] ?? ''), 'PackageRedirect source FPackageId');
            // FilePackageStore uses FindOrAdd while traversing the effective mounted-container order;
            // the first row for a source FPackageId wins.
            $redirects[self::mapKey($source)] ??= $row;
        }
        $localized = [];
        foreach ($localizedRows as $row) {
            $row = (array)$row;
            $source = self::u64((string)($row['source_package_id'] ?? ''), 'LocalizedPackage source FPackageId');
            $localized[self::mapKey($source)] ??= $row;
        }

        return [
            'redirects' => $redirects,
            'localized' => $localized,
            'is_editor' => array_key_exists('is_editor', $runtimeContext)
                ? (bool)$runtimeContext['is_editor']
                : null,
        ];
    }

    private static function mappedNameText(mixed $value): ?string
    {
        if (!is_array($value)) { return null; }
        $text = $value['text'] ?? null;
        return is_string($text) ? $text : null;
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
        string $reasonCode,
        array $extra = []
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
        ] + $extra;
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

    private static function mapKey(string $u64): string
    {
        // PHP converts numeric-looking string array keys to integers. Prefix every
        // uint64 identity used as an internal map key so fixed-width hex remains exact.
        return 'u64:' . $u64;
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
