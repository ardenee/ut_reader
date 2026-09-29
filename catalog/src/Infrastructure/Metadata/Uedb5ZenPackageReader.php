<?php
/**
 * Parses UE5 5.8.3 cooked Zen package headers reconstructed from IoStore ExportBundleData chunks.
 *
 * PackageImport identity is paired only with the authoritative ordered ImportedPackages
 * array supplied by the selected FFilePackageStoreEntry.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5ZenPackageReader
{
    public const PACKAGE_FAMILY = 'zen-iostore';
    public const SOURCE_POLICY = 'ue5-5.8.3-release-396c9f059903aed5fec78ecd3d437a40c6415368';

    public const SUMMARY_SIZE = 52;
    public const CELL_OFFSETS_SIZE = 8;
    public const EXPORT_MAP_ENTRY_SIZE = 72;
    public const CELL_EXPORT_MAP_ENTRY_SIZE = 40;
    public const EXPORT_BUNDLE_ENTRY_SIZE = 8;
    public const DEPENDENCY_BUNDLE_HEADER_SIZE = 20;
    public const DEPENDENCY_BUNDLE_ENTRY_SIZE = 4;
    public const BULK_DATA_MAP_ENTRY_SIZE = 32;

    private const UE5_DATA_RESOURCES = 1009;
    private const UE5_VERSE_CELLS = 1015;

    /** @param array<string,mixed> $storeEntry @return array<string,mixed> */
    public static function parse(string $bytes, string $packageId, array $storeEntry): array
    {
        $packageId = self::u64Hex($packageId, 'Zen package FPackageId');
        if (self::u64Hex((string)($storeEntry['package_id'] ?? ''), 'package-store FPackageId') !== $packageId) {
            throw new RuntimeException('Zen package FPackageId does not match the selected package-store entry.');
        }
        $importedPackageIds = [];
        foreach ((array)($storeEntry['imported_package_ids'] ?? []) as $id) {
            $importedPackageIds[] = self::u64Hex((string)$id, 'ImportedPackages FPackageId');
        }

        if (strlen($bytes) < self::SUMMARY_SIZE) {
            throw new RuntimeException('Zen package chunk is shorter than FZenPackageSummary.');
        }
        $reader = new Uedb5BinaryReader($bytes, 'Zen package chunk');
        $summary = self::readSummary($reader);
        $headerSize = (int)$summary['header_size'];
        if ($headerSize < self::SUMMARY_SIZE || $headerSize > strlen($bytes)) {
            throw new RuntimeException('Zen HeaderSize is outside the reconstructed package chunk.');
        }
        if (!in_array((int)$summary['has_versioning_info'], [0, 1], true)) {
            throw new RuntimeException('Zen bHasVersioningInfo is not a serialized boolean value.');
        }

        $versioning = null;
        if ((int)$summary['has_versioning_info'] === 1) {
            $versioning = self::readVersioningInfo($reader);
        }
        $packageVersion = $versioning === null ? null : (int)$versioning['package_version']['ue5'];

        $cellOffsets = [
            'cell_import_map_offset' => (int)$summary['export_bundle_entries_offset'],
            'cell_export_map_offset' => (int)$summary['export_bundle_entries_offset'],
            'serialized' => false,
        ];
        if ($versioning === null || $packageVersion >= self::UE5_VERSE_CELLS) {
            $cellOffsets = [
                'cell_import_map_offset' => $reader->i32le(),
                'cell_export_map_offset' => $reader->i32le(),
                'serialized' => true,
            ];
        }

        $nameMap = self::readNameBatch($reader, 'Zen package name map');
        $packageName = self::resolveMappedName((array)$summary['name'], $nameMap, 'Zen package summary Name');

        $bulkDataMap = [];
        $bulkDataPad = null;
        $bulkDataMapSize = null;
        $bulkDataMapOffset = null;
        if ($versioning === null || $packageVersion >= self::UE5_DATA_RESOURCES) {
            $bulkDataPadHex = $reader->u64HexLe();
            $bulkDataPad = Uedb5BinaryReader::unsignedHexLeToIntOrNull($bulkDataPadHex);
            if ($bulkDataPad === null || $bulkDataPad > 7) {
                throw new RuntimeException('Zen BulkDataPad is outside the source alignment range.');
            }
            $padBytes = $reader->read($bulkDataPad);
            if ($padBytes !== str_repeat("\0", $bulkDataPad)) {
                throw new RuntimeException('Zen BulkDataPad contains non-zero bytes.');
            }
            $bulkDataMapSize = $reader->i64le();
            if ($bulkDataMapSize < 0 || ($bulkDataMapSize % self::BULK_DATA_MAP_ENTRY_SIZE) !== 0) {
                throw new RuntimeException('Zen BulkDataMapSize is invalid.');
            }
            $bulkDataMapOffset = $reader->tell();
            if ($bulkDataMapOffset + $bulkDataMapSize > $headerSize) {
                throw new RuntimeException('Zen bulk-data map exceeds HeaderSize.');
            }
            $bulkReader = new Uedb5BinaryReader(
                substr($bytes, $bulkDataMapOffset, $bulkDataMapSize),
                'Zen bulk-data map'
            );
            for ($index = 0; $index < intdiv($bulkDataMapSize, self::BULK_DATA_MAP_ENTRY_SIZE); $index++) {
                $bulkDataMap[] = [
                    'index' => $index,
                    'serial_offset' => $bulkReader->i64le(),
                    'duplicate_serial_offset' => $bulkReader->i64le(),
                    'serial_size' => $bulkReader->i64le(),
                    'flags' => sprintf('%08X', $bulkReader->u32le()),
                    'cooked_index' => $bulkReader->u8(),
                    'pad' => strtoupper(bin2hex($bulkReader->read(3))),
                ];
            }
            $reader->seek($bulkDataMapOffset + $bulkDataMapSize);
        }

        self::validateOffsets($summary, $cellOffsets, $headerSize);

        $importedHashesOffset = (int)$summary['imported_public_export_hashes_offset'];
        if ($reader->tell() > $importedHashesOffset) {
            throw new RuntimeException('Zen variable header data overlaps ImportedPublicExportHashes.');
        }
        $prefixPadding = $reader->read($importedHashesOffset - $reader->tell());

        $importedPublicExportHashes = self::readU64HexSpan(
            $bytes,
            $importedHashesOffset,
            (int)$summary['import_map_offset'],
            'ImportedPublicExportHashes'
        );

        $importMap = self::readObjectIndexSpan(
            $bytes,
            (int)$summary['import_map_offset'],
            (int)$summary['export_map_offset'],
            'ImportMap',
            $importedPackageIds,
            $importedPublicExportHashes
        );

        $exportCount = self::spanCount(
            (int)$summary['export_map_offset'],
            (int)$cellOffsets['cell_import_map_offset'],
            self::EXPORT_MAP_ENTRY_SIZE,
            'ExportMap'
        );
        $cellImportCount = self::spanCount(
            (int)$cellOffsets['cell_import_map_offset'],
            (int)$cellOffsets['cell_export_map_offset'],
            8,
            'CellImportMap'
        );
        $cellExportCount = self::spanCount(
            (int)$cellOffsets['cell_export_map_offset'],
            (int)$summary['export_bundle_entries_offset'],
            self::CELL_EXPORT_MAP_ENTRY_SIZE,
            'CellExportMap'
        );
        $totalExportCount = $exportCount + $cellExportCount;

        $exports = self::readExportMap(
            $bytes,
            (int)$summary['export_map_offset'],
            $exportCount,
            $nameMap,
            $importedPackageIds,
            $importedPublicExportHashes,
            $totalExportCount
        );
        $cellImports = self::readObjectIndexSpan(
            $bytes,
            (int)$cellOffsets['cell_import_map_offset'],
            (int)$cellOffsets['cell_export_map_offset'],
            'CellImportMap',
            $importedPackageIds,
            $importedPublicExportHashes,
            $totalExportCount
        );
        $cellExports = self::readCellExportMap(
            $bytes,
            (int)$cellOffsets['cell_export_map_offset'],
            $cellExportCount,
            $nameMap
        );

        $exportBundles = self::readExportBundles(
            $bytes,
            (int)$summary['export_bundle_entries_offset'],
            (int)$summary['dependency_bundle_headers_offset'],
            $totalExportCount
        );
        $dependencyHeaders = self::readDependencyHeaders(
            $bytes,
            (int)$summary['dependency_bundle_headers_offset'],
            (int)$summary['dependency_bundle_entries_offset'],
            $totalExportCount
        );
        $dependencyEntries = self::readDependencyEntries(
            $bytes,
            (int)$summary['dependency_bundle_entries_offset'],
            (int)$summary['imported_package_names_offset'],
            $importMap,
            $cellImports,
            $totalExportCount
        );
        self::validateDependencyRanges($dependencyHeaders, count($dependencyEntries));

        $importedPackageNames = self::readImportedPackageNames(
            $bytes,
            (int)$summary['imported_package_names_offset'],
            $headerSize
        );
        if ($importedPackageNames !== [] && count($importedPackageNames) !== count($importedPackageIds)) {
            throw new RuntimeException('Zen ImportedPackageNames count does not match the package-store ImportedPackages count.');
        }

        return [
            'package_family' => self::PACKAGE_FAMILY,
            'source_policy' => self::SOURCE_POLICY,
            'package_id' => $packageId,
            'package_name' => $packageName,
            'summary' => $summary,
            'versioning_info' => $versioning,
            'cell_offsets' => $cellOffsets,
            'name_map' => $nameMap,
            'bulk_data_pad' => $bulkDataPad,
            'bulk_data_map_size' => $bulkDataMapSize,
            'bulk_data_map_offset' => $bulkDataMapOffset,
            'bulk_data_map' => $bulkDataMap,
            'pre_imported_hash_padding' => strtoupper(bin2hex($prefixPadding)),
            'imported_package_ids' => $importedPackageIds,
            'imported_public_export_hashes' => $importedPublicExportHashes,
            'import_map' => $importMap,
            'exports' => $exports,
            'cell_import_map' => $cellImports,
            'cell_exports' => $cellExports,
            'export_bundle_entries' => $exportBundles,
            'dependency_bundle_headers' => $dependencyHeaders,
            'dependency_bundle_entries' => $dependencyEntries,
            'imported_package_names' => $importedPackageNames,
            'header_bytes' => $headerSize,
            'exports_data_bytes' => strlen($bytes) - $headerSize,
            'store_entry' => $storeEntry,
        ];
    }

    /** @return array<string,mixed> */
    private static function readSummary(Uedb5BinaryReader $reader): array
    {
        $hasVersioningInfo = $reader->u32le();
        $headerSize = $reader->u32le();
        $name = self::readMappedNameRaw($reader);
        return [
            'has_versioning_info' => $hasVersioningInfo,
            'header_size' => $headerSize,
            'name' => $name,
            'package_flags' => sprintf('%08X', $reader->u32le()),
            'unused_cooked_header_size' => $reader->u32le(),
            'imported_public_export_hashes_offset' => $reader->i32le(),
            'import_map_offset' => $reader->i32le(),
            'export_map_offset' => $reader->i32le(),
            'export_bundle_entries_offset' => $reader->i32le(),
            'dependency_bundle_headers_offset' => $reader->i32le(),
            'dependency_bundle_entries_offset' => $reader->i32le(),
            'imported_package_names_offset' => $reader->i32le(),
        ];
    }

    /** @return array<string,mixed> */
    private static function readVersioningInfo(Uedb5BinaryReader $reader): array
    {
        $zenVersion = $reader->u32le();
        $ue4 = $reader->i32le();
        $ue5 = $reader->i32le();
        $licensee = $reader->i32le();
        $count = $reader->i32le();
        if ($count < 0 || $count > intdiv($reader->remaining(), 20)) {
            throw new RuntimeException('Zen custom-version count exceeds the package header.');
        }
        $customVersions = [];
        for ($index = 0; $index < $count; $index++) {
            $guidBytes = $reader->read(16);
            $words = array_values(unpack('V4', $guidBytes));
            $customVersions[] = [
                'index' => $index,
                'key_raw' => strtoupper(bin2hex($guidBytes)),
                'key_words' => array_map(static fn(int $word): string => sprintf('%08X', $word), $words),
                'version' => $reader->i32le(),
            ];
        }
        return [
            'zen_version' => $zenVersion,
            'package_version' => ['ue4' => $ue4, 'ue5' => $ue5],
            'licensee_version' => $licensee,
            'custom_versions' => $customVersions,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function readNameBatch(Uedb5BinaryReader $reader, string $label): array
    {
        $count = $reader->u32le();
        if ($count === 0) {
            return [];
        }
        $stringBytes = $reader->u32le();
        $hashVersion = $reader->u64HexLe();
        if ($count > intdiv($reader->remaining(), 10)) {
            throw new RuntimeException($label . ' count exceeds the available metadata.');
        }

        $hashes = [];
        for ($index = 0; $index < $count; $index++) {
            $hashes[] = $reader->u64HexLe();
        }
        $headers = [];
        for ($index = 0; $index < $count; $index++) {
            $first = $reader->u8();
            $second = $reader->u8();
            $headers[] = [
                'is_utf16' => ($first & 0x80) !== 0,
                'length' => (($first & 0x7f) << 8) | $second,
            ];
        }
        if ($stringBytes > $reader->remaining()) {
            throw new RuntimeException($label . ' string bytes exceed the available metadata.');
        }
        $strings = new Uedb5BinaryReader($reader->read($stringBytes), $label . ' strings');

        $names = [];
        foreach ($headers as $index => $header) {
            $byteLength = (int)$header['length'] * ((bool)$header['is_utf16'] ? 2 : 1);
            $raw = $strings->read($byteLength);
            $text = $raw;
            if ((bool)$header['is_utf16']) {
                $converted = function_exists('iconv') ? @iconv('UTF-16LE', 'UTF-8', $raw) : false;
                if (!is_string($converted)) {
                    throw new RuntimeException($label . ' UTF-16 name could not be decoded.');
                }
                $text = $converted;
            }
            $names[] = [
                'index' => $index,
                'hash_version' => $hashVersion,
                'hash' => $hashes[$index],
                'is_utf16' => (bool)$header['is_utf16'],
                'length' => (int)$header['length'],
                'raw' => strtoupper(bin2hex($raw)),
                'text' => $text,
            ];
        }
        if ($strings->remaining() !== 0) {
            throw new RuntimeException($label . ' lengths do not consume the declared string bytes.');
        }
        return $names;
    }

    /** @return array<string,mixed> */
    private static function readMappedNameRaw(Uedb5BinaryReader $reader): array
    {
        $rawIndex = $reader->u32le();
        $number = $reader->u32le();
        $decoded = Uedb5BinaryReader::decodeMappedNameIndex($rawIndex);
        return [
            'raw_index' => sprintf('%08X', $rawIndex),
            'type' => (int)$decoded['type'],
            'name_index' => (int)$decoded['index'],
            'number' => $number,
        ];
    }

    /** @param list<array<string,mixed>> $nameMap @return array<string,mixed> */
    private static function resolveMappedName(array $mapped, array $nameMap, string $label): array
    {
        if ((int)($mapped['type'] ?? -1) !== 0) {
            throw new RuntimeException($label . ' is not a package-name-map FMappedName.');
        }
        $index = (int)($mapped['name_index'] ?? -1);
        if (!isset($nameMap[$index])) {
            throw new RuntimeException($label . ' refers outside the Zen package name map.');
        }
        $base = (string)$nameMap[$index]['text'];
        $number = (int)($mapped['number'] ?? 0);
        return $mapped + [
            'base_text' => $base,
            'text' => $number > 0 ? $base . '_' . ($number - 1) : $base,
        ];
    }

    /** @return list<string> */
    private static function readU64HexSpan(string $bytes, int $start, int $end, string $label): array
    {
        $count = self::spanCount($start, $end, 8, $label);
        $reader = new Uedb5BinaryReader(substr($bytes, $start, $end - $start), 'Zen ' . $label);
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = $reader->u64HexLe();
        }
        return $rows;
    }

    /**
     * @param list<string> $importedPackageIds
     * @param list<string> $importedHashes
     * @return list<array<string,mixed>>
     */
    private static function readObjectIndexSpan(
        string $bytes,
        int $start,
        int $end,
        string $label,
        array $importedPackageIds,
        array $importedHashes,
        ?int $totalExportCount = null
    ): array {
        $count = self::spanCount($start, $end, 8, $label);
        $reader = new Uedb5BinaryReader(substr($bytes, $start, $end - $start), 'Zen ' . $label);
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = self::decodeObjectIndex(
                $reader->u64HexLe(),
                $label . '[' . $index . ']',
                $importedPackageIds,
                $importedHashes,
                $totalExportCount
            ) + ['index' => $index];
        }
        return $rows;
    }

    /**
     * @param list<string> $importedPackageIds
     * @param list<string> $importedHashes
     * @return array<string,mixed>
     */
    private static function decodeObjectIndex(
        string $rawHex,
        string $label,
        array $importedPackageIds,
        array $importedHashes,
        ?int $totalExportCount = null
    ): array {
        $rawHex = self::u64Hex($rawHex, $label);
        if ($rawHex === 'FFFFFFFFFFFFFFFF') {
            return [
                'raw_type_and_id' => $rawHex,
                'type' => 'Null',
                'type_tag' => 3,
                'value_u62' => '3FFFFFFFFFFFFFFF',
            ];
        }
        $high = (int)hexdec(substr($rawHex, 0, 8));
        $low = (int)hexdec(substr($rawHex, 8, 8));
        $type = ($high >> 30) & 0x3;
        $valueHigh = $high & 0x3fffffff;
        $valueHex = sprintf('%08X%08X', $valueHigh, $low);
        if ($type === 3) {
            throw new RuntimeException($label . ' uses the reserved FPackageObjectIndex type tag without the Null sentinel.');
        }

        $row = [
            'raw_type_and_id' => $rawHex,
            'type_tag' => $type,
            'type' => ['Export', 'ScriptImport', 'PackageImport'][$type],
            'value_u62' => $valueHex,
        ];
        if ($type === 0) {
            if ($valueHigh !== 0) {
                throw new RuntimeException($label . ' Export index exceeds the uint32 local-export representation.');
            }
            if ($totalExportCount !== null && $low >= $totalExportCount) {
                throw new RuntimeException($label . ' local Export index is outside the ordinary/cell export maps.');
            }
            $row['local_export_index'] = $low;
        } elseif ($type === 1) {
            $row['script_import_hash'] = $valueHex;
        } else {
            $packageIndex = $valueHigh;
            $hashIndex = $low;
            if (!isset($importedPackageIds[$packageIndex])) {
                throw new RuntimeException($label . ' ImportedPackageIndex is outside the selected package-store entry.');
            }
            if (!isset($importedHashes[$hashIndex])) {
                throw new RuntimeException($label . ' ImportedPublicExportHashIndex is outside the Zen header table.');
            }
            $row['imported_package_index'] = $packageIndex;
            $row['imported_public_export_hash_index'] = $hashIndex;
            $row['provider_package_id'] = $importedPackageIds[$packageIndex];
            $row['provider_public_export_hash'] = $importedHashes[$hashIndex];
        }
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $nameMap
     * @param list<string> $importedPackageIds
     * @param list<string> $importedHashes
     * @return list<array<string,mixed>>
     */
    private static function readExportMap(
        string $bytes,
        int $start,
        int $count,
        array $nameMap,
        array $importedPackageIds,
        array $importedHashes,
        int $totalExportCount
    ): array {
        $reader = new Uedb5BinaryReader(
            substr($bytes, $start, $count * self::EXPORT_MAP_ENTRY_SIZE),
            'Zen ExportMap'
        );
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $serialOffsetHex = $reader->u64HexLe();
            $serialSizeHex = $reader->u64HexLe();
            $mapped = self::resolveMappedName(self::readMappedNameRaw($reader), $nameMap, 'ExportMap[' . $index . '].ObjectName');
            $outer = self::decodeObjectIndex($reader->u64HexLe(), 'ExportMap[' . $index . '].OuterIndex', $importedPackageIds, $importedHashes, $totalExportCount);
            $class = self::decodeObjectIndex($reader->u64HexLe(), 'ExportMap[' . $index . '].ClassIndex', $importedPackageIds, $importedHashes, $totalExportCount);
            $super = self::decodeObjectIndex($reader->u64HexLe(), 'ExportMap[' . $index . '].SuperIndex', $importedPackageIds, $importedHashes, $totalExportCount);
            $template = self::decodeObjectIndex($reader->u64HexLe(), 'ExportMap[' . $index . '].TemplateIndex', $importedPackageIds, $importedHashes, $totalExportCount);
            $publicHash = $reader->u64HexLe();
            $objectFlags = $reader->u32le();
            $filterFlags = $reader->u8();
            $pad = $reader->read(3);
            $rows[] = [
                'index' => $index,
                'cooked_serial_offset_u64' => $serialOffsetHex,
                'cooked_serial_offset' => Uedb5BinaryReader::unsignedHexLeToIntOrNull($serialOffsetHex),
                'cooked_serial_size_u64' => $serialSizeHex,
                'cooked_serial_size' => Uedb5BinaryReader::unsignedHexLeToIntOrNull($serialSizeHex),
                'object_name' => $mapped,
                'outer_index' => $outer,
                'class_index' => $class,
                'super_index' => $super,
                'template_index' => $template,
                'public_export_hash' => $publicHash,
                'object_flags' => sprintf('%08X', $objectFlags),
                'filter_flags' => $filterFlags,
                'pad' => strtoupper(bin2hex($pad)),
            ];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $nameMap @return list<array<string,mixed>> */
    private static function readCellExportMap(string $bytes, int $start, int $count, array $nameMap): array
    {
        $reader = new Uedb5BinaryReader(
            substr($bytes, $start, $count * self::CELL_EXPORT_MAP_ENTRY_SIZE),
            'Zen CellExportMap'
        );
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $serialOffset = $reader->u64HexLe();
            $layoutSize = $reader->u64HexLe();
            $serialSize = $reader->u64HexLe();
            $cpp = self::resolveMappedName(self::readMappedNameRaw($reader), $nameMap, 'CellExportMap[' . $index . '].CppClassInfo');
            $rows[] = [
                'index' => $index,
                'cooked_serial_offset_u64' => $serialOffset,
                'cooked_serial_offset' => Uedb5BinaryReader::unsignedHexLeToIntOrNull($serialOffset),
                'cooked_serial_layout_size_u64' => $layoutSize,
                'cooked_serial_layout_size' => Uedb5BinaryReader::unsignedHexLeToIntOrNull($layoutSize),
                'cooked_serial_size_u64' => $serialSize,
                'cooked_serial_size' => Uedb5BinaryReader::unsignedHexLeToIntOrNull($serialSize),
                'cpp_class_info' => $cpp,
                'public_export_hash' => $reader->u64HexLe(),
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function readExportBundles(string $bytes, int $start, int $end, int $totalExportCount): array
    {
        $count = self::spanCount($start, $end, self::EXPORT_BUNDLE_ENTRY_SIZE, 'ExportBundleEntries');
        if ($count !== $totalExportCount * 2) {
            throw new RuntimeException('Zen ExportBundleEntries count is not exactly two entries per ordinary/cell export.');
        }
        $reader = new Uedb5BinaryReader(substr($bytes, $start, $end - $start), 'Zen ExportBundleEntries');
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $local = $reader->u32le();
            $command = $reader->u32le();
            if ($local >= $totalExportCount || $command > 1) {
                throw new RuntimeException('Zen ExportBundleEntry contains an invalid local export index or command type.');
            }
            $rows[] = [
                'index' => $index,
                'local_export_index' => $local,
                'command_type' => $command,
                'command' => $command === 0 ? 'Create' : 'Serialize',
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function readDependencyHeaders(string $bytes, int $start, int $end, int $totalExportCount): array
    {
        $count = self::spanCount($start, $end, self::DEPENDENCY_BUNDLE_HEADER_SIZE, 'DependencyBundleHeaders');
        if ($count !== $totalExportCount) {
            throw new RuntimeException('Zen DependencyBundleHeaders count does not match ordinary/cell export count.');
        }
        $reader = new Uedb5BinaryReader(substr($bytes, $start, $end - $start), 'Zen DependencyBundleHeaders');
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $first = $reader->i32le();
            $counts = [
                [$reader->u32le(), $reader->u32le()],
                [$reader->u32le(), $reader->u32le()],
            ];
            $rows[] = [
                'index' => $index,
                'first_entry_index' => $first,
                'entry_count' => $counts,
                'total_entry_count' => array_sum($counts[0]) + array_sum($counts[1]),
            ];
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $importMap
     * @param list<array<string,mixed>> $cellImportMap
     * @return list<array<string,mixed>>
     */
    private static function readDependencyEntries(
        string $bytes,
        int $start,
        int $end,
        array $importMap,
        array $cellImportMap,
        int $totalExportCount
    ): array {
        $count = self::spanCount($start, $end, self::DEPENDENCY_BUNDLE_ENTRY_SIZE, 'DependencyBundleEntries');
        $reader = new Uedb5BinaryReader(substr($bytes, $start, $end - $start), 'Zen DependencyBundleEntries');
        $rows = [];
        $combinedImports = array_merge($importMap, $cellImportMap);
        for ($index = 0; $index < $count; $index++) {
            $raw = $reader->i32le();
            $decoded = ['kind' => 'Null'];
            if ($raw > 0) {
                $exportIndex = $raw - 1;
                if ($exportIndex >= $totalExportCount) {
                    throw new RuntimeException('Zen dependency entry Export FPackageIndex is outside the export maps.');
                }
                $decoded = ['kind' => 'Export', 'local_export_index' => $exportIndex];
            } elseif ($raw < 0) {
                $importIndex = -$raw - 1;
                if (!isset($combinedImports[$importIndex])) {
                    throw new RuntimeException('Zen dependency entry Import FPackageIndex is outside the import maps.');
                }
                $decoded = [
                    'kind' => 'Import',
                    'combined_import_index' => $importIndex,
                    'object_index' => $combinedImports[$importIndex],
                ];
            }
            $rows[] = ['index' => $index, 'raw_package_index' => $raw] + $decoded;
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $headers */
    private static function validateDependencyRanges(array $headers, int $entryCount): void
    {
        foreach ($headers as $header) {
            $first = (int)$header['first_entry_index'];
            $count = (int)$header['total_entry_count'];
            if ($first === -1) {
                if ($count !== 0) {
                    throw new RuntimeException('Zen dependency bundle with FirstEntryIndex=-1 has non-zero entry counts.');
                }
                continue;
            }
            if ($first < 0 || $first + $count > $entryCount) {
                throw new RuntimeException('Zen dependency bundle range exceeds DependencyBundleEntries.');
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private static function readImportedPackageNames(string $bytes, int $start, int $end): array
    {
        if ($start === $end) {
            return [];
        }
        if ($start < 0 || $start > $end || $end > strlen($bytes)) {
            throw new RuntimeException('Zen ImportedPackageNames span is outside HeaderSize.');
        }
        $reader = new Uedb5BinaryReader(substr($bytes, $start, $end - $start), 'Zen ImportedPackageNames');
        $names = self::readNameBatch($reader, 'Zen ImportedPackageNames name batch');
        $result = [];
        foreach ($names as $index => $name) {
            if ($reader->remaining() < 4) {
                throw new RuntimeException('Zen ImportedPackageNames is missing an FName number.');
            }
            $number = $reader->i32le();
            $base = (string)$name['text'];
            $result[] = $name + [
                'number' => $number,
                'display_text' => $number > 0 ? $base . '_' . ($number - 1) : $base,
            ];
        }
        if ($reader->remaining() !== 0) {
            throw new RuntimeException('Zen ImportedPackageNames has unexpected trailing bytes.');
        }
        return $result;
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $cellOffsets */
    private static function validateOffsets(array $summary, array $cellOffsets, int $headerSize): void
    {
        $offsets = [
            'ImportedPublicExportHashesOffset' => (int)$summary['imported_public_export_hashes_offset'],
            'ImportMapOffset' => (int)$summary['import_map_offset'],
            'ExportMapOffset' => (int)$summary['export_map_offset'],
            'CellImportMapOffset' => (int)$cellOffsets['cell_import_map_offset'],
            'CellExportMapOffset' => (int)$cellOffsets['cell_export_map_offset'],
            'ExportBundleEntriesOffset' => (int)$summary['export_bundle_entries_offset'],
            'DependencyBundleHeadersOffset' => (int)$summary['dependency_bundle_headers_offset'],
            'DependencyBundleEntriesOffset' => (int)$summary['dependency_bundle_entries_offset'],
            'ImportedPackageNamesOffset' => (int)$summary['imported_package_names_offset'],
            'HeaderSize' => $headerSize,
        ];
        $previous = self::SUMMARY_SIZE;
        foreach ($offsets as $label => $offset) {
            if ($offset < $previous || $offset > $headerSize) {
                throw new RuntimeException('Zen ' . $label . ' is out of order or outside HeaderSize.');
            }
            $previous = $offset;
        }
    }

    private static function spanCount(int $start, int $end, int $recordSize, string $label): int
    {
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Zen ' . $label . ' span is invalid.');
        }
        $bytes = $end - $start;
        if (($bytes % $recordSize) !== 0) {
            throw new RuntimeException('Zen ' . $label . ' span is not aligned to its source record size.');
        }
        return intdiv($bytes, $recordSize);
    }

    private static function u64Hex(string $hex, string $label): string
    {
        $hex = strtoupper(trim($hex));
        if (preg_match('/^[0-9A-F]{16}$/', $hex) !== 1) {
            throw new RuntimeException($label . ' must be exactly 16 hexadecimal digits.');
        }
        return $hex;
    }
}
