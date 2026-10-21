<?php
/**
 * Native UE1 v0.867 Unrealfile USound and UMusic serializer contract.
 *
 * Src/Inc/UnSound.h SerializeHeader: UObject::SerializeHeader followed
 * by INT DataSize and FName FamilyName (WORD).
 * Src/Inc/UnMusic.h SerializeHeader: UObject header followed by INT DataSize.
 * Both SerializeData implementations call Ar.Serialize(GetData(), DataSize)
 * exactly. In-memory SoundID and audio decoding are not serialized here.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileAudioReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, bool $strict = false): array
    {
        $meta = CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stacks = CatalogUE1OriginalUnrealfileStackReader::read($path, false)['stack_prefixes'];
        $byIndex = [];
        foreach ($stacks as $stack) $byIndex[(int)$stack['object_index']] = $stack;
        $names = $meta['names'];
        $out = [];
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) throw new RuntimeException('Cannot open original Unrealfile audio resource');
        try {
            foreach ($meta['object_bases'] as $object) {
                if ($object['kind'] !== 'export') continue;
                $class = strtolower((string)$object['class_name']);
                if ($class !== 'sound' && $class !== 'music') continue;
                $isSound = $class === 'sound';
                $memberSize = $isSound ? 6 : 4;
                $idx = (int)$object['index'];
                $stack = $byIndex[$idx] ?? null;
                $row = [
                    'object_index' => $idx, 'object_name' => $object['name'],
                    'native_class' => $isSound ? 'USound' : 'UMusic',
                    'status' => 'incomplete_native_audio_header',
                    'audio_payload_decoded' => false,
                    'runtime_audio_driver_loaded' => false,
                ];
                if (($stack['status'] ?? null) !== 'native_stack_prefix_decoded'
                    || (int)$stack['class_member_bytes_remaining'] < $memberSize) {
                    if ($strict) throw new RuntimeException('Original audio class header is truncated');
                    $out[] = $row;
                    continue;
                }
                $offset = (int)$object['file_header_offset'] + (int)$stack['serialized_prefix_bytes'];
                if (fseek($fh, $offset, SEEK_SET) !== 0)
                    throw new RuntimeException('Cannot seek original audio header fields');
                $data = fread($fh, $memberSize);
                if (!is_string($data) || strlen($data) !== $memberSize)
                    throw new RuntimeException('Short original audio header fields');
                $size = (int)unpack('V', substr($data, 0, 4))[1];
                if ($size > 0x7fffffff || $size !== (int)$object['file_data_size'])
                    throw new RuntimeException('Original audio DataSize does not match SerializeData byte count');
                $familyIdx = $isSound ? (int)unpack('v', substr($data, 4, 2))[1] : null;
                if ($familyIdx !== null && $familyIdx > count($names))
                    throw new RuntimeException('USound FamilyName exceeds original one-based NameMap');
                $left = (int)$stack['class_member_bytes_remaining'] - $memberSize;
                if ($strict && $left !== 0)
                    throw new RuntimeException('Unexpected additional native audio header bytes');
                $out[] = [
                    'object_index' => $idx, 'object_name' => $object['name'],
                    'native_class' => $isSound ? 'USound' : 'UMusic',
                    'status' => $left === 0 ? 'native_audio_serializer_decoded' : 'native_audio_header_has_extension',
                    'data_size' => $size,
                    'data_offset' => (int)$object['file_data_offset'],
                    'data_sha256' => self::digest($fh, (int)$object['file_data_offset'], $size),
                    'family_name_ref' => $familyIdx,
                    'family_name' => $familyIdx === null || $familyIdx === 0 ? null : (string)$names[$familyIdx-1]['name'],
                    'serialized_header_member_bytes' => $memberSize,
                    'unconsumed_class_member_bytes' => $left,
                    'audio_payload_decoded' => false,
                    'runtime_audio_driver_loaded' => false,
                ];
            }
        } finally { fclose($fh); }
        return [
            'source_format' => 'early_unrealfile',
            'audio_records' => $out,
            'audio_payload_decoded' => false,
            'runtime_audio_driver_loaded' => false,
        ];
    }

    /** Hash the exact archive payload without treating it as decoded media. */
    private static function digest($fp, int $offset, int $length): string
    {
        if ($length < 0 || fseek($fp, $offset, SEEK_SET) !== 0)
            throw new RuntimeException('Invalid native audio archive span');
        $ctx = hash_init('sha256');
        while ($length > 0) {
            $block = fread($fp, min(65536, $length));
            if (!is_string($block) || $block === '') throw new RuntimeException('Truncated native audio archive payload');
            hash_update($ctx, $block);
            $length -= strlen($block);
        }
        return hash_final($ctx);
    }
}
