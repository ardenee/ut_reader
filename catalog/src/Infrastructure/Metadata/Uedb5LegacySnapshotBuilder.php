<?php
/**
 * Shared source-shaped serializer for the UE1/UE2 classic package table layout.
 * Game wrappers remain responsible for source-policy and supported-version boundaries.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyPackageReaderBase;

final class Uedb5LegacySnapshotBuilder
{
    /**
     * @param array<string,mixed> $file
     * @param array{label:string,policy:string,schema_prefix:string} $contract
     * @return array<string,mixed>
     */
    public static function build(
        CatalogLegacyPackageReaderBase $reader,
        array $file,
        array $contract
    ): array {
        $label = trim((string)($contract['label'] ?? ''));
        $policy = trim((string)($contract['policy'] ?? ''));
        $prefix = trim((string)($contract['schema_prefix'] ?? ''));
        if ($label === '' || $policy === '' || $prefix === '') {
            throw new RuntimeException('Legacy UEDB5 source contract is incomplete.');
        }
        $issues = $reader->validatePackage();
        if ($issues !== []) {
            throw new RuntimeException(
                'Cannot persist ' . $label . ' UEDB5 metadata: ' . implode('; ', $issues)
            );
        }
        $header = $reader->getHeader();
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException($label . ' UEDB5 persistence requires positive file and game IDs.');
        }

        $names = $reader->getNames();
        $imports = $reader->getImports();
        $exports = $reader->getExports();
        self::assertCounts($label, $header, $names, $imports, $exports);

        $version = (int)($header['version'] ?? 0);
        $pre50Unreal = $prefix === 'ue1.unreal' && $version < 50;

        return [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => (string)($file['package_name'] ?? ''),
                'original_name' => (string)($file['original_name'] ?? ''),
            ],
            'package_family' => 'classic-linkerload',
            'source_policy' => $policy,
            'section_schemas' => [
                'summary' => $prefix . ($pre50Unreal ? '.package-summary.v2' : '.package-summary.v1'),
                'names' => $prefix . '.name-entry.v1',
                'imports' => $prefix . ($pre50Unreal ? '.object-import.v2' : '.object-import.v1'),
                'exports' => $prefix . '.object-export.v1',
            ],
            'sections' => [
                'summary' => [self::summaryRow($header)],
                'names' => array_map(self::nameRow(...), $names),
                'imports' => array_map(static fn(array $row): array => self::importRow($row, $version), $imports),
                'exports' => array_map(self::exportRow(...), $exports),
            ],
        ];
    }

    /** @param array<string,mixed> $header @return array<string,mixed> */
    private static function summaryRow(array $header): array
    {
        $version = (int)($header['version'] ?? 0);
        $pre68 = $version < 68;
        return [
            'serialized_tag' => self::u32Hex((int)($header['tag'] ?? 0)),
            'packed_file_version' => self::u32Hex((int)($header['packedVersion'] ?? 0)),
            'package_version' => $version,
            'licensee_version' => (int)($header['licenseeVersion'] ?? 0),
            'package_flags' => self::u32Hex((int)($header['packageFlags'] ?? 0)),
            'name_count' => (int)($header['nameCount'] ?? 0),
            'name_offset' => (int)($header['nameOffset'] ?? 0),
            'export_count' => (int)($header['exportCount'] ?? 0),
            'export_offset' => (int)($header['exportOffset'] ?? 0),
            'import_count' => (int)($header['importCount'] ?? 0),
            'import_offset' => (int)($header['importOffset'] ?? 0),
            'summary_layout' => $pre68 ? 'heritage-table' : 'guid-generations',
            'guid' => (string)($header['guid'] ?? ''),
            'guid_raw' => (string)($header['guidRaw'] ?? ''),
            'heritage_count_present' => $pre68,
            'heritage_count' => $pre68 ? (int)($header['heritageCount'] ?? 0) : null,
            'heritage_offset' => $pre68 ? (int)($header['heritageOffset'] ?? 0) : null,
            'generation_count_present' => !$pre68,
            'generation_count' => !$pre68 ? (int)($header['genCount'] ?? 0) : null,
            'generations' => array_values((array)($header['generations'] ?? [])),
            'name_entry_encoding' => $version < 64 ? 'ansi-z' : 'fstring-compact-length',
            'fname_index_encoding' => 'compact-index',
            'import_outer_index_encoding' => $version < 50 ? 'not-serialized' : 'int32-le',
            'import_object_package_encoding' => $version < 50 ? 'fname-compact-index' : 'not-serialized',
            'export_class_index_encoding' => 'compact-index',
            'export_super_index_encoding' => 'compact-index',
            'export_outer_index_encoding' => 'int32-le',
            'export_serial_size_encoding' => 'compact-index',
            'export_serial_offset_encoding' => 'compact-index-if-size-nonzero',
            'package_compression' => 'none',
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function nameRow(array $row): array
    {
        return [
            'index' => (int)($row['index'] ?? -1),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'text' => (string)($row['text'] ?? $row['name'] ?? ''),
            'flags' => self::u32Hex((int)($row['flags'] ?? 0)),
            'flags_serialized_width_bits' => 32,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function importRow(array $row, int $version): array
    {
        $index = (int)($row['index'] ?? -1);
        $result = [
            'index' => $index,
            'package_index' => -($index + 1),
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'class_package' => self::fname(
                (int)($row['classPackage'] ?? -1),
                (string)($row['classPackageText'] ?? '')
            ),
            'class_name' => self::fname(
                (int)($row['className'] ?? -1),
                (string)($row['classNameText'] ?? '')
            ),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'outer_index_serialized_width_bits' => 32,
            'object_name' => self::fname(
                (int)($row['objectName'] ?? -1),
                (string)($row['objectNameText'] ?? '')
            ),
        ];
        if ($version < 50) {
            $result['outer_index_serialized'] = false;
            $result['object_package_present'] = true;
            $result['object_package'] = self::fname(
                (int)($row['objectPackage'] ?? -1),
                (string)($row['objectPackageText'] ?? '')
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function exportRow(array $row): array
    {
        $index = (int)($row['index'] ?? -1);
        $serialSize = (int)($row['serialSize'] ?? 0);
        return [
            'index' => $index,
            'package_index' => $index + 1,
            'serialized_offset' => (int)($row['offset'] ?? -1),
            'class_index' => (int)($row['classIndex'] ?? 0),
            'super_index' => (int)($row['superIndex'] ?? 0),
            'outer_index' => (int)($row['outerIndex'] ?? 0),
            'outer_index_serialized_width_bits' => 32,
            'object_name' => self::fname(
                (int)($row['objectName'] ?? -1),
                (string)($row['objectNameText'] ?? '')
            ),
            'object_flags' => self::u32Hex((int)($row['objectFlags'] ?? 0)),
            'object_flags_serialized_width_bits' => 32,
            'serial_size' => $serialSize,
            'serial_size_encoding' => 'compact-index',
            'serial_offset_present' => $serialSize !== 0,
            'serial_offset' => $serialSize !== 0 ? (int)($row['serialOffset'] ?? 0) : null,
            'serial_offset_encoding' => $serialSize !== 0 ? 'compact-index' : null,
        ];
    }

    /** @return array{name_index:int,number:int,text:string} */
    private static function fname(int $index, string $text): array
    {
        return ['name_index' => $index, 'number' => 0, 'text' => $text];
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
    private static function assertCounts(
        string $label,
        array $header,
        array $names,
        array $imports,
        array $exports
    ): void {
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
                $label . ' reader table counts do not match the serialized summary: expected='
                . json_encode($expected) . ' actual=' . json_encode($actual)
            );
        }
    }
}
