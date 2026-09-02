<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogPackageHeaderInspector` for catalog package header inspector.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Catalog;

/** Reads only the first MiB required for Unreal package-header inspection. */
final class CatalogPackageHeaderInspector
{
    /** @return array{ok:bool,error:string,summary:array<string,mixed>,rows:list<array<string,mixed>>} */
    public static function inspect(?string $path, array $file): array
    {
        if ($path === null || !is_file($path)) {
            return ['ok' => false, 'error' => 'Stored package file is not available on disk.', 'summary' => [], 'rows' => []];
        }
        $bytes = @file_get_contents($path, false, null, 0, 1048576);
        if ($bytes === false || strlen($bytes) < 40) {
            return ['ok' => false, 'error' => 'Stored package file is too small to parse header.', 'summary' => [], 'rows' => []];
        }
        $tag = self::u32($bytes, 0);
        if (!\UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedLittleEndianValue($tag)) {
            return ['ok' => false, 'error' => sprintf('Bad package tag 0x%08X', $tag), 'summary' => [], 'rows' => []];
        }
        $signed = self::i32($bytes, 4);
        $legacyVersion = self::u32($bytes, 4) & 0xFFFF;
        $knownLegacyVersion = ($legacyVersion >= 40 && $legacyVersion <= 199)
            || ($legacyVersion >= 334 && $legacyVersion <= 867);
        $extension = strtolower((string)($file['extension'] ?? ''));
        return (!$knownLegacyVersion && $signed < 0) || in_array($extension, ['uasset', 'umap'], true)
            ? self::inspectUe4($bytes, $file)
            : self::inspectLegacy($bytes);
    }

    /** @return array{ok:bool,error:string,summary:array<string,mixed>,rows:list<array<string,mixed>>} */
    private static function inspectLegacy(string $bytes): array
    {
        $rows = [];
        $offset = 0;
        $tag = self::u32Field($rows, $bytes, $offset, 'signature');
        $packed = self::u32($bytes, 4);
        $version = $packed & 0xFFFF;
        $licensee = ($packed >> 16) & 0xFFFF;
        self::row($rows, $bytes, 4, 4, 'packedVersionLicensee', 'uint32', 'packed=' . $packed . ', version=' . $version . ', licensee=' . $licensee);
        $offset = 8;
        $flags = self::u32Field($rows, $bytes, $offset, 'pkgFlags');
        $nameCount = self::i32Field($rows, $bytes, $offset, 'nameCount');
        $nameOffset = self::i32Field($rows, $bytes, $offset, 'nameOffset');
        $exportCount = self::i32Field($rows, $bytes, $offset, 'exportCount');
        $exportOffset = self::i32Field($rows, $bytes, $offset, 'exportOffset');
        $importCount = self::i32Field($rows, $bytes, $offset, 'importCount');
        $importOffset = self::i32Field($rows, $bytes, $offset, 'importOffset');
        $heritage = 'n/a';
        if ($version < 68 && self::has($bytes, $offset, 8)) {
            $heritageCount = self::i32Field($rows, $bytes, $offset, 'heritageCount');
            $heritageOffset = self::i32Field($rows, $bytes, $offset, 'heritageOffset');
            $heritage = $heritageCount . ' / ' . $heritageOffset;
        }
        $guid = self::has($bytes, $offset, 16) ? self::guidField($rows, $bytes, $offset) : '';
        $generations = null;
        if ($version >= 68 && self::has($bytes, $offset, 4)) {
            $generations = self::i32Field($rows, $bytes, $offset, 'generationCount');
        }
        $build = $version >= 500 ? 'UE3' : ($version >= 100 ? 'UE2' : ($version > 0 ? 'Unreal1' : 'unknown'));
        return [
            'ok' => true,
            'error' => '',
            'summary' => [
                'GUID' => $guid,
                'Version' => $version,
                'Licensee Version' => $licensee,
                'Signature' => sprintf('0x%08X', $tag),
                'Name Offset' => $nameOffset,
                'Import Offset' => $importOffset,
                'Export Offset' => $exportOffset,
                'Flags' => self::flags($flags),
                'Build' => $build,
                'Heritage' => $heritage,
                'Counts' => 'N ' . $nameCount . ' / I ' . $importCount . ' / E ' . $exportCount,
                'Generations' => $generations ?? 'n/a',
            ],
            'rows' => $rows,
        ];
    }

    /** @return array{ok:bool,error:string,summary:array<string,mixed>,rows:list<array<string,mixed>>} */
    private static function inspectUe4(string $bytes, array $file): array
    {
        $rows = [];
        $offset = 0;
        $tag = self::u32Field($rows, $bytes, $offset, 'signature');
        $legacy = self::i32Field($rows, $bytes, $offset, 'legacyFileVersion', 'signed 32-bit UE4 marker');
        $legacyUe3 = null;
        if ($legacy !== -4) {
            $legacyUe3 = self::i32Field($rows, $bytes, $offset, 'legacyUE3Version');
        }
        $rawVersion = self::i32Field($rows, $bytes, $offset, 'fileVersionUE4');
        $licensee = self::i32Field($rows, $bytes, $offset, 'fileVersionLicenseeUE4');
        if ($legacy <= -2 && self::has($bytes, $offset, 4)) {
            $customCount = max(0, min(self::i32Field($rows, $bytes, $offset, 'customVersionCount'), 4096));
            for ($index = 0; $index < $customCount; $index++) {
                if ($legacy === -2) {
                    self::i32Field($rows, $bytes, $offset, 'customVersion[' . $index . '].key');
                    self::i32Field($rows, $bytes, $offset, 'customVersion[' . $index . '].version');
                } elseif ($legacy >= -5) {
                    self::guidField($rows, $bytes, $offset, 'customVersion[' . $index . '].guid');
                    self::i32Field($rows, $bytes, $offset, 'customVersion[' . $index . '].version');
                    self::fstringField($rows, $bytes, $offset, 'customVersion[' . $index . '].friendlyName');
                } else {
                    self::guidField($rows, $bytes, $offset, 'customVersion[' . $index . '].guid');
                    self::i32Field($rows, $bytes, $offset, 'customVersion[' . $index . '].version');
                }
            }
        }
        $unversioned = $rawVersion === 0 && $licensee === 0;
        $version = $unversioned ? 511 : $rawVersion;
        $totalHeader = self::i32Field($rows, $bytes, $offset, 'totalHeaderSize');
        $folder = self::fstringField($rows, $bytes, $offset, 'folderName');
        $flags = self::u32Field($rows, $bytes, $offset, 'packageFlags');
        $nameCount = self::i32Field($rows, $bytes, $offset, 'nameCount');
        $nameOffset = self::i32Field($rows, $bytes, $offset, 'nameOffset');
        if ($version >= 459) {
            self::i32Field($rows, $bytes, $offset, 'gatherableTextDataCount');
            self::i32Field($rows, $bytes, $offset, 'gatherableTextDataOffset');
        }
        $exportCount = self::i32Field($rows, $bytes, $offset, 'exportCount');
        $exportOffset = self::i32Field($rows, $bytes, $offset, 'exportOffset');
        $importCount = self::i32Field($rows, $bytes, $offset, 'importCount');
        $importOffset = self::i32Field($rows, $bytes, $offset, 'importOffset');
        self::i32Field($rows, $bytes, $offset, 'dependsOffset');
        if ($version >= 384) {
            self::i32Field($rows, $bytes, $offset, 'stringAssetReferencesCount');
            self::i32Field($rows, $bytes, $offset, 'stringAssetReferencesOffset');
        }
        if ($version >= 510) {
            self::i32Field($rows, $bytes, $offset, 'searchableNamesOffset');
        }
        self::i32Field($rows, $bytes, $offset, 'thumbnailTableOffset');
        $guid = self::guidField($rows, $bytes, $offset);
        $generations = self::i32Field($rows, $bytes, $offset, 'generationCount');
        $counts = 'N ' . $nameCount . ' / I ' . $importCount . ' / E ' . $exportCount;
        $catalogCounts = 'N ' . (int)($file['name_count'] ?? 0) . ' / I ' . (int)($file['import_count'] ?? 0) . ' / E ' . (int)($file['export_count'] ?? 0);
        $summary = [
            'GUID' => $guid,
            'Version' => $unversioned ? '511 assumed (unversioned UE4; raw 0)' : $rawVersion,
            'Licensee Version' => $licensee,
            'Signature' => sprintf('0x%08X', $tag),
            'Name Offset' => $nameOffset,
            'Import Offset' => $importOffset,
            'Export Offset' => $exportOffset,
            'Flags' => self::flags($flags),
            'Build' => 'UE4',
            'Heritage' => $legacyUe3 === null ? 'n/a' : ('legacyUE3Version=' . $legacyUe3),
            'Counts' => $counts,
            'Generations' => $generations,
            'Total Header Size' => $totalHeader,
            'Folder Name' => $folder !== '' ? $folder : 'n/a',
        ];
        if ($counts !== $catalogCounts) {
            $summary['Catalog Counts'] = $catalogCounts;
        }
        return ['ok' => true, 'error' => '', 'summary' => $summary, 'rows' => $rows];
    }

    private static function u32(string $bytes, int $offset): int
    {
        if (!self::has($bytes, $offset, 4)) {
            return 0;
        }
        return (int)unpack('V', substr($bytes, $offset, 4))[1];
    }

    private static function i32(string $bytes, int $offset): int
    {
        $value = self::u32($bytes, $offset);
        return ($value & 0x80000000) !== 0 ? $value - 0x100000000 : $value;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function row(array &$rows, string $bytes, int $offset, int $size, string $field, string $type, string $value, string $note = ''): void
    {
        $rows[] = [
            'offset' => $offset,
            'size' => $size,
            'field' => $field,
            'type' => $type,
            'value' => $value,
            'hex' => strtoupper(trim(chunk_split(bin2hex(substr($bytes, $offset, max(0, $size))), 2, ' '))),
            'note' => $note,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function i32Field(array &$rows, string $bytes, int &$offset, string $field, string $note = ''): int
    {
        $start = $offset;
        $value = self::i32($bytes, $offset);
        self::row($rows, $bytes, $start, 4, $field, 'int32', (string)$value, $note);
        $offset += 4;
        return $value;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function u32Field(array &$rows, string $bytes, int &$offset, string $field, string $note = ''): int
    {
        $start = $offset;
        $value = self::u32($bytes, $offset);
        self::row($rows, $bytes, $start, 4, $field, 'uint32', (string)$value, $note !== '' ? $note : sprintf('0x%08X', $value));
        $offset += 4;
        return $value;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function guidField(array &$rows, string $bytes, int &$offset, string $field = 'guid'): string
    {
        $start = $offset;
        $guid = sprintf('%08X-%08X-%08X-%08X', self::u32($bytes, $offset), self::u32($bytes, $offset + 4), self::u32($bytes, $offset + 8), self::u32($bytes, $offset + 12));
        self::row($rows, $bytes, $start, 16, $field, 'FGuid', $guid);
        $offset += 16;
        return $guid;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function fstringField(array &$rows, string $bytes, int &$offset, string $field): string
    {
        $start = $offset;
        $length = self::i32($bytes, $offset);
        $offset += 4;
        if ($length === 0) {
            self::row($rows, $bytes, $start, 4, $field, 'FString', '', 'length=0');
            return '';
        }
        if ($length > 0) {
            $available = max(0, min($length, strlen($bytes) - $offset));
            $raw = substr($bytes, $offset, $available);
            $offset += $length;
            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }
            $value = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
            $value = $value === false ? $raw : $value;
            self::row($rows, $bytes, $start, 4 + $available, $field, 'FString', $value, 'length=' . $length);
            return $value;
        }
        $byteLength = (-$length) * 2;
        $available = max(0, min($byteLength, strlen($bytes) - $offset));
        $raw = substr($bytes, $offset, $available);
        $offset += $byteLength;
        if (substr($raw, -2) === "\0\0") {
            $raw = substr($raw, 0, -2);
        }
        $value = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        $value = $value === false ? '' : $value;
        self::row($rows, $bytes, $start, 4 + $available, $field, 'FString', $value, 'wide length=' . $length);
        return $value;
    }

    private static function flags(int $flags): string
    {
        $known = [
            0x1 => 'AllowDownload', 0x2 => 'ClientOptional', 0x4 => 'ServerSideOnly',
            0x8 => 'NoExportAllowed', 0x10 => 'Cooked', 0x20 => 'Encrypted',
            0x8000 => 'Map', 0x20000 => 'Script', 0x40000 => 'ContainsMap',
            0x80000 => 'DebugInfo', 0x100000 => 'Imports', 0x200000 => 'Compressed',
            0x400000 => 'FullyCompressed',
        ];
        $names = [];
        foreach ($known as $bit => $name) {
            if (($flags & $bit) !== 0) {
                $names[] = $name;
            }
        }
        return sprintf('0x%08X', $flags) . ($names !== [] ? ' / ' . implode(', ', $names) : '');
    }

    private static function has(string $bytes, int $offset, int $length): bool
    {
        return $offset >= 0 && $length >= 0 && strlen($bytes) >= $offset + $length;
    }
}
