<?php
/** Builds source-shaped UEDB5 snapshots from authoritative UT4 UE4 package bytes. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5Ut4SnapshotBuilder
{
    public const PACKAGE_FAMILY = 'ue4-classic-package';
    public const SOURCE_POLICY = 'ue4-ut4-v511-unrealtournament-clean-master';
    public const PACKAGE_VERSION = 511;

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(\UnrealPackageReader4 $reader, array $file): array
    {
        $issues = $reader->validatePackage();
        if ($issues !== []) {
            throw new RuntimeException('Cannot persist UT4 UEDB5 metadata: ' . implode('; ', $issues));
        }
        $header = $reader->getHeader();
        $version = (int)($header['version'] ?? -1);
        if ($version !== self::PACKAGE_VERSION || !empty($header['unversioned'])) {
            throw new RuntimeException('UT4 V5 source policy requires serialized UE4 package version 511.');
        }
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UT4 UEDB5 persistence requires positive file and game IDs.');
        }
        $names = $reader->getNames();
        $imports = $reader->getImports();
        $exports = $reader->getExports();
        self::assertCounts($header, $names, $imports, $exports);

        return [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => (string)($file['package_name'] ?? ''),
                'original_name' => (string)($file['original_name'] ?? ''),
            ],
            'package_family' => self::PACKAGE_FAMILY,
            'source_policy' => self::SOURCE_POLICY,
            'section_schemas' => self::sectionSchemas(),
            'sections' => [
                'summary' => [self::summaryRow($header)],
                'names' => array_map(self::nameRow(...), $names),
                'imports' => array_map(self::importRow(...), $imports),
                'exports' => array_map(self::exportRow(...), $exports),
                'string_asset_references' => array_values($reader->getStringAssetReferences()),
                'preload_dependencies' => array_values($reader->getPreloadDependencies()),
            ],
        ];
    }

    /** @return array<string,string> */
    private static function sectionSchemas(): array
    {
        return [
            'summary' => 'ue4.ut4.package-summary.v1',
            'names' => 'ue4.ut4.name-entry.v1',
            'imports' => 'ue4.ut4.object-import.v1',
            'exports' => 'ue4.ut4.object-export.v1',
            'string_asset_references' => 'ue4.ut4.string-asset-reference.v1',
            'preload_dependencies' => 'ue4.ut4.preload-dependency.v1',
        ];
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private static function summaryRow(array $header): array
    {
        return [
            'serialized_tag' => self::u32Hex((int)($header['signature'] ?? 0)),
            'legacy_file_version' => (int)($header['legacyFileVersion'] ?? 0),
            'legacy_ue3_version' => (int)($header['legacyUE3Version'] ?? 0),
            'package_version' => (int)($header['version'] ?? 0),
            'licensee_version' => (int)($header['licenseeVersion'] ?? 0),
            'unversioned' => (bool)($header['unversioned'] ?? false),
            'custom_versions' => array_values((array)($header['customVersions'] ?? [])),
            'total_header_size' => (int)($header['totalHeaderSize'] ?? 0),
            'folder_name' => (string)($header['folderName'] ?? ''),
            'package_flags' => self::u32Hex((int)($header['packageFlags'] ?? 0)),
            'name_count' => (int)($header['nameCount'] ?? 0),
            'name_offset' => (int)($header['nameOffset'] ?? 0),
            'gatherable_text_data_count' => (int)($header['gatherableTextDataCount'] ?? 0),
            'gatherable_text_data_offset' => (int)($header['gatherableTextDataOffset'] ?? 0),
            'export_count' => (int)($header['exportCount'] ?? 0),
            'export_offset' => (int)($header['exportOffset'] ?? 0),
            'import_count' => (int)($header['importCount'] ?? 0),
            'import_offset' => (int)($header['importOffset'] ?? 0),
            'depends_offset' => (int)($header['dependsOffset'] ?? 0),
            'string_asset_references_count' => (int)($header['stringAssetReferencesCount'] ?? 0),
            'string_asset_references_offset' => (int)($header['stringAssetReferencesOffset'] ?? 0),
            'searchable_names_offset' => (int)($header['searchableNamesOffset'] ?? 0),
            'thumbnail_table_offset' => (int)($header['thumbnailTableOffset'] ?? 0),
            'guid' => (string)($header['guid'] ?? ''),
            'persistent_guid' => (string)($header['persistentGuid'] ?? ''),
            'owner_persistent_guid' => (string)($header['ownerPersistentGuid'] ?? ''),
            'generations' => array_values((array)($header['generations'] ?? [])),
            'saved_by_engine_version' => (array)($header['savedByEngineVersion'] ?? []),
            'compatible_with_engine_version' => (array)($header['compatibleWithEngineVersion'] ?? []),
            'compression_flags' => self::u32Hex((int)($header['compressionFlags'] ?? 0)),
            'compressed_chunks' => array_values((array)($header['compressedChunks'] ?? [])),
            'package_source' => self::u32Hex((int)($header['packageSource'] ?? 0)),
            'additional_packages_to_cook' => array_values((array)($header['additionalPackagesToCook'] ?? [])),
            'asset_registry_data_offset' => (int)($header['assetRegistryDataOffset'] ?? 0),
            'bulk_data_start_offset' => (int)($header['bulkDataStartOffset'] ?? 0),
            'world_tile_info_data_offset' => (int)($header['worldTileInfoDataOffset'] ?? 0),
            'chunk_ids' => array_values((array)($header['chunkIDs'] ?? [])),
            'preload_dependency_count' => (int)($header['preloadDependencyCount'] ?? 0),
            'preload_dependency_offset' => (int)($header['preloadDependencyOffset'] ?? 0),
            'uexp_path' => (string)($header['uexpPath'] ?? ''),
            'has_uexp' => (bool)($header['hasUexp'] ?? false),
            'parser_profile' => [
                'key' => (string)($header['parserProfileKey'] ?? ''),
                'label' => (string)($header['parserProfileLabel'] ?? ''),
                'assumed_unversioned_parser_version' => (int)($header['assumedUnversionedParserVersion'] ?? 0),
                'source_reference' => (string)(($header['parserProfile']['source_reference'] ?? '')),
                'raw' => (array)($header['parserProfile'] ?? []),
            ],
        ];
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function nameRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'text' => (string)($row['name'] ?? ''),
            'non_case_hash' => isset($row['nonCaseHash']) ? (int)$row['nonCaseHash'] : null,
            'case_hash' => isset($row['caseHash']) ? (int)$row['caseHash'] : null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function importRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'package_index' => (int)($row['ref'] ?? 0),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'class_package' => self::fname((array)($row['classPackage'] ?? [])),
            'class_name' => self::fname((array)($row['className'] ?? [])),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'object_name' => self::fname((array)($row['objectName'] ?? [])),
            'package_name' => self::fname((array)($row['packageName'] ?? [])),
        ];
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function exportRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'package_index' => (int)($row['ref'] ?? 0),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'class_index' => (int)($row['classIndex'] ?? 0),
            'super_index' => (int)($row['superIndex'] ?? 0),
            'template_index' => (int)($row['templateIndex'] ?? 0),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'object_name' => self::fname((array)($row['objectName'] ?? [])),
            'object_flags' => self::u32Hex((int)($row['objectFlags'] ?? 0)),
            'object_flags_serialized_width_bits' => 32,
            'serial_size' => (int)($row['serialSize'] ?? 0),
            'serial_offset' => (int)($row['serialOffset'] ?? 0),
            'serial_range_serialized_width_bits' => 64,
            'forced_export' => (bool)($row['forcedExport'] ?? false),
            'not_for_client' => (bool)($row['notForClient'] ?? false),
            'not_for_server' => (bool)($row['notForServer'] ?? false),
            'not_for_editor_game' => $row['notForEditorGame'] ?? null,
            'is_asset' => $row['isAsset'] ?? null,
            'package_guid' => (string)($row['packageGuid'] ?? ''),
            'package_flags' => self::u32Hex((int)($row['packageFlags'] ?? 0)),
            'preload' => (array)($row['preload'] ?? []),
        ];
    }
    /** @param array<string,mixed> $value @return array{name_index:int,number:int,text:string} */
    private static function fname(array $value): array
    {
        return [
            'name_index' => (int)($value['index'] ?? 0),
            'number' => (int)($value['number'] ?? 0),
            'text' => (string)($value['text'] ?? ''),
        ];
    }

    private static function u32Hex(int $value): string
    {
        return strtoupper(sprintf('%08X', $value & 0xFFFFFFFF));
    }

    /**
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $names
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     */
    private static function assertCounts(array $header, array $names, array $imports, array $exports): void
    {
        $expected = [
            'names' => (int)($header['nameCount'] ?? -1),
            'imports' => (int)($header['importCount'] ?? -1),
            'exports' => (int)($header['exportCount'] ?? -1),
        ];
        $actual = [
            'names' => count($names),
            'imports' => count($imports),
            'exports' => count($exports),
        ];
        if ($expected !== $actual) {
            throw new RuntimeException(
                'UT4 reader table counts do not match the serialized summary: expected='
                . json_encode($expected) . ' actual=' . json_encode($actual)
            );
        }
    }
}
