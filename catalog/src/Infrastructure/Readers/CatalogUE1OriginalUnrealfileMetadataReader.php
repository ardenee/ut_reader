<?php
/**
 * Epic Unreal v0.86x/v0.867 FUnrealfileSummary + FNameEntry metadata reader.
 * Original sources:
 *  Src/Inc/UnLinker.h:258-310 and 469-497;
 *  Src/Inc/UnArc.h:56-69 (variable-length FArchive::String);
 *  Src/Inc/UnName.h:19,39-55 (NAME_SIZE=32, string then DWORD flags).
 *
 * This does not claim to deserialize UObjectBase records or object payloads.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileMetadataReader
{
    private const TAG = "Unrealfile\x1A";
    private const SUMMARY_BASE = 56; // fixed tag[32], six little-endian DWORDs
    private const MAX_DEPENDENCIES = 16;
    private const NAME_SIZE = 32;

    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (!is_resource($fh)) {
            throw new RuntimeException('Could not open original Unrealfile.');
        }
        try {
            $info = fstat($fh);
            $size = (int)($info['size'] ?? 0);
            if ($size < self::SUMMARY_BASE) {
                throw new RuntimeException('Original Unrealfile summary is truncated.');
            }
            $base = self::exact($fh, self::SUMMARY_BASE);
            if (!str_starts_with(substr($base, 0, 32), self::TAG . "\0")) {
                throw new RuntimeException('Not the original fixed-tag Unrealfile format.');
            }
            $fields = unpack('Vversion/Vobjects/Vnames/VnamesOffset/VobjectsOffset/VdependencyCount',
                substr($base, 32, 24));
            if (!is_array($fields)) {
                throw new RuntimeException('Cannot decode original Unrealfile summary.');
            }
            $version = (int)$fields['version'];
            // Epic v0.867 uses UNREAL_FILE_VERSION=23, UNREAL_MIN_VERSION=22.
            // Older versions trigger an interactive compatibility warning.
            if ($version < 22 || $version > 23) {
                throw new RuntimeException('Original Unrealfile version ' . $version
                    . ' is outside the source-backed v22/v23 reader contract.');
            }
            $objects = (int)$fields['objects'];
            $names = (int)$fields['names'];
            $deps = (int)$fields['dependencyCount'];
            if ($deps > self::MAX_DEPENDENCIES || $objects > $size
                || $names > intdiv($size, 5)) {
                throw new RuntimeException('Original Unrealfile counts are outside source-defined bounds.');
            }
            $namesOffset = (int)$fields['namesOffset'];
            $objectsOffset = (int)$fields['objectsOffset'];
            if (($names > 0 && ($namesOffset > $size || $namesOffset < self::SUMMARY_BASE))
                || ($objects > 0 && ($objectsOffset > $size || $objectsOffset < self::SUMMARY_BASE))) {
                throw new RuntimeException('Original Unrealfile name/object table offset exceeds file bounds.');
            }

            $dependencies = [];
            for ($i = 0; $i < $deps; $i++) {
                $dependencies[] = self::string($fh, self::NAME_SIZE);
            }
            $summaryEnd = ftell($fh);
            if ($summaryEnd === false) {
                throw new RuntimeException('Cannot track Unrealfile summary position.');
            }

            $entries = [];
            if ($names > 0) {
                if ($namesOffset < $summaryEnd || fseek($fh, $namesOffset, SEEK_SET) !== 0
                    || $names > intdiv($size - $namesOffset, 5)) {
                    throw new RuntimeException('Original Unrealfile name table is not readable.');
                }
                for ($i = 0; $i < $names; $i++) {
                    $offset = ftell($fh);
                    $name = self::string($fh, self::NAME_SIZE);
                    $flags = unpack('Vflags', self::exact($fh, 4));
                    $entries[] = [
                        'index' => $i,
                        'serialized_offset' => $offset,
                        'name' => $name,
                        'flags' => (int)$flags['flags'],
                    ];
                }
            }

            return [
                'format' => 'early_unrealfile',
                'version' => $version,
                'metadata_decoded' => true,
                'object_bases_decoded' => false,
                'object_payload_decoded' => false,
                'summary' => [
                    'version' => $version,
                    'object_count' => $objects,
                    'name_count' => $names,
                    'names_offset' => $namesOffset,
                    'objects_offset' => $objectsOffset,
                    'dependency_count' => $deps,
                    'serialized_summary_end' => $summaryEnd,
                ],
                'dependencies' => $dependencies,
                'names' => $entries,
            ];
        } finally {
            fclose($fh);
        }
    }

    /**
     * FArchive::String reads up to MaxLength-1 serialized bytes.
     * A zero terminates early; exactly 31 bytes has NO serialized NUL.
     */
    private static function string($fh, int $maxLength): string
    {
        $name = '';
        for ($i = 0; $i < $maxLength - 1; $i++) {
            $c = self::exact($fh, 1);
            if ($c === "\0") {
                break;
            }
            $name .= $c;
        }
        return \UnrealDb\Catalog\Domain\Package\CatalogUE1AnsiName::fromBytes($name);
    }

    private static function exact($fh, int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $part = fread($fh, $length - strlen($out));
            if (!is_string($part) || $part === '') {
                throw new RuntimeException('Truncated source-defined Unrealfile field.');
            }
            $out .= $part;
        }
        return $out;
    }
}
