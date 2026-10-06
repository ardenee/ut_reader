<?php
/**
 * Builds source-shaped UEDB5 snapshots from authoritative UT99 package bytes.
 * Retail v1.400 proves package versions 60-68; UT99src-ext supplies v69 layout evidence.
 * Game-profile rules decide which package versions may be attempted. This builder preserves
 * source-specific serialization policy after the canonical reader has successfully parsed them.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

final class Uedb5Ut99SnapshotBuilder
{
    public const PACKAGE_FAMILY = 'classic-linkerload';
    public const POLICY_RETAIL = 'ue1-ut99-retail-v1400-1999-11-30';
    public const POLICY_SUPPLEMENTAL = 'ue1-ut99-v430-public-source-partial';
    public const POLICY_V69 = self::POLICY_SUPPLEMENTAL;
    public const POLICY_FORWARD_COMPAT = 'ue1-ut99-post-v69-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE1PackageReader $reader, array $file): array
    {
        $issues = $reader->validatePackage();
        if ($issues !== []) {
            throw new RuntimeException('Cannot persist UT99 UEDB5 metadata: ' . implode('; ', $issues));
        }
        $header = $reader->getHeader();
        $version = (int)($header['version'] ?? -1);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UT99 UEDB5 persistence requires positive file and game IDs.');
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
            'source_policy' => $version > 69
                ? self::POLICY_FORWARD_COMPAT
                : (($version <= 68 && (int)($header['licenseeVersion'] ?? 0) === 0)
                    ? self::POLICY_RETAIL : self::POLICY_SUPPLEMENTAL),
            'section_schemas' => self::sectionSchemas(),
            'sections' => [
                'summary' => [self::summaryRow($header)],
                'names' => array_map(self::nameRow(...), $names),
                'imports' => array_map(self::importRow(...), $imports),
                'exports' => array_map(self::exportRow(...), $exports),
            ],
        ];
    }
    /** @return array<string,string> */
    private static function sectionSchemas(): array
    {
        return [
            'summary' => 'ue1.ut99.package-summary.v1',
            'names' => 'ue1.ut99.name-entry.v1',
            'imports' => 'ue1.ut99.object-import.v1',
            'exports' => 'ue1.ut99.object-export.v1',
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
            'import_outer_index_encoding' => 'int32-le',
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
    private static function importRow(array $row): array
    {
        $index = (int)($row['index'] ?? -1);
        return [
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
                'UT99 reader table counts do not match the serialized summary: expected='
                . json_encode($expected) . ' actual=' . json_encode($actual)
            );
        }
    }
}
