<?php
/**
 * Builds isolated source-shaped UEDB5 snapshots from UE5 5.8.3 IoStore/Zen containers.
 * It does not publish SQL projections or register format 5 in the production runtime.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5Ue5ZenIoStoreSnapshotBuilder
{
    public const PACKAGE_FAMILY = Uedb5ZenPackageReader::PACKAGE_FAMILY;
    public const SOURCE_POLICY = Uedb5ZenPackageReader::SOURCE_POLICY;

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(Uedb5IoStoreTocReader $toc, string $packageId, array $file): array
    {
        $packageId = self::u64($packageId, 'FPackageId');
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Zen UEDB5 persistence requires positive file and game IDs.');
        }

        $tocHeader = $toc->header();
        $containerHeaderIndexes = $toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_CONTAINER_HEADER);
        if (count($containerHeaderIndexes) !== 1) {
            throw new RuntimeException('Selected IoStore container must expose exactly one ContainerHeader chunk.');
        }
        $containerHeaderIndex = $containerHeaderIndexes[0];
        $containerHeader = Uedb5IoStoreContainerHeaderReader::parse($toc->readChunk($containerHeaderIndex));
        if ((string)$containerHeader['container_id'] !== (string)$tocHeader['container_id']) {
            throw new RuntimeException('IoStore ContainerHeader ContainerId does not match the selected TOC.');
        }

        [$storeEntry, $storeListIndex, $optionalStoreEntry, $optionalStoreListIndex] = self::findStoreEntries($containerHeader, $packageId);
        $packageChunkIndex = $toc->findPackageChunk($packageId, Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA, 0);
        if ($packageChunkIndex === null) {
            throw new RuntimeException('IoStore TOC has no ExportBundleData chunk for FPackageId ' . $packageId . '.');
        }
        $chunks = $toc->chunks();
        $chunk = $chunks[$packageChunkIndex];
        $zen = Uedb5ZenPackageReader::parse($toc->readChunk($packageChunkIndex), $packageId, $storeEntry);
        $optionalZen = null;
        $optionalChunkIndex = null;
        $optionalChunk = null;
        $optionalSoft = [];
        if (is_array($optionalStoreEntry)) {
            $optionalChunkIndex = $toc->findPackageChunk($packageId, Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA, 1);
            if ($optionalChunkIndex === null) {
                throw new RuntimeException('IoStore package-store entry declares an optional segment but chunk index 1 is absent for FPackageId ' . $packageId . '.');
            }
            $optionalChunk = $toc->chunks()[$optionalChunkIndex];
            $optionalZen = Uedb5ZenPackageReader::parse($toc->readChunk($optionalChunkIndex), $packageId, $optionalStoreEntry);
            $optionalSoft = self::softReferencesForPackage($containerHeader, (int)$optionalStoreListIndex, true);
        }

        $soft = self::softReferencesForPackage($containerHeader, $storeListIndex, false);
        // Package-store redirects/localization are container-global runtime lookup context.
        // Retain the complete selected ContainerHeader order; filtering them to the current
        // package loses redirects that Epic may apply to any imported FPackageId.
        $redirects = self::containerRedirects($containerHeader);
        $localized = self::containerLocalizedPackages($containerHeader);
        $zenPackageName = (array)$zen['package_name'];
        $packageName = trim((string)($file['package_name'] ?? ''));
        if ($packageName === '') {
            $packageName = (string)($zenPackageName['text'] ?? '');
        }

        return [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
                'original_name' => (string)($file['original_name'] ?? ''),
            ],
            'package_family' => self::PACKAGE_FAMILY,
            'source_policy' => self::SOURCE_POLICY,
            'section_schemas' => self::sectionSchemas(),
            'sections' => [
                'container_provenance' => [[
                    'utoc_filename' => $toc->utocFilename(),
                    'utoc_sha256' => $toc->tocSha256(),
                    'toc_version' => (int)$tocHeader['version'],
                    'toc_header_size' => (int)$tocHeader['toc_header_size'],
                    'container_id' => (string)$tocHeader['container_id'],
                    'container_flags' => (int)$tocHeader['container_flags'],
                    'partition_count' => (int)$tocHeader['partition_count'],
                    'partition_size_u64' => (string)$tocHeader['partition_size_u64'],
                    'compression_methods' => $toc->compressionMethods(),
                    'container_header_version' => (int)$containerHeader['version'],
                    'package_chunk_toc_index' => $packageChunkIndex,
                    'package_chunk_raw_id' => (string)$chunk['raw'],
                    'package_chunk_offset' => (int)$chunk['offset'],
                    'package_chunk_length' => (int)$chunk['length'],
                    'optional_segment_chunk_toc_index' => $optionalChunkIndex,
                    'optional_segment_chunk_raw_id' => is_array($optionalChunk) ? (string)$optionalChunk['raw'] : null,
                    'optional_segment_chunk_offset' => is_array($optionalChunk) ? (int)$optionalChunk['offset'] : null,
                    'optional_segment_chunk_length' => is_array($optionalChunk) ? (int)$optionalChunk['length'] : null,
                ]],
                'package_summary' => [[
                    'package_id' => $packageId,
                    'package_name' => $zenPackageName,
                    'package_name_text' => (string)($zenPackageName['text'] ?? ''),
                    'summary' => (array)$zen['summary'],
                    'versioning_info' => $zen['versioning_info'],
                    'cell_offsets' => (array)$zen['cell_offsets'],
                    'header_bytes' => (int)$zen['header_bytes'],
                    'exports_data_bytes' => (int)$zen['exports_data_bytes'],
                ]],
                'package_store' => [[
                    'package_id' => $packageId,
                    'optional_segment' => false,
                    'store_entry_index' => $storeListIndex,
                    'imported_package_ids' => array_values((array)$storeEntry['imported_package_ids']),
                    'shader_map_hashes' => array_values((array)$storeEntry['shader_map_hashes']),
                    'has_optional_segment' => is_array($optionalStoreEntry),
                    'optional_segment_store_entry_index' => $optionalStoreListIndex,
                    'optional_segment_imported_package_ids' => is_array($optionalStoreEntry)
                        ? array_values((array)$optionalStoreEntry['imported_package_ids']) : [],
                    'optional_segment_shader_map_hashes' => is_array($optionalStoreEntry)
                        ? array_values((array)$optionalStoreEntry['shader_map_hashes']) : [],
                ]],
                'name_map' => self::indexedRows((array)$zen['name_map']),
                'imported_package_ids' => self::importedPackageRows($zen),
                'imported_public_export_hashes' => self::hashRows((array)$zen['imported_public_export_hashes']),
                'imports' => self::classifiedObjectIndexes((array)$zen['import_map'], false, false),
                'exports' => self::indexedRows((array)$zen['exports']),
                'cell_imports' => self::classifiedObjectIndexes((array)$zen['cell_import_map'], false, true),
                'cell_exports' => self::indexedRows((array)$zen['cell_exports']),
                'export_bundle_entries' => self::classifiedLoadOrderRows((array)$zen['export_bundle_entries']),
                'dependency_bundle_headers' => self::indexedRows((array)$zen['dependency_bundle_headers']),
                'dependency_bundle_entries' => self::classifiedDependencyBundleRows((array)$zen['dependency_bundle_entries']),
                'bulk_data_map' => self::indexedRows((array)$zen['bulk_data_map']),
                'imported_package_names' => self::indexedRows((array)$zen['imported_package_names']),
                'soft_package_references' => $soft,
                'package_redirects' => $redirects,
                'localized_packages' => $localized,
                'optional_segment' => $optionalZen === null ? [] : [[
                    'package_id' => $packageId,
                    'summary' => (array)$optionalZen['summary'],
                    'versioning_info' => $optionalZen['versioning_info'],
                    'cell_offsets' => (array)$optionalZen['cell_offsets'],
                    'header_bytes' => (int)$optionalZen['header_bytes'],
                    'exports_data_bytes' => (int)$optionalZen['exports_data_bytes'],
                    'name_map' => self::indexedRows((array)$optionalZen['name_map']),
                    'imported_package_ids' => self::importedPackageRows($optionalZen),
                    'imported_public_export_hashes' => self::hashRows((array)$optionalZen['imported_public_export_hashes']),
                    'imports' => self::classifiedObjectIndexes((array)$optionalZen['import_map'], true, false),
                    'exports' => self::indexedRows((array)$optionalZen['exports']),
                    'cell_imports' => self::classifiedObjectIndexes((array)$optionalZen['cell_import_map'], true, true),
                    'cell_exports' => self::indexedRows((array)$optionalZen['cell_exports']),
                    'export_bundle_entries' => self::classifiedLoadOrderRows((array)$optionalZen['export_bundle_entries']),
                    'dependency_bundle_headers' => self::indexedRows((array)$optionalZen['dependency_bundle_headers']),
                    'dependency_bundle_entries' => self::classifiedDependencyBundleRows((array)$optionalZen['dependency_bundle_entries']),
                    'bulk_data_map' => self::indexedRows((array)$optionalZen['bulk_data_map']),
                    'imported_package_names' => self::indexedRows((array)$optionalZen['imported_package_names']),
                    'soft_package_references' => $optionalSoft,
                ]],
            ],
        ];
    }

    /** @return array<string,string> */
    private static function sectionSchemas(): array
    {
        return [
            'container_provenance' => 'ue5.zen.iostore-provenance.v1',
            'package_summary' => 'ue5.zen.package-summary.v1',
            'package_store' => 'ue5.zen.package-store-entry.v1',
            'name_map' => 'ue5.zen.name-map.v1',
            'imported_package_ids' => 'ue5.zen.imported-package-id.v1',
            'imported_public_export_hashes' => 'ue5.zen.imported-public-export-hash.v1',
            'imports' => 'ue5.zen.package-object-index.v1',
            'exports' => 'ue5.zen.export-map-entry.v1',
            'cell_imports' => 'ue5.zen.cell-import.v1',
            'cell_exports' => 'ue5.zen.cell-export.v1',
            'export_bundle_entries' => 'ue5.zen.export-bundle-entry.v1',
            'dependency_bundle_headers' => 'ue5.zen.dependency-bundle-header.v1',
            'dependency_bundle_entries' => 'ue5.zen.dependency-bundle-entry.v1',
            'bulk_data_map' => 'ue5.zen.bulk-data-map-entry.v1',
            'imported_package_names' => 'ue5.zen.imported-package-name.v1',
            'soft_package_references' => 'ue5.zen.soft-package-reference.v1',
            'package_redirects' => 'ue5.zen.package-redirect.v2',
            'localized_packages' => 'ue5.zen.localized-package.v2',
            'optional_segment' => 'ue5.zen.optional-segment.v1',
        ];
    }

    /** @return array{0:array<string,mixed>,1:int,2:?array<string,mixed>,3:?int} */
    private static function findStoreEntries(array $containerHeader, string $packageId): array
    {
        $ordinary = [];
        foreach ((array)$containerHeader['store_entries'] as $index => $entry) {
            if ((string)($entry['package_id'] ?? '') === $packageId) {
                $ordinary[] = [(array)$entry, (int)$index];
            }
        }
        if (count($ordinary) !== 1) {
            throw new RuntimeException('FPackageId must have exactly one ordinary package-store entry in the selected container context.');
        }
        $optional = [];
        foreach ((array)$containerHeader['optional_segment_store_entries'] as $index => $entry) {
            if ((string)($entry['package_id'] ?? '') === $packageId) {
                $optional[] = [(array)$entry, (int)$index];
            }
        }
        if (count($optional) > 1) {
            throw new RuntimeException('FPackageId has more than one optional-segment package-store entry in the selected container context.');
        }
        return [
            $ordinary[0][0], $ordinary[0][1],
            $optional[0][0] ?? null, $optional[0][1] ?? null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function importedPackageRows(array $zen): array
    {
        $ids = array_values((array)$zen['imported_package_ids']);
        $names = array_values((array)$zen['imported_package_names']);
        $rows = [];
        foreach ($ids as $index => $id) {
            $rows[] = [
                'index' => $index,
                'package_id' => (string)$id,
                'serialized_name' => $names[$index] ?? null,
                'dependency_class' => 'hard_package',
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function hashRows(array $hashes): array
    {
        $rows = [];
        foreach ($hashes as $index => $hash) {
            $rows[] = ['index' => $index, 'public_export_hash' => (string)$hash];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function indexedRows(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $index => $row) {
            $out[] = ['index' => $index] + (array)$row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function classifiedObjectIndexes(array $rows, bool $optionalSegment, bool $cell): array
    {
        $out = [];
        foreach (array_values($rows) as $index => $row) {
            $row = (array)$row;
            $type = (string)($row['type'] ?? '');
            $classification = match ($type) {
                'PackageImport' => $cell ? 'cell_verse' : ($optionalSegment ? 'optional' : 'hard'),
                'ScriptImport' => 'script',
                default => $cell ? 'cell_verse' : 'runtime_derived',
            };
            $out[] = ['index' => $index, 'dependency_class' => $classification] + $row;
        }
        return $out;
    }
    /** @return list<array<string,mixed>> */
    private static function classifiedLoadOrderRows(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $index => $row) {
            $out[] = ['index' => $index, 'dependency_class' => 'load_order'] + (array)$row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function classifiedDependencyBundleRows(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $index => $row) {
            $out[] = ['index' => $index, 'dependency_class' => 'load_order'] + (array)$row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function softReferencesForPackage(array $containerHeader, int $storeIndex, bool $optional): array
    {
        if ($optional) {
            return [];
        }
        $soft = (array)($containerHeader['soft_package_references'] ?? []);
        $refs = (array)(($soft['package_references'] ?? [])[$storeIndex] ?? []);
        $rows = [];
        foreach (array_values($refs) as $index => $ref) {
            $rows[] = ['index' => $index, 'dependency_class' => 'soft'] + (array)$ref;
        }
        return $rows;
    }
    /** @return list<array<string,mixed>> */
    private static function containerRedirects(array $containerHeader): array
    {
        $rows = [];
        foreach (array_values((array)($containerHeader['package_redirects'] ?? [])) as $index => $row) {
            $rows[] = [
                'container_index' => $index,
                'dependency_class' => 'runtime_derived',
            ] + (array)$row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function containerLocalizedPackages(array $containerHeader): array
    {
        $rows = [];
        foreach (array_values((array)($containerHeader['localized_packages'] ?? [])) as $index => $row) {
            $rows[] = [
                'container_index' => $index,
                'dependency_class' => 'runtime_derived',
            ] + (array)$row;
        }
        return $rows;
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
