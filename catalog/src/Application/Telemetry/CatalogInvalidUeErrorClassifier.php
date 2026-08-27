<?php
/**
 * Converts Unreal package validation failures into stable operator-facing
 * error codes, concise reasons and structured arguments.
 *
 * New readers should supply an explicit code/arguments. Text parsing is kept
 * only for legacy/historical reader messages so System Error grouping remains
 * stable while older jobs are still retained.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Telemetry;

final class CatalogInvalidUeErrorClassifier
{
    /**
     * @param array<string,mixed> $arguments
     * @return array{code:string,group:string,error_type:string,reason:string,arguments:array<string,mixed>}
     */
    public static function classify(
        string $message,
        string $explicitCode = '',
        array $arguments = []
    ): array {
        $reason = self::cleanReason($message);
        $code = self::normalizeCode($explicitCode);
        if ($code === '') {
            [$code, $parsed] = self::classifyLegacyText($reason);
            $arguments = array_replace($parsed, $arguments);
        }
        if ($code === '') {
            $code = 'unreal.invalid_package';
        }

        $arguments = self::normalizeArguments($arguments);
        $reason = self::renderReason($code, $arguments, $reason);
        $group = str_contains($code, '.')
            ? substr($code, 0, (int)strrpos($code, '.'))
            : 'unreal';

        return [
            'code' => $code,
            'group' => $group,
            'error_type' => 'InvalidUnrealPackage.' . $code,
            'reason' => $reason,
            'arguments' => $arguments,
        ];
    }

    public static function cleanReason(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/^(?:(?:RuntimeException|OutOfBoundsException|Exception):\s*)+/i', '', $message) ?? $message;
        $message = preg_replace('/^Invalid Unreal package\s+[^:]+:\s*/i', '', $message) ?? $message;
        $parts = preg_split('/\s+File:\s+|\s+PHP:\s+|\s+Package:\s+|\s+Trace:\s+/i', $message);
        $message = trim((string)($parts[0] ?? $message));
        return $message !== '' ? $message : 'Invalid Unreal package.';
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function classifyLegacyText(string $reason): array
    {
        $patterns = [
            'ue3.compressed_chunk_out_of_bounds' => '/Epic UE3 compressed chunk exceeds physical package size(?:\:\s*)?'
                . '.*?chunk=(\d+)'
                . '.*?compressed_offset=(\d+)'
                . '.*?compressed_size=(\d+)'
                . '.*?compressed_end=(\d+)'
                . '.*?physical_size=(\d+)'
                . '.*?uncompressed_offset=(\d+)'
                . '.*?uncompressed_size=(\d+)'
                . '.*?compression_flags=(0x[0-9A-Fa-f]+)'
                . '.*?chunk_count=(\d+)'
                . '.*?package_version=(\d+)'
                . '.*?licensee_version=(\d+)/i',
            'ue3.compressed_chunk_out_of_bounds_legacy' => '/Epic UE3 compressed chunk exceeds physical package size/i',
            'ue3.compressed_block_size_mismatch' => '/Epic UE3 compressed block size mismatch expected=(\d+) got=(\d+)/i',
            'ue3.compressed_chunk_size_mismatch' => '/Epic UE3 compressed chunk size mismatch expected=(\d+) got=(\d+)/i',
            'ue3.invalid_compressed_chunk_header' => '/Invalid Epic UE3 compressed chunk header expected=(\d+) actual=(\d+)/i',
            'ue3.invalid_compressed_chunk_tag' => '/Invalid Epic UE3 compressed chunk tag/i',
            'ue3.overlapping_compressed_chunks' => '/Overlapping Epic UE3 compressed chunk ranges are invalid/i',
            'ue3.invalid_first_compressed_chunk_offset' => '/Invalid first Epic UE3 compressed chunk offset/i',
            'ue3.compressed_block_out_of_bounds' => '/Epic UE3 compressed block exceeds chunk payload(?:\:\s*)?'
                . '.*?block=(\d+).*?compressed_size=(\d+).*?remaining=(\d+)/i',
            'ue3.read_range_out_of_bounds' => '/Epic UE3 read range exceeds physical package(?:\:\s*)?'
                . '.*?offset=(\d+).*?length=(\d+).*?end=(\d+).*?physical_size=(\d+)/i',
            'ue3.name_table_out_of_bounds' => '/Invalid Epic UE3 Name table count=(\d+) offset=(\d+) logicalSize=(\d+)/i',
            'ue3.import_table_out_of_bounds' => '/Invalid Epic UE3 Import table count=(\d+) offset=(\d+) logicalSize=(\d+)/i',
            'ue3.export_table_out_of_bounds' => '/Invalid Epic UE3 Export table count=(\d+) offset=(\d+) logicalSize=(\d+)/i',
            'ue3.fname_index_out_of_bounds' => '/Invalid Epic UE3 FName index=(-?\d+) for (.+?) nameCount=(\d+)/i',
            'ue3.export_serial_range_invalid' => '/Invalid Epic UE3 export serial range export=(\d+) size=(-?\d+) offset=(-?\d+)/i',
            'ue3.unsupported_compression' => '/Unsupported Epic UE3 compression flags=(0x[0-9A-Fa-f]+)/i',
            'ue3.zlib_decompression_failed' => '/Epic UE3 zlib decompression failed/i',
            'ue3.lzo_decompression_failed' => '/LZO (?:input overrun|literal input overrun|invalid match distance|output size mismatch)/i',
            'ue3.lzx_decompression_failed' => '/LZX (?:input overrun|invalid|output size mismatch|frame output mismatch)/i',
            'unreal.unsupported_reader' => '/(?:No supported package reader can be selected from serialized header data|serialized package header does not identify a supported engine reader)/i',
            'legacy.exports_table_out_of_bounds' => '/Invalid Exports table offset:\s*(\d+)\/(\d+)/i',
            'legacy.imports_table_out_of_bounds' => '/Invalid Imports table offset:\s*(\d+)\/(\d+)/i',
            'legacy.names_table_out_of_bounds' => '/Invalid Names table offset:\s*(\d+)\/(\d+)/i',
            'legacy.compact_index_invalid_length' => '/Invalid compact package index length/i',
            'legacy.fstring_invalid_wide_length' => '/Invalid legacy wide FString length:\s*(-?\d+)/i',
            'legacy.fstring_invalid_byte_length' => '/Invalid legacy FString byte length:\s*(-?\d+)/i',
            'unreal.magic_not_found' => '/(?:Unreal package magic not found|does not contain a supported Unreal package header)/i',
            'unreal.required_guid_missing' => '/package header is missing the required package GUID/i',
        ];

        foreach ($patterns as $code => $pattern) {
            if (preg_match($pattern, $reason, $match) !== 1) {
                continue;
            }
            return [$code === 'ue3.compressed_chunk_out_of_bounds_legacy'
                ? 'ue3.compressed_chunk_out_of_bounds'
                : $code, self::argumentsForMatch($code, $match)];
        }

        return ['unreal.invalid_package', []];
    }

    /**
     * @param array<int,string> $match
     * @return array<string,mixed>
     */
    private static function argumentsForMatch(string $code, array $match): array
    {
        return match ($code) {
            'ue3.compressed_chunk_out_of_bounds' => [
                'chunk_index' => (int)$match[1],
                'compressed_offset' => (int)$match[2],
                'compressed_size' => (int)$match[3],
                'compressed_end' => (int)$match[4],
                'physical_size' => (int)$match[5],
                'uncompressed_offset' => (int)$match[6],
                'uncompressed_size' => (int)$match[7],
                'compression_flags' => strtoupper($match[8]),
                'chunk_count' => (int)$match[9],
                'package_version' => (int)$match[10],
                'licensee_version' => (int)$match[11],
            ],
            'ue3.compressed_block_size_mismatch',
            'ue3.compressed_chunk_size_mismatch' => [
                'expected_size' => (int)$match[1],
                'actual_size' => (int)$match[2],
            ],
            'ue3.invalid_compressed_chunk_header' => [
                'expected_uncompressed_size' => (int)$match[1],
                'actual_uncompressed_size' => (int)$match[2],
            ],
            'ue3.compressed_block_out_of_bounds' => [
                'block_index' => (int)$match[1],
                'compressed_size' => (int)$match[2],
                'remaining' => (int)$match[3],
            ],
            'ue3.read_range_out_of_bounds' => [
                'offset' => (int)$match[1],
                'length' => (int)$match[2],
                'end' => (int)$match[3],
                'physical_size' => (int)$match[4],
            ],
            'ue3.name_table_out_of_bounds',
            'ue3.import_table_out_of_bounds',
            'ue3.export_table_out_of_bounds' => [
                'count' => (int)$match[1],
                'offset' => (int)$match[2],
                'logical_size' => (int)$match[3],
            ],
            'ue3.fname_index_out_of_bounds' => [
                'name_index' => (int)$match[1],
                'field' => trim((string)$match[2]),
                'name_count' => (int)$match[3],
            ],
            'ue3.export_serial_range_invalid' => [
                'export_index' => (int)$match[1],
                'serial_size' => (int)$match[2],
                'serial_offset' => (int)$match[3],
            ],
            'ue3.unsupported_compression' => [
                'compression_flags' => strtoupper($match[1]),
            ],
            'legacy.exports_table_out_of_bounds',
            'legacy.imports_table_out_of_bounds',
            'legacy.names_table_out_of_bounds' => [
                'table_offset' => (int)$match[1],
                'physical_size' => (int)$match[2],
            ],
            'legacy.fstring_invalid_wide_length',
            'legacy.fstring_invalid_byte_length' => [
                'serialized_length' => (int)$match[1],
            ],
            default => [],
        };
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private static function normalizeArguments(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]+/i', '_', trim((string)$key)) ?? '';
            $key = strtolower(trim($key, '_'));
            if ($key === '' || is_array($value) || is_object($value) || is_resource($value)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $out[$key] = $value;
                continue;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                $out[$key] = mb_strlen($text, 'UTF-8') > 500
                    ? mb_substr($text, 0, 500, 'UTF-8')
                    : $text;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private static function renderReason(string $code, array $arguments, string $fallback): string
    {
        return match ($code) {
            'ue3.compressed_chunk_out_of_bounds' => isset(
                $arguments['chunk_index'],
                $arguments['compressed_offset'],
                $arguments['compressed_size'],
                $arguments['compressed_end'],
                $arguments['physical_size']
            )
                ? 'UE3 compressed chunk ' . $arguments['chunk_index']
                    . ' is outside the physical package: compressed_offset=' . $arguments['compressed_offset']
                    . ', compressed_size=' . $arguments['compressed_size']
                    . ', compressed_end=' . $arguments['compressed_end']
                    . ', physical_size=' . $arguments['physical_size']
                    . (isset($arguments['uncompressed_offset']) ? ', uncompressed_offset=' . $arguments['uncompressed_offset'] : '')
                    . (isset($arguments['uncompressed_size']) ? ', uncompressed_size=' . $arguments['uncompressed_size'] : '')
                    . (isset($arguments['compression_flags']) ? ', compression_flags=' . $arguments['compression_flags'] : '')
                    . (isset($arguments['chunk_count']) ? ', chunk_count=' . $arguments['chunk_count'] : '')
                    . (isset($arguments['package_version']) ? ', package_version=' . $arguments['package_version'] : '')
                    . (isset($arguments['licensee_version']) ? ', licensee_version=' . $arguments['licensee_version'] : '')
                    . '.'
                : 'UE3 compressed chunk is outside the physical package.',
            'ue3.compressed_block_size_mismatch' => isset($arguments['expected_size'], $arguments['actual_size'])
                ? 'UE3 compressed block output size mismatch: expected=' . $arguments['expected_size']
                    . ', actual=' . $arguments['actual_size'] . '.'
                : $fallback,
            'ue3.compressed_chunk_size_mismatch' => isset($arguments['expected_size'], $arguments['actual_size'])
                ? 'UE3 compressed chunk output size mismatch: expected=' . $arguments['expected_size']
                    . ', actual=' . $arguments['actual_size'] . '.'
                : $fallback,
            'legacy.exports_table_out_of_bounds' => isset($arguments['table_offset'], $arguments['physical_size'])
                ? 'Exports table is outside the package: offset=' . $arguments['table_offset']
                    . ', physical_size=' . $arguments['physical_size'] . '.'
                : $fallback,
            'legacy.imports_table_out_of_bounds' => isset($arguments['table_offset'], $arguments['physical_size'])
                ? 'Imports table is outside the package: offset=' . $arguments['table_offset']
                    . ', physical_size=' . $arguments['physical_size'] . '.'
                : $fallback,
            'legacy.names_table_out_of_bounds' => isset($arguments['table_offset'], $arguments['physical_size'])
                ? 'Names table is outside the package: offset=' . $arguments['table_offset']
                    . ', physical_size=' . $arguments['physical_size'] . '.'
                : $fallback,
            default => self::appendArguments($fallback, $arguments),
        };
    }

    /** @param array<string,mixed> $arguments */
    private static function appendArguments(string $reason, array $arguments): string
    {
        if ($arguments === []) {
            return $reason;
        }
        $parts = [];
        foreach ($arguments as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $parts[] = $key . '=' . (string)$value;
        }
        return rtrim($reason, " .\t\r\n") . ': ' . implode(', ', $parts) . '.';
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9._-]+/', '_', $code) ?? '';
        return trim($code, '._-');
    }
}
