<?php
/**
 * Original UE1 v9/v10 native UFont layout, 32-bit MSVC.
 *
 * Epic v0.84a Src/Inc/UnRes.h: FFontCharacter (4 INTs), UFont:
 * UResource 76 + UDatabase Num/Max 8 + Texture pointer 4 = 88.
 * Src/Engine/UnGfx.cpp: UFont::Register assigns RecordSize=16;
 * QueryHeaderReferences asks the runtime resource registry to relink Texture.
 * That pointer cannot be resolved from the file alone.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalResourceFontReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, bool $strict = false): array
    {
        $meta = CatalogUE1OriginalResourceDatabaseReader::read($path, $strict);
        $headers = CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $fontRecords = [];
        $fp = @fopen($path, 'rb');
        if (!is_resource($fp)) {
            throw new RuntimeException('Cannot open original UFont resource');
        }
        try {
            foreach ($meta['database_headers'] as $idx => $db) {
                if ((int)$db['resource_type'] !== 6) continue;
                $h = $headers[$idx];
                $row = [
                    'export_index' => (int)$h['export_index'],
                    'native_type' => 'RES_Font',
                    'status' => 'incomplete_native_font_header',
                    'runtime_texture_reference_resolved' => false,
                ];
                if (($db['status'] ?? '') !== 'native_database_num_max_decoded'
                    || (int)$h['serialized_length'] < 88) {
                    if ($strict) throw new RuntimeException('1996 UFont lacks 88-byte source-defined header');
                    $fontRecords[] = $row;
                    continue;
                }
                $num = (int)$db['database_count'];
                $length = (int)$h['file_data_size'];
                if ($num > intdiv(PHP_INT_MAX, 16) || $num * 16 !== $length) {
                    throw new RuntimeException('UFont QueryMinSize differs from serialized glyph data size');
                }
                if (fseek($fp, (int)$h['serialized_offset'] + 84, SEEK_SET) !== 0) {
                    throw new RuntimeException('Cannot seek UFont native Texture pointer');
                }
                $word = fread($fp, 4);
                if (!is_string($word) || strlen($word) !== 4) {
                    throw new RuntimeException('Truncated UFont texture pointer');
                }
                $nativePointer = (int)unpack('V', $word)[1];
                if (fseek($fp, (int)$h['file_data_offset'], SEEK_SET) !== 0) {
                    throw new RuntimeException('Cannot seek native UFont characters');
                }
                $characters = [];
                for ($i = 0; $i < $num; $i++) {
                    $packed = fread($fp, 16);
                    if (!is_string($packed) || strlen($packed) !== 16) {
                        throw new RuntimeException('Short native FFontCharacter record');
                    }
                    $c = unpack('VStartU/VStartV/VUSize/VVSize', $packed);
                    $signed = static fn(int $v): int => $v >= 0x80000000 ? $v - 0x100000000 : $v;
                    $characters[] = [
                        'index' => $i, 'start_u' => $signed((int)$c['StartU']),
                        'start_v' => $signed((int)$c['StartV']),
                        'u_size' => $signed((int)$c['USize']),
                        'v_size' => $signed((int)$c['VSize']),
                    ];
                }
                $fontRecords[] = [
                    'export_index' => (int)$h['export_index'],
                    'native_type' => 'RES_Font', 'status' => 'native_font_records_decoded',
                    'glyph_count' => $num, 'glyph_capacity' => (int)$db['database_capacity'],
                    'record_bytes' => 16, 'serialized_data_bytes' => $length,
                    'texture_pointer_raw_word' => $nativePointer,
                    'texture_pointer_not_portable' => true,
                    'runtime_texture_reference_resolved' => false,
                    'glyphs' => $characters,
                    'class_header_extra_bytes' => (int)$h['serialized_length'] - 88,
                ];
            }
        } finally { fclose($fp); }
        return [
            'source_family' => 'early_unreal_resource',
            'font_records' => $fontRecords,
            'runtime_texture_references_resolved' => false,
        ];
    }
}
