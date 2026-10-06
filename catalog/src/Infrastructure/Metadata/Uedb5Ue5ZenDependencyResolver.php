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
    public const RESOLVER_POLICY = 'ue5-5.8.3-zen-iostore-dependency-v3';
    private const OBJECT_REDIRECTOR_SCRIPT_IMPORT_HASH = '1D39669A89BAECB6';
    private const FILTER_NOT_FOR_CLIENT = 1;
    private const FILTER_NOT_FOR_SERVER = 2;

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
        $optional = self::optionalSegment($consumer);
        if ($optional !== null) {
            foreach ((array)($optional['imports'] ?? []) as $index => $import) {
                $result = self::resolveObjectIndex((array)$import, $providers, $packageStoreContext, 'optional_segment_imports', (int)$index, null, false);
                if ($result !== null) { $rows[] = $result; }
            }
            foreach ((array)($optional['cell_imports'] ?? []) as $index => $import) {
                $result = self::resolveObjectIndex((array)$import, $providers, $packageStoreContext, 'optional_segment_cell_imports', (int)$index, null, true);
                if ($result !== null) { $rows[] = $result; }
            }
            foreach ((array)($optional['soft_package_references'] ?? []) as $index => $soft) {
                $soft = (array)$soft;
                $packageId = self::u64((string)($soft['package_id'] ?? ''), 'optional soft package FPackageId');
                if ($packageStoreContext['with_editor'] !== true) {
                    $rows[] = self::row(
                        'SoftPackageReference', 'optional_segment_soft_package_references', (int)$index, 'optional',
                        $packageId, null, 'unresolved', null, null,
                        $packageStoreContext['with_editor'] === false
                            ? 'optional_segment_not_loaded_non_editor'
                            : 'optional_segment_editor_build_context_required'
                    );
                } else {
                    $provider = $providers[self::mapKey($packageId)] ?? null;
                    $rows[] = self::row(
                        'SoftPackageReference', 'optional_segment_soft_package_references', (int)$index, 'optional',
                        $packageId, null, $provider === null ? 'missing' : 'package_only', $provider, null,
                        $provider === null ? 'soft_package_provider_missing' : 'soft_package_provider_known'
                    );
                }
            }
            foreach ((array)($optional['dependency_bundle_entries'] ?? []) as $index => $edge) {
                $edge = (array)$edge;
                $kind = (string)($edge['kind'] ?? '');
                if ($packageStoreContext['with_editor'] !== true) {
                    $rows[] = self::row(
                        'DependencyBundle', 'optional_segment_dependency_bundle_entries', (int)$index, 'optional',
                        null, null, 'unresolved', null, null,
                        $packageStoreContext['with_editor'] === false
                            ? 'optional_segment_not_loaded_non_editor'
                            : 'optional_segment_editor_build_context_required'
                    );
                    continue;
                }
                if ($kind === 'Export') {
                    $rows[] = self::row(
                        'DependencyBundle', 'optional_segment_dependency_bundle_entries', (int)$index, 'optional',
                        null, null, 'resolved', null,
                        ['local_export_index' => (int)($edge['local_export_index'] ?? -1)],
                        'local_export_load_order_edge'
                    );
                    continue;
                }
                if ($kind === 'Import') {
                    $combinedIndex = (int)($edge['combined_import_index'] ?? -1);
                    [$object, $cellTarget] = self::combinedImportSections($optional, $combinedIndex);
                    $edgeObject = (array)($edge['object_index'] ?? []);
                    if ($edgeObject !== [] && $edgeObject !== $object) {
                        throw new RuntimeException('Zen optional-segment dependency bundle object_index does not match the combined import map.');
                    }
                    $result = self::resolveObjectIndex($object, $providers, $packageStoreContext, 'optional_segment_dependency_bundle_entries', (int)$index, 'optional', $cellTarget);
                    if ($result !== null) { $rows[] = $result; }
                }
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
                $exportsByHash[self::mapKey($hash)][] = ['index' => (int)$index, 'segment' => 'main'] + $export;
            }
            $optionalProvider = self::optionalSegment($snapshot);
            if ($optionalProvider !== null) {
                foreach ((array)($optionalProvider['exports'] ?? []) as $index => $export) {
                    $export = (array)$export;
                    $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider optional-segment PublicExportHash');
                    if ($hash === '0000000000000000') { continue; }
                    $exportsByHash[self::mapKey($hash)][] = ['index' => (int)$index, 'segment' => 'optional'] + $export;
                }
            }
            $cellExportsByHash = [];
            foreach ((array)$snapshot['sections']['cell_exports'] as $index => $export) {
                $export = (array)$export;
                $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider Cell PublicExportHash');
                if ($hash === '0000000000000000') { continue; }
                $cellExportsByHash[self::mapKey($hash)][] = ['index' => (int)$index, 'segment' => 'main'] + $export;
            }
            if ($optionalProvider !== null) {
                foreach ((array)($optionalProvider['cell_exports'] ?? []) as $index => $export) {
                    $export = (array)$export;
                    $hash = self::u64((string)($export['public_export_hash'] ?? ''), 'provider optional-segment Cell PublicExportHash');
                    if ($hash === '0000000000000000') { continue; }
                    $cellExportsByHash[self::mapKey($hash)][] = ['index' => (int)$index, 'segment' => 'optional'] + $export;
                }
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
        if (str_starts_with($sourceSection, 'optional_segment_') && $packageStoreContext['with_editor'] !== true) {
            $requiredPackageId = $type === 'PackageImport'
                ? self::u64((string)($object['provider_package_id'] ?? ''), 'optional PackageImport provider FPackageId')
                : null;
            $requiredObject = $type === 'PackageImport'
                ? self::u64((string)($object['provider_public_export_hash'] ?? ''), 'optional PackageImport PublicExportHash')
                : (string)($object['script_import_hash'] ?? '');
            return self::row(
                $type === 'ScriptImport' ? 'ScriptImport' : 'PackageImport',
                $sourceSection, $sourceIndex, $classification,
                $requiredPackageId, $requiredObject, 'unresolved', null, null,
                $packageStoreContext['with_editor'] === false
                    ? 'optional_segment_not_loaded_non_editor'
                    : 'optional_segment_editor_build_context_required'
            );
        }
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
        $effectiveImportPackageId = $packageId;
        $lookupPackageId = $packageId;
        $extra = [
            'effective_import_package_id' => $effectiveImportPackageId,
            'provider_lookup_package_id' => $lookupPackageId,
        ];

        // AsyncLoading2 can rewrite ImportedPackageId and/or PackageIdToLoad through CoreRedirect,
        // instancing and loose-file localization before consulting FPackageStore. Those are live
        // engine/environment inputs. The catalogue may proceed only when the caller supplies an
        // authoritative aggregate result (an empty rewrite table proves that no rewrite applies).
        if (!$packageStoreContext['identity_authoritative']) {
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'unresolved', null, null,
                'package_identity_runtime_context_required', $extra
            );
        }
        $identityRewrite = $packageStoreContext['identity_rewrites'][self::mapKey($packageId)] ?? null;
        if (is_array($identityRewrite)) {
            $effectiveImportPackageId = self::u64(
                (string)($identityRewrite['imported_package_id'] ?? $packageId),
                'effective ImportedPackageId'
            );
            $lookupPackageId = self::u64(
                (string)($identityRewrite['package_id_to_load'] ?? $effectiveImportPackageId),
                'effective PackageIdToLoad'
            );
            $extra['effective_import_package_id'] = $effectiveImportPackageId;
            $extra['provider_lookup_package_id'] = $lookupPackageId;
            $extra['package_identity_rewrite'] = [
                'source_package_id' => $packageId,
                'imported_package_id' => $effectiveImportPackageId,
                'package_id_to_load' => $lookupPackageId,
                'reason' => (string)($identityRewrite['reason'] ?? 'authoritative_runtime_identity_rewrite'),
            ];
        }

        $redirect = $packageStoreContext['redirects'][self::mapKey($lookupPackageId)] ?? null;
        $localized = $packageStoreContext['localized'][self::mapKey($lookupPackageId)] ?? null;

        // FPackageStore arbitration is global across backend priority and, inside the file backend,
        // mounted-container Order/Sequence. Absence from one consumer ContainerHeader is not proof
        // that no higher-priority backend/container redirects this package. Require an authoritative
        // effective store context even when the consumer's own container has no matching row.
        if (!$packageStoreContext['authoritative']) {
            if (is_array($redirect)) {
                $extra['package_store_redirect_candidate'] = [
                    'source_package_id' => $lookupPackageId,
                    'target_package_id' => self::u64((string)($redirect['target_package_id'] ?? ''), 'PackageRedirect target FPackageId'),
                    'source_package_name' => self::mappedNameText($redirect['source_package_name'] ?? null),
                    'container_index' => isset($redirect['container_index']) ? (int)$redirect['container_index'] : null,
                ];
            }
            if (is_array($localized)) {
                $extra['localized_package_candidate'] = [
                    'source_package_id' => $lookupPackageId,
                    'source_package_name' => self::mappedNameText($localized['source_package_name'] ?? null),
                    'container_index' => isset($localized['container_index']) ? (int)$localized['container_index'] : null,
                ];
            }
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'unresolved', null, null,
                'package_store_mount_context_required', $extra
            );
        }

        // FFilePackageStoreBackend::GetPackageRedirectInfo checks explicit package redirects first.
        // They are container/package-store identity, not CoreRedirects and are not gated by
        // s.AllowPackageRedirectorSupport.
        if (is_array($redirect)) {
            $preRedirectLookupPackageId = $lookupPackageId;
            $lookupPackageId = self::u64((string)($redirect['target_package_id'] ?? ''), 'PackageRedirect target FPackageId');
            $extra['provider_lookup_package_id'] = $lookupPackageId;
            $extra['package_store_redirect'] = [
                'source_package_id' => $preRedirectLookupPackageId,
                'target_package_id' => $lookupPackageId,
                'source_package_name' => self::mappedNameText($redirect['source_package_name'] ?? null),
                'container_index' => isset($redirect['container_index']) ? (int)$redirect['container_index'] : null,
            ];
        } elseif (is_array($localized) && $packageStoreContext['is_editor'] !== true) {
            // The cooked backend resolves localization only outside the editor, by consulting the
            // active culture and then proving that the resulting localized FPackageId exists in the
            // mounted package store. A static package snapshot has no authoritative active culture.
            $extra['localized_package'] = [
                'source_package_id' => $lookupPackageId,
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
        if (count($matches) > 1) {
            $candidateIndices = array_values(array_map(
                static fn(array $candidate): int => (int)$candidate['index'],
                $matches
            ));
            $extra[$cellTarget ? 'candidate_cell_export_indices' : 'candidate_export_indices'] = $candidateIndices;
            $extra['candidate_provider_exports'] = array_values(array_map(
                static fn(array $candidate): array => [
                    'segment' => (string)($candidate['segment'] ?? 'main'),
                    'index' => (int)$candidate['index'],
                ],
                $matches
            ));
            return self::row(
                'PackageImport', $sourceSection, $sourceIndex, $classification,
                $packageId, $publicHash, 'unresolved', $provider, null,
                $cellTarget
                    ? 'public_cell_export_hash_runtime_collision_ambiguous'
                    : 'public_export_hash_runtime_collision_ambiguous',
                $extra
            );
        }
        $match = (array)$matches[0];
        if ((string)($match['segment'] ?? 'main') === 'optional') {
            if ($packageStoreContext['with_editor'] === false) {
                return self::row(
                    'PackageImport', $sourceSection, $sourceIndex, $classification,
                    $packageId, $publicHash, 'missing', $provider, null,
                    'optional_segment_export_not_loaded_non_editor', $extra
                );
            }
            if ($packageStoreContext['with_editor'] === null) {
                return self::row(
                    'PackageImport', $sourceSection, $sourceIndex, $classification,
                    $packageId, $publicHash, 'unresolved', $provider, null,
                    'optional_segment_export_editor_build_context_required', $extra
                );
            }
        }
        if (!$cellTarget && (int)($match['filter_flags'] ?? 0) !== 0) {
            $skipFilteredExport = self::shouldSkipLoadingExport((int)$match['filter_flags'], $packageStoreContext);
            if ($skipFilteredExport === null) {
                return self::row(
                    'PackageImport', $sourceSection, $sourceIndex, $classification,
                    $packageId, $publicHash, 'unresolved', $provider, null,
                    'export_filter_runtime_state_required', $extra
                );
            }
            if ($skipFilteredExport) {
                return self::row(
                    'PackageImport', $sourceSection, $sourceIndex, $classification,
                    $packageId, $publicHash, 'unresolved', $provider, null,
                    'export_filtered_for_runtime_build', $extra
                );
            }
        }
        if (!$cellTarget && self::isObjectRedirectorExport($match)) {
            if ($packageStoreContext['with_editor'] !== false) {
                return self::row(
                    'PackageImport', $sourceSection, $sourceIndex, $classification,
                    $packageId, $publicHash, 'unresolved', $provider, null,
                    $packageStoreContext['with_editor'] === true
                        ? 'object_redirector_destination_runtime_payload_required'
                        : 'object_redirector_editor_build_context_required',
                    $extra
                );
            }
        }
        $providerObject = [
            $cellTarget
                ? 'cell_export_index'
                : ((string)($match['segment'] ?? 'main') === 'optional' ? 'optional_segment_export_index' : 'export_index')
                => (int)$match['index'],
            'public_export_hash' => $publicHash,
        ];
        if (!$cellTarget) {
            $providerObject['object_name'] = $match['object_name'] ?? null;
        } else {
            $providerObject['cpp_class_info'] = $match['cpp_class_info'] ?? null;
        }
        $reason = $cellTarget
            ? 'package_id_public_cell_export_hash_match'
            : 'package_id_public_export_hash_match';
        if (is_array($redirect)) {
            $reason = 'package_store_redirect_' . $reason;
        }
        return self::row(
            'PackageImport', $sourceSection, $sourceIndex, $classification,
            $packageId, $publicHash, 'resolved', $provider, $providerObject, $reason, $extra
        );
    }

    /** @return array<string,mixed> */
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

        $identityRewrites = [];
        foreach ((array)($runtimeContext['package_identity_rewrites'] ?? []) as $rewrite) {
            if (!is_array($rewrite)) {
                throw new RuntimeException('Zen package identity rewrite must be an object/array row.');
            }
            $source = self::u64((string)($rewrite['source_package_id'] ?? ''), 'identity rewrite source FPackageId');
            $identityRewrites[self::mapKey($source)] ??= $rewrite;
        }

        return [
            'redirects' => $redirects,
            'localized' => $localized,
            'authoritative' => !empty($runtimeContext['package_store_context_authoritative']),
            'identity_authoritative' => !empty($runtimeContext['package_identity_context_authoritative']),
            'identity_rewrites' => $identityRewrites,
            // WITH_EDITOR controls optional segments, redirector following and the editor filter rule.
            'with_editor' => array_key_exists('with_editor', $runtimeContext)
                ? (bool)$runtimeContext['with_editor']
                : null,
            // GIsEditor controls cooked file-package-store localization only.
            'is_editor' => array_key_exists('is_editor', $runtimeContext)
                ? (bool)$runtimeContext['is_editor']
                : null,
            'ue_server' => array_key_exists('ue_server', $runtimeContext)
                ? (bool)$runtimeContext['ue_server'] : null,
            'with_server_code' => array_key_exists('with_server_code', $runtimeContext)
                ? (bool)$runtimeContext['with_server_code'] : null,
            'is_server' => array_key_exists('is_server', $runtimeContext)
                ? (bool)$runtimeContext['is_server'] : null,
            'is_client' => array_key_exists('is_client', $runtimeContext)
                ? (bool)$runtimeContext['is_client'] : null,
        ];
    }

    /** @param array<string,mixed> $context */
    private static function shouldSkipLoadingExport(int $filterFlags, array $context): ?bool
    {
        if ($filterFlags === 0) { return false; }
        if ($context['with_editor'] === true) { return false; }
        if ($context['ue_server'] === true) {
            return ($filterFlags & self::FILTER_NOT_FOR_SERVER) !== 0;
        }
        if ($context['with_server_code'] === false) {
            return ($filterFlags & self::FILTER_NOT_FOR_CLIENT) !== 0;
        }
        if ($context['is_server'] !== null && $context['is_client'] !== null) {
            $dedicatedServer = $context['is_server'] === true && $context['is_client'] === false;
            $clientOnly = $context['is_client'] === true && $context['is_server'] === false;
            if ($dedicatedServer) { return ($filterFlags & self::FILTER_NOT_FOR_SERVER) !== 0; }
            if ($clientOnly) { return ($filterFlags & self::FILTER_NOT_FOR_CLIENT) !== 0; }
            return false;
        }
        return null;
    }

    /** @param array<string,mixed> $export */
    private static function isObjectRedirectorExport(array $export): bool
    {
        $classIndex = $export['class_index'] ?? null;
        if (!is_array($classIndex) || (string)($classIndex['type'] ?? '') !== 'ScriptImport') {
            return false;
        }
        return strtoupper((string)($classIndex['script_import_hash'] ?? ''))
            === self::OBJECT_REDIRECTOR_SCRIPT_IMPORT_HASH;
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
        return self::combinedImportSections((array)$snapshot['sections'], $combinedIndex);
    }

    /** @param array<string,mixed> $sections @return array{0:array<string,mixed>,1:bool} */
    private static function combinedImportSections(array $sections, int $combinedIndex): array
    {
        if ($combinedIndex < 0) {
            throw new RuntimeException('Zen dependency bundle import index is negative.');
        }
        $imports = array_values((array)($sections['imports'] ?? []));
        if (isset($imports[$combinedIndex])) {
            return [(array)$imports[$combinedIndex], false];
        }
        $cellIndex = $combinedIndex - count($imports);
        $cellImports = array_values((array)($sections['cell_imports'] ?? []));
        if (!isset($cellImports[$cellIndex])) {
            throw new RuntimeException('Zen dependency bundle import index is outside ordinary/cell import maps.');
        }
        return [(array)$cellImports[$cellIndex], true];
    }

    /** @return array<string,mixed>|null */
    private static function optionalSegment(array $snapshot): ?array
    {
        $row = (array)(((array)(($snapshot['sections'] ?? [])['optional_segment'] ?? []))[0] ?? []);
        return $row === [] ? null : $row;
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
