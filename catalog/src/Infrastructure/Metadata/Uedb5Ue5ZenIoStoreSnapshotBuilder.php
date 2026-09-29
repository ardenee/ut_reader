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

        [$storeEntry, $storeListIndex, $optionalSegment] = self::findStoreEntry($containerHeader, $packageId);
        $packageChunkIndex = $toc->findPackageChunk($packageId);
        if ($packageChunkIndex === null) {
            throw new RuntimeException('IoStore TOC has no ExportBundleData chunk for FPackageId ' . $packageId . '.');
        }
        $chunks = $toc->chunks();
        $chunk = $chunks[$packageChunkIndex];
        $zen = Uedb5ZenPackageReader::parse($toc->readChunk($packageChunkIndex), $packageId, $storeEntry);

        $soft = self::softReferencesForPackage($containerHeader, $storeListIndex, $optionalSegment);
        $redirects = self::relevantRedirects($containerHeader, $packageId);
        $localized = self::relevantLocalized($containerHeader, $packageId);
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
                    'optional_segment' => $optionalSegment,
                    'store_entry_index' => $storeListIndex,
                    'imported_package_ids' => array_values((array)$storeEntry['imported_package_ids']),
                    'shader_map_hashes' => array_values((array)$storeEntry['shader_map_hashes']),
                ]],
                'name_map' => self::indexedRows((array)$zen['name_map']),
                'imported_package_ids' => self::importedPackageRows($zen),
                'imported_public_export_hashes' => self::hashRows((array)$zen['imported_public_export_hashes']),
                'imports' => self::classifiedObjectIndexes((array)$zen['import_map'], $optionalSegment, false),
                'exports' => self::indexedRows((array)$zen['exports']),
                'cell_imports' => self::classifiedObjectIndexes((array)$zen['cell_import_map'], $optionalSegment, true),
                'cell_exports' => self::indexedRows((array)$zen['cell_exports']),
                'export_bundle_entries' => self::classifiedLoadOrderRows((array)$zen['export_bundle_entries']),
                'dependency_bundle_headers' => self::indexedRows((array)$zen['dependency_bundle_headers']),
                'dependency_bundle_entries' => self::classifiedDependencyBundleRows((array)$zen['dependency_bundle_entries']),
                'bulk_data_map' => self::indexedRows((array)$zen['bulk_data_map']),
                'imported_package_names' => self::indexedRows((array)$zen['imported_package_names']),
                'soft_package_references' => $soft,
                'package_redirects' => $redirects,
                'localized_packages' => $localized,
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
            'package_redirects' => 'ue5.zen.package-redirect.v1',
            'localized_packages' => 'ue5.zen.localized-package.v1',
        ];
    }

    /** @return array{0:array<string,mixed>,1:int,2:bool} */
    private static function findStoreEntry(array $containerHeader, string $packageId): array
    {
        $matches = [];
        foreach ((array)$containerHeader['store_entries'] as $index => $entry) {
            if ((string)($entry['package_id'] ?? '') === $packageId) {
                $matches[] = [(array)$entry, (int)$index, false];
            }
        }
        foreach ((array)$containerHeader['optional_segment_store_entries'] as $index => $entry) {
            if ((string)($entry['package_id'] ?? '') === $packageId) {
                $matches[] = [(array)$entry, (int)$index, true];
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('FPackageId must have exactly one package-store entry in the selected container context.');
        }
        return $matches[0];
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
    private static function relevantRedirects(array $containerHeader, string $packageId): array
    {
        $rows = [];
        foreach ((array)($containerHeader['package_redirects'] ?? []) as $row) {
            $row = (array)$row;
            if (($row['source_package_id'] ?? null) === $packageId || ($row['target_package_id'] ?? null) === $packageId) {
                $rows[] = ['dependency_class' => 'runtime_derived'] + $row;
            }
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function relevantLocalized(array $containerHeader, string $packageId): array
    {
        $rows = [];
        foreach ((array)($containerHeader['localized_packages'] ?? []) as $row) {
            $row = (array)$row;
            if (($row['source_package_id'] ?? null) === $packageId) {
                $rows[] = ['dependency_class' => 'runtime_derived'] + $row;
            }
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
