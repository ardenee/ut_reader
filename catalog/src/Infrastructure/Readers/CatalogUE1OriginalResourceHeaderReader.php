<?php
/**
 * Original 1996 Unreal resource headers, NOT later FPackageFileSummary exports.
 * Epic v0.84a Src/Inc/UnResTyp.h:413-429: common UResource header
 * (32-bit native virtual-table pointer, EResourceType, Name[32],
 * Data pointer, Index, VersionNumber, FileNum, Flags,
 * FileDataOffset, FileDataSize, FileCRC, Unused1).
 * Epic Src/Engine/UnRes.cpp:1027-1078,1452-1493 copies Type->HeaderSize
 * bytes per export and dereferences the common fields.
 *
 * Pointer words saved in these structures are process addresses and are
 * intentionally NOT treated as valid references in UnrealDB.
 * Class-specific extension headers, reference fixups and object payloads
 * are outside this metadata decoder.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalResourceHeaderReader
{
    private const COMMON_HEADER_BYTES = 76; // 32-bit MSVC, vtable word + members

    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        $metadata = CatalogUE1OriginalResourceTableReader::read($path);
        $offsets = $metadata['summary']['table_offsets'];
        $offset = (int)$offsets['headers'];
        $headerLength = (int)$offsets['headers_length'];
        $trailer = (int)(\UnrealDb\Catalog\Domain\Package\CatalogUE1OriginalResourceFormat::identify($path)['trailer_offset'] ?? 0);
        $size = (int)(@filesize($path) ?: 0);
        if ($offset > $trailer || $headerLength > $trailer - $offset) {
            throw new RuntimeException('Original Unreal export headers exceed native trailer bounds.');
        }
        $exports = $metadata['exports'];
        $h = @fopen($path, 'rb');
        if (!is_resource($h)) {
            throw new RuntimeException('Cannot open original Unreal resource header buffer.');
        }
        try {
            if (fseek($h, $offset, SEEK_SET) !== 0) {
                throw new RuntimeException('Cannot seek to original Unreal resource headers.');
            }
            $position = 0;
            $decoded = [];
            foreach ($exports as $export) {
                $length = (int)$export['resource_header_size'];
                if ($length < self::COMMON_HEADER_BYTES || $length > $headerLength - $position) {
                    throw new RuntimeException('Original Unreal exported resource ' . $export['index']
                        . ' has an invalid source-defined header size.');
                }
                $recordOffset = $offset + $position;
                $bytes = self::exact($h, $length);
                $nameBytes = substr($bytes, 8, 32);
                $name = \UnrealDb\Catalog\Domain\Package\CatalogUE1AnsiName::fromFixedBytes($nameBytes);
                $common = unpack(
                    'VresourceType/VdataPointer/Vindex/VresourceVersion/VfileNumber/Vflags/'
                    . 'VdataOffset/VdataSize/Vcrc/Vunused',
                    substr($bytes, 4, 4) . substr($bytes, 40, 36)
                );
                // The two pointer words (vtable at offset 0 and Data at
                // offset 40) are never surfaced as persistent references.
                $type = (int)$common['resourceType'];
                $dataOffset = (int)$common['dataOffset'];
                $dataSize = (int)$common['dataSize'];
                if ($dataSize > 0 && ($dataOffset > $size
                    || $dataSize > $size - $dataOffset)) {
                    throw new RuntimeException('Original Unreal export ' . $export['index']
                        . ' resource data exceeds the physical file.');
                }
                $decoded[] = [
                    'export_index' => $export['index'],
                    'serialized_offset' => $recordOffset,
                    'serialized_length' => $length,
                    'resource_name' => $name,
                    'name_matches_table' => strcasecmp($name, (string)$export['name']) === 0,
                    'resource_type' => $type,
                    'type_matches_table' => $type === (int)$export['resource_type'],
                    'resource_index' => (int)$common['index'],
                    'resource_version' => (int)$common['resourceVersion'],
                    'source_file_number' => (int)$common['fileNumber'],
                    'flags' => (int)$common['flags'],
                    'file_data_offset' => $dataOffset,
                    'file_data_size' => $dataSize,
                    'file_crc' => (int)$common['crc'],
                    'derived_header_bytes' => $length - self::COMMON_HEADER_BYTES,
                ];
                $position += $length;
            }
            $metadata['resource_headers'] = $decoded;
            $metadata['resource_headers_decoded'] = true;
            $metadata['unconsumed_header_bytes'] = $headerLength - $position;
            return $metadata;
        } finally {
            fclose($h);
        }
    }

    private static function exact($h, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($h, $length - strlen($bytes));
            if (!is_string($part) || $part === '') {
                throw new RuntimeException('Truncated native Unreal resource header.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }
}
