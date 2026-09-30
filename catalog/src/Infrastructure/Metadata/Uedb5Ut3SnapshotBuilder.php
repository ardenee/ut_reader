<?php
/** Source-shaped UEDB5 persistence for UT3 package version 512. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5Ut3SnapshotBuilder
{
    public const PACKAGE_FAMILY = 'classic-linkerload';
    public const PACKAGE_VERSION = 512;
    public const SOURCE_POLICY = 'ue3-ut3-jan2008-package-v512';

    /** @param object $reader @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(object $reader, array $file): array
    {
        $issues = method_exists($reader, 'validatePackage') ? (array)$reader->validatePackage() : [];
        if ($issues !== []) {
            throw new RuntimeException('Cannot persist UT3 UEDB5 metadata: ' . implode('; ', $issues));
        }
        $header = (array)$reader->getHeader();
        if ((int)($header['version'] ?? -1) !== self::PACKAGE_VERSION) {
            throw new RuntimeException('UT3 V5 source policy requires package version 512.');
        }
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UT3 UEDB5 persistence requires positive file/game IDs.');
        }
        $names = (array)$reader->getNames();
        $imports = (array)$reader->getImports();
        $exports = (array)$reader->getExports();
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
            'section_schemas' => [
                'summary' => 'ue3.ut3.package-summary.v1',
                'names' => 'ue3.ut3.name-entry.v1',
                'imports' => 'ue3.ut3.object-import.v1',
                'exports' => 'ue3.ut3.object-export.v1',
                'compression_chunks' => 'ue3.ut3.compression-chunk.v1',
            ],
            'sections' => [
                'summary' => [self::summaryRow($header)],
                'names' => array_map(self::nameRow(...), $names),
                'imports' => array_map(self::importRow(...), $imports),
                'exports' => array_map(self::exportRow(...), $exports),
                'compression_chunks' => self::compressionRows((array)($header['chunks'] ?? [])),
            ],
        ];
    }

    /** @param array<string,mixed> $h @return array<string,mixed> */
    private static function summaryRow(array $h): array
    {
        return [
            'serialized_tag' => self::u32Hex((int)($h['sourceTag'] ?? $h['tag'] ?? 0)),
            'byte_swapped' => (bool)($h['byteSwapped'] ?? false),
            'packed_file_version' => self::u32Hex((int)($h['packedVersion'] ?? 0)),
            'package_version' => (int)($h['version'] ?? 0),
            'licensee_version' => (int)($h['licenseeVersion'] ?? 0),
            'total_header_size' => (int)($h['totalHeaderSize'] ?? 0),
            'folder_name' => (string)($h['folderName'] ?? ''),
            'package_flags' => self::u32Hex((int)($h['packageFlags'] ?? 0)),
            'name_count' => (int)($h['nameCount'] ?? 0),
            'name_offset' => (int)($h['nameOffset'] ?? 0),
            'export_count' => (int)($h['exportCount'] ?? 0),
            'export_offset' => (int)($h['exportOffset'] ?? 0),
            'import_count' => (int)($h['importCount'] ?? 0),
            'import_offset' => (int)($h['importOffset'] ?? 0),
            'depends_offset' => (int)($h['dependsOffset'] ?? 0),
            'guid' => (string)($h['guid'] ?? ''),
            'generations' => array_values((array)($h['generations'] ?? [])),
            'engine_version' => (int)($h['engineVersion'] ?? 0),
            'cooked_content_version' => (int)($h['cookedContentVersion'] ?? 0),
            'compression_flags' => self::u32Hex((int)($h['compressionFlags'] ?? 0)),
            'compressed' => (bool)($h['compressed'] ?? false),
            'compressed_chunk_count' => count((array)($h['chunks'] ?? [])),
            'logical_decompressed' => (bool)($h['logicalDecompressed'] ?? false),
            'logical_size' => (int)($h['logicalSize'] ?? 0),
            'package_source' => self::u32Hex((int)($h['packageSource'] ?? 0)),
            'crosslevel_guid_fields_present' => false,
            'thumbnail_table_offset_present' => false,
            'additional_packages_to_cook_present' => false,
            'texture_allocations_present' => false,
            'fname_encoding' => 'int32-index-plus-int32-number',
            'package_index_encoding' => 'int32-le',
            'name_flags_serialized_width_bits' => 64,
            'object_flags_serialized_width_bits' => 64,
            'serial_size_serialized_width_bits' => 32,
            'serial_offset_serialized_width_bits' => 32,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function nameRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'text' => (string)($row['text'] ?? $row['name'] ?? ''),
            'flags' => self::u64Hex((int)($row['objectFlagsHigh'] ?? 0), (int)($row['flags'] ?? 0)),
            'flags_serialized_width_bits' => 64,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function importRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'package_index' => -((int)($row['index'] ?? -1) + 1),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'class_package' => self::fname((array)($row['ClassPackage'] ?? [])),
            'class_name' => self::fname((array)($row['ClassName'] ?? [])),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'object_name' => self::fname((array)($row['ObjectName'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function exportRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'package_index' => (int)($row['index'] ?? -1) + 1,
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'class_index' => (int)($row['classIndex'] ?? 0),
            'super_index' => (int)($row['superIndex'] ?? 0),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'object_name' => [
                'name_index' => (int)($row['nameIndex'] ?? -1),
                'number' => (int)($row['nameNumber'] ?? 0),
                'text' => (string)($row['objectNameText'] ?? ''),
            ],
            'archetype_index' => (int)($row['archetype'] ?? 0),
            'object_flags' => self::u64Hex(
                (int)($row['objectFlagsHigh'] ?? 0),
                (int)($row['objectFlagsLow'] ?? 0)
            ),
            'object_flags_serialized_width_bits' => 64,
            'serial_size' => (int)($row['serialSize'] ?? 0),
            'serial_offset' => (int)($row['serialOffset'] ?? 0),
            'component_map' => array_values((array)($row['componentMap'] ?? [])),
            'export_flags' => self::u32Hex((int)($row['exportFlags'] ?? 0)),
            'generation_net_object_count' => array_values((array)($row['netObjectCount'] ?? [])),
            'package_guid' => (string)($row['guid'] ?? ''),
            'package_flags' => self::u32Hex((int)($row['packageFlags'] ?? 0)),
        ];
    }

    /** @param list<array<string,mixed>> $chunks @return list<array<string,mixed>> */
    private static function compressionRows(array $chunks): array
    {
        $rows = [];
        foreach ($chunks as $index => $chunk) {
            $rows[] = [
                'index' => (int)$index,
                'uncompressed_offset' => (int)($chunk['uOff'] ?? 0),
                'uncompressed_size' => (int)($chunk['uLen'] ?? 0),
                'compressed_offset' => (int)($chunk['cOff'] ?? 0),
                'compressed_size' => (int)($chunk['cLen'] ?? 0),
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $row @return array{name_index:int,number:int,text:string} */
    private static function fname(array $row): array
    {
        return [
            'name_index' => (int)($row['index'] ?? -1),
            'number' => (int)($row['number'] ?? 0),
            'text' => (string)($row['text'] ?? ''),
        ];
    }

    private static function u32Hex(int $value): string
    {
        return strtoupper(sprintf('%08X', $value & 0xFFFFFFFF));
    }

    private static function u64Hex(int $high, int $low): string
    {
        return self::u32Hex($high) . self::u32Hex($low);
    }

    /** @param array<string,mixed> $header @param array<int,mixed> $names @param array<int,mixed> $imports @param array<int,mixed> $exports */
    private static function assertCounts(array $header, array $names, array $imports, array $exports): void
    {
        $expected = [
            'names' => (int)($header['nameCount'] ?? -1),
            'imports' => (int)($header['importCount'] ?? -1),
            'exports' => (int)($header['exportCount'] ?? -1),
        ];
        $actual = ['names'=>count($names),'imports'=>count($imports),'exports'=>count($exports)];
        if ($expected !== $actual) {
            throw new RuntimeException('UT3 reader table counts do not match summary: expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
        }
    }
}
