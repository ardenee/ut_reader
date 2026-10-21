<?php
/**
 * Identification of Epic's original Unreal v0.82/v0.83/v0.84a resource files.
 * NOT the later "Unrealfile" or PACKAGE_FILE_TAG format.
 *
 * Native source (each 1996 game tree):
 *   Src/Inc/UnBuild.h: RES_FILE_TAG, RES_FILE_VERSION (9 or 10)
 *   Src/Inc/UnResTyp.h: FResourceFileTrailer, TRAILER_MARK
 *   Src/Engine/UnRes.cpp: FGlobalResourceManager::AddFile/SaveFile
 *
 * The writer puts a NUL-terminated resource tag at the start, and a
 * fixed 164-byte trailer at EOF after an informational [SUMMARY] marker.
 * The loader reads only the fixed trailer from EOF, WITHOUT verifying the
 * informational marker or duplicated trailer tag. Do not add those checks
 * as false compatibility restrictions. These are not classic packages.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Package;

final class CatalogUE1OriginalResourceFormat
{
    private const TAG = "Unreal Resource\x1A";
    private const MARKER = '[SUMMARY]';
    private const TRAILER_SIZE = 164; // DWORD[9] + Pad[16] + DeltaFname[80] + Tag[32]

    /**
     * @return array<string,mixed>|null Null only when there is no original
     *         Unreal resource-file signature at the beginning of the file.
     */
    public static function identify(string $path, ?string $prefix = null): ?array
    {
        if ($prefix === null) {
            $prefix = @file_get_contents($path, false, null, 0, 20);
        }
        if (!is_string($prefix) || !str_starts_with($prefix, self::TAG . "\0")) {
            return null;
        }

        $base = [
            'ok' => false,
            'format' => 'early_unreal_resource',
            'engine_hint' => 'UE1',
            'licensee' => null,
            'version' => null,
        ];
        $malformed = static fn(string $reason): array => $base + [
            'reason' => $reason,
            'error_code' => 'unreal.early_resource_malformed',
            'error_arguments' => ['format' => 'early_unreal_resource'],
        ];
        clearstatcache(true, $path);
        $size = @filesize($path);
        $minimum = max(strlen(self::TAG) + 1, self::TRAILER_SIZE);
        if (!is_int($size) || $size < $minimum) {
            return $malformed('Original Unreal resource file is truncated before its EOF summary.');
        }
        $offset = $size - self::TRAILER_SIZE;
        $trailer = @file_get_contents($path, false, null, $offset, self::TRAILER_SIZE);
        if (!is_string($trailer) || strlen($trailer) !== self::TRAILER_SIZE) {
            return $malformed('Original Unreal resource file has no readable EOF trailer.');
        }
        // The writer emits these hints; the original AddFile reader ignores
        // both, so they are informative only and NEVER block identification.
        $marker = $offset >= strlen(self::MARKER)
            ? @file_get_contents($path, false, null, $offset - strlen(self::MARKER), strlen(self::MARKER))
            : false;
        $markerPresent = $marker === self::MARKER;
        $trailerTagMatches = str_starts_with(substr($trailer, 132, 32), self::TAG . "\0");
        $fields = unpack(
            'Vversion/Vexports/Vimports/Vnames/VnamesOffset/VimportsOffset/'
            . 'VexportsOffset/VheadersOffset/VheadersLength',
            substr($trailer, 0, 36)
        );
        if (!is_array($fields)) {
            return $malformed('Original Unreal resource trailer cannot be decoded.');
        }
        $version = (int)$fields['version'];
        $counts = [
            'names' => (int)$fields['names'],
            'imports' => (int)$fields['imports'],
            'exports' => (int)$fields['exports'],
        ];
        return array_merge($base, [
            'version' => $version,
            'reason' => 'Original Unreal resource format version ' . $version
                . ' requires its original resource-table reader.',
            'error_code' => 'unreal.early_resource_unsupported',
            'error_arguments' => [
                'format' => 'early_unreal_resource',
                'version' => $version,
                'source_versions' => [9, 10],
            ],
            'trailer_offset' => $offset,
            'writer_marker_present' => $markerPresent,
            'writer_trailer_tag_present' => $trailerTagMatches,
            'counts' => $counts,
            'table_offsets' => [
                'names' => (int)$fields['namesOffset'],
                'imports' => (int)$fields['importsOffset'],
                'exports' => (int)$fields['exportsOffset'],
                'headers' => (int)$fields['headersOffset'],
                'headers_length' => (int)$fields['headersLength'],
            ],
        ]);
    }
}
