<?php
/**
 * Builds source-shaped UEDB5 staging snapshots from the audited UE5 5.8.3 classic LinkerLoad reader.
 * This class does not publish SQL projections and is not wired into the production UEDB4 runtime.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5Ue5ClassicSnapshotBuilder
{
    public const PACKAGE_FAMILY = 'classic-linkerload';
    public const SOURCE_POLICY = 'ue5-5.8.3-release-396c9f059903aed5fec78ecd3d437a40c6415368';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(\UnrealPackageReader5 $reader, array $file): array
    {
        $issues = array_values(array_filter($reader->validatePackage(), static fn(string $issue): bool =>
            !str_starts_with($issue, 'Package is unversioned; using assumed UE5 parser versions ')));
        if ($issues !== []) {
            throw new RuntimeException('Cannot persist UE5 classic UEDB5 metadata from a reader with validation issues: '
                . implode('; ', $issues));
        }

        $header = $reader->getHeader();
        $names = $reader->getNames();
        $imports = $reader->getImports();
        $exports = $reader->getExports();
        $soft = $reader->getStringAssetReferences();
        $preload = $reader->getPreloadDependencies();
        self::assertReaderShape($header, $names, $imports, $exports, $soft, $preload);

        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UE5 classic UEDB5 persistence requires positive file and game IDs.');
        }

        $packageName = trim((string)($file['package_name'] ?? ''));
        if ($packageName === '') {
            $packageName = (string)($header['packageName'] ?? '');
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
                'summary' => [self::summaryRow($header)],
                'names' => array_map(self::nameRow(...), $names),
                'imports' => array_map(self::importRow(...), $imports),
                'exports' => array_map(self::exportRow(...), $exports),
                'soft_package_references' => array_map(self::softReferenceRow(...), $soft),
                'preload_dependencies' => array_map(self::preloadRow(...), $preload),
            ],
        ];
    }

    /** @return array<string,string> */
    private static function sectionSchemas(): array
    {
        return [
            'summary' => 'ue5.classic.package-summary.v1',
            'names' => 'ue5.classic.name-map.v1',
            'imports' => 'ue5.classic.object-import.v1',
            'exports' => 'ue5.classic.object-export.v1',
            'soft_package_references' => 'ue5.classic.soft-package-reference.v1',
            'preload_dependencies' => 'ue5.classic.preload-dependency.v1',
        ];
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private static function summaryRow(array $header): array
    {
        return [
            'serialized_tag' => self::u32Hex((int)($header['serializedSignature'] ?? 0)),
            'normalized_tag' => self::u32Hex((int)($header['signature'] ?? 0)),
            'byte_swapping' => (bool)($header['byteSwapping'] ?? false),
            'legacy_file_version' => (int)($header['legacyFileVersion'] ?? 0),
            'legacy_ue3_version' => (int)($header['legacyUE3Version'] ?? 0),
            'serialized_file_version' => [
                'ue4' => (int)($header['serializedUE4Version'] ?? 0),
                'ue5' => (int)($header['serializedUE5Version'] ?? 0),
                'licensee' => (int)($header['serializedLicenseeVersion'] ?? 0),
            ],
            'effective_file_version' => [
                'ue4' => (int)($header['ue4Version'] ?? 0),
                'ue5' => (int)($header['ue5Version'] ?? 0),
                'licensee' => (int)($header['licenseeVersion'] ?? 0),
            ],
            'unversioned' => (bool)($header['unversioned'] ?? false),
            'parser_profile' => [
                'key' => (string)($header['parserProfileKey'] ?? ''),
                'label' => (string)($header['parserProfileLabel'] ?? ''),
                'assumed_ue4_version' => (int)($header['assumedUnversionedUE4Version'] ?? 0),
                'assumed_ue5_version' => (int)($header['assumedUnversionedUE5Version'] ?? 0),
            ],
            'custom_versions' => array_values((array)($header['customVersions'] ?? [])),
            'package_name' => (string)($header['packageName'] ?? ''),
            'package_flags' => self::u32Hex((int)($header['packageFlags'] ?? 0)),
            'saved_hash' => (string)($header['savedHash'] ?? ''),
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
            'chunk_ids' => array_values((array)($header['chunkIDs'] ?? [])),
            'counts' => [
                'names' => (int)($header['nameCount'] ?? 0),
                'imports' => (int)($header['importCount'] ?? 0),
                'exports' => (int)($header['exportCount'] ?? 0),
                'soft_package_references' => (int)($header['stringAssetReferencesCount'] ?? 0),
                'preload_dependencies' => max(0, (int)($header['preloadDependencyCount'] ?? 0)),
                'soft_object_paths' => (int)($header['softObjectPathsCount'] ?? 0),
                'cell_exports' => (int)($header['cellExportCount'] ?? 0),
                'cell_imports' => (int)($header['cellImportCount'] ?? 0),
                'import_type_hierarchies' => (int)($header['importTypeHierarchiesCount'] ?? 0),
            ],
            'total_header_size' => (int)($header['totalHeaderSize'] ?? 0),
            'names_referenced_from_export_data_count' => (int)($header['namesReferencedFromExportDataCount'] ?? 0),
            'payload_toc_offset' => (int)($header['payloadTocOffset'] ?? -1),
            'data_resource_offset' => (int)($header['dataResourceOffset'] ?? -1),
        ];
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function nameRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'text' => (string)($row['name'] ?? ''),
            'serialized_offset' => (int)($row['offset'] ?? 0),
            'non_case_hash' => array_key_exists('nonCaseHash', $row) && $row['nonCaseHash'] !== null
                ? (int)$row['nonCaseHash'] : null,
            'case_hash' => array_key_exists('caseHash', $row) && $row['caseHash'] !== null
                ? (int)$row['caseHash'] : null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function importRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'package_index' => (int)($row['ref'] ?? 0),
            'serialized_offset' => (int)($row['offset'] ?? 0),
            'class_package' => self::fname((array)($row['classPackage'] ?? [])),
            'class_name' => self::fname((array)($row['className'] ?? [])),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'object_name' => self::fname((array)($row['objectName'] ?? [])),
            'serialized_package_name_present' => (bool)($row['serializedPackageNamePresent'] ?? false),
            'serialized_package_name' => !empty($row['serializedPackageNamePresent'])
                ? self::fname((array)($row['serializedPackageName'] ?? [])) : null,
            'effective_package_name' => self::effectiveFname((array)($row['packageName'] ?? [])),
            'b_import_optional_present' => (bool)($row['bImportOptionalPresent'] ?? false),
            'b_import_optional' => !empty($row['bImportOptionalPresent'])
                ? (bool)($row['bImportOptional'] ?? false) : null,
        ];
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function exportRow(array $row): array
    {
        $preload = (array)($row['preload'] ?? []);
        return [
            'index' => (int)($row['index'] ?? -1),
            'package_index' => (int)($row['ref'] ?? 0),
            'serialized_offset' => (int)($row['offset'] ?? 0),
            'class_index' => (int)($row['classIndex'] ?? 0),
            'super_index' => (int)($row['superIndex'] ?? 0),
            'template_index_present' => (bool)($row['templateIndexPresent'] ?? false),
            'template_index' => !empty($row['templateIndexPresent']) ? (int)($row['templateIndex'] ?? 0) : null,
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'object_name' => self::fname((array)($row['objectName'] ?? [])),
            'object_flags' => self::u64HexFromU32((int)($row['objectFlags'] ?? 0)),
            'object_flags_serialized_width_bits' => 32,
            'object_flags_semantics' => 'RF_Load mask serialized as uint32',
            'serial_size_width_bits' => (int)($row['serialSizeWidthBits'] ?? 0),
            'serial_size' => (int)($row['serialSize'] ?? 0),
            'serial_offset' => (int)($row['serialOffset'] ?? 0),
            'b_forced_export' => (bool)($row['forcedExport'] ?? false),
            'b_not_for_client' => (bool)($row['notForClient'] ?? false),
            'b_not_for_server' => (bool)($row['notForServer'] ?? false),
            'package_guid_present' => (bool)($row['packageGuidPresent'] ?? false),
            'package_guid' => !empty($row['packageGuidPresent']) ? (string)($row['packageGuid'] ?? '') : null,
            'b_is_inherited_instance_present' => (bool)($row['bIsInheritedInstancePresent'] ?? false),
            'b_is_inherited_instance' => !empty($row['bIsInheritedInstancePresent'])
                ? (bool)($row['isInheritedInstance'] ?? false) : null,
            'package_flags' => self::u32Hex((int)($row['packageFlags'] ?? 0)),
            'b_not_always_loaded_for_editor_game_present' => (bool)($row['notForEditorGamePresent'] ?? false),
            'b_not_always_loaded_for_editor_game' => $row['notForEditorGame'] ?? null,
            'b_is_asset_present' => (bool)($row['isAssetPresent'] ?? false),
            'b_is_asset' => $row['isAsset'] ?? null,
            'b_generate_public_hash_present' => (bool)($row['bGeneratePublicHashPresent'] ?? false),
            'b_generate_public_hash' => !empty($row['bGeneratePublicHashPresent'])
                ? (bool)($row['bGeneratePublicHash'] ?? false) : null,
            'preload_dependency_range_present' => (bool)($row['preloadPresent'] ?? false),
            'preload_dependency_range' => !empty($row['preloadPresent']) ? [
                'first_export_dependency' => (int)($preload['firstExportDependency'] ?? 0),
                'serialization_before_serialization' => (int)($preload['serializationBeforeSerializationDependencies'] ?? 0),
                'create_before_serialization' => (int)($preload['createBeforeSerializationDependencies'] ?? 0),
                'serialization_before_create' => (int)($preload['serializationBeforeCreateDependencies'] ?? 0),
                'create_before_create' => (int)($preload['createBeforeCreateDependencies'] ?? 0),
            ] : null,
            'script_serialization_offsets_present' => (bool)($row['scriptSerializationOffsetsPresent'] ?? false),
            'script_serialization_start_offset' => !empty($row['scriptSerializationOffsetsPresent'])
                ? (int)($row['scriptSerializationStartOffset'] ?? 0) : null,
            'script_serialization_end_offset' => !empty($row['scriptSerializationOffsetsPresent'])
                ? (int)($row['scriptSerializationEndOffset'] ?? 0) : null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function softReferenceRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'serialized_offset' => (int)($row['offset'] ?? 0),
            'package_name' => self::fname((array)($row['name'] ?? [])),
            'path' => (string)($row['path'] ?? ''),
            'dependency_class' => 'soft_package_reference',
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function preloadRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'serialized_offset' => (int)($row['offset'] ?? 0),
            'package_index' => (int)($row['ref'] ?? 0),
            'path' => (string)($row['path'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $name @return array{name_index:int,number:int,text:string} */
    private static function fname(array $name): array
    {
        foreach (['index', 'number', 'text'] as $field) {
            if (!array_key_exists($field, $name)) {
                throw new RuntimeException('UE5 classic UEDB5 persistence requires raw FName ' . $field . '.');
            }
        }
        return [
            'name_index' => (int)$name['index'],
            'number' => (int)$name['number'],
            'text' => (string)$name['text'],
        ];
    }

    /** @param array<string,mixed> $name @return array{name_index:?int,number:?int,text:string,is_none:bool} */
    private static function effectiveFname(array $name): array
    {
        $text = (string)($name['text'] ?? '');
        $isNone = $text === '' || ((int)($name['number'] ?? 0) === 0 && strcasecmp($text, 'None') === 0);
        return [
            'name_index' => $isNone ? null : (int)($name['index'] ?? 0),
            'number' => $isNone ? null : (int)($name['number'] ?? 0),
            'text' => $text,
            'is_none' => $isNone,
        ];
    }

    private static function u32Hex(int $value): string
    {
        return sprintf('%08X', $value & 0xffffffff);
    }

    private static function u64HexFromU32(int $value): string
    {
        return '00000000' . self::u32Hex($value);
    }

    /**
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $names
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @param list<array<string,mixed>> $soft
     * @param list<array<string,mixed>> $preload
     */
    private static function assertReaderShape(
        array $header,
        array $names,
        array $imports,
        array $exports,
        array $soft,
        array $preload
    ): void {
        if ((int)($header['legacyFileVersion'] ?? 0) > -8
            || (int)($header['ue5Version'] ?? 0) < 1000) {
            throw new RuntimeException('UE5 classic UEDB5 builder received a non-UE5 classic package.');
        }
        if (!array_key_exists('serializedUE4Version', $header)
            || !array_key_exists('serializedUE5Version', $header)
            || !array_key_exists('serializedLicenseeVersion', $header)
            || !array_key_exists('serializedSignature', $header)
            || !array_key_exists('byteSwapping', $header)) {
            throw new RuntimeException('UE5 classic reader did not expose lossless serialized version/tag identity.');
        }

        self::assertCount('names', (int)($header['nameCount'] ?? -1), $names);
        self::assertCount('imports', (int)($header['importCount'] ?? -1), $imports);
        self::assertCount('exports', (int)($header['exportCount'] ?? -1), $exports);
        self::assertCount('soft package references', (int)($header['stringAssetReferencesCount'] ?? -1), $soft);
        $expectedPreload = (int)($header['preloadDependencyCount'] ?? 0);
        if ($expectedPreload >= 0) {
            self::assertCount('preload dependencies', $expectedPreload, $preload);
        }

        foreach ($imports as $row) {
            if (!array_key_exists('serializedPackageNamePresent', $row)
                || !array_key_exists('serializedPackageName', $row)
                || !array_key_exists('packageName', $row)
                || !array_key_exists('bImportOptionalPresent', $row)
                || !array_key_exists('bImportOptional', $row)) {
                throw new RuntimeException('UE5 classic import is missing UEDB5-required source fields.');
            }
        }
        foreach ($exports as $row) {
            foreach (['classIndex', 'superIndex', 'templateIndexPresent', 'templateIndex', 'outerIndex', 'objectName',
                'objectFlags', 'serialSizeWidthBits', 'packageFlags', 'packageGuidPresent',
                'bIsInheritedInstancePresent', 'notForEditorGamePresent', 'isAssetPresent',
                'bGeneratePublicHashPresent', 'bGeneratePublicHash', 'preloadPresent', 'preload',
                'scriptSerializationOffsetsPresent', 'scriptSerializationStartOffset', 'scriptSerializationEndOffset'] as $field) {
                if (!array_key_exists($field, $row)) {
                    throw new RuntimeException('UE5 classic export is missing UEDB5-required field ' . $field . '.');
                }
            }
        }
    }

    /** @param array<mixed> $rows */
    private static function assertCount(string $label, int $expected, array $rows): void
    {
        if ($expected < 0 || count($rows) !== $expected) {
            throw new RuntimeException('UE5 classic ' . $label . ' count mismatch: expected '
                . $expected . ', found ' . count($rows) . '.');
        }
    }
}
