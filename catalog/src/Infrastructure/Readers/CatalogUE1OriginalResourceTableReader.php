<?php
/**
 * Epic Unreal 1996 original resource-table reader (O02/O03/O04 groundwork).
 * Source: v0.82/0.83/0.84a Src/Engine/UnRes.cpp::FGlobalResourceManager::AddFile,
 * Src/Inc/UnResTyp.h::FResourceFileTrailer/FName[Table]Entry/FResNameFileEntry.
 *
 * This reads exactly the source-defined summary, referenced names, imports
 * and exports. It does NOT deserialize the resource headers or payload data,
 * nor claim that original resource imports have modern UObject semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;
use UnrealDb\Catalog\Domain\Package\CatalogUE1OriginalResourceFormat;

final class CatalogUE1OriginalResourceTableReader
{
    private const NAME_WIDTH = 32;
    private const NAME_ROW_SIZE = 36; // char[32] + DWORD flags
    private const OBJECT_ROW_SIZE = 44; // char[32] + DWORD version, enum(32), DWORD size

    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        $probe = CatalogUE1OriginalResourceFormat::identify($path);
        if (!is_array($probe) || ($probe['format'] ?? '') !== 'early_unreal_resource'
            || ($probe['error_code'] ?? '') !== 'unreal.early_resource_unsupported') {
            throw new RuntimeException('Not a readable original Unreal 1996 resource-file summary.');
        }
        $version = (int)$probe['version'];
        if (!in_array($version, [9, 10], true)) {
            throw new RuntimeException('Original Unreal resource version ' . $version
                . ' is not defined by the supplied 0.82/0.83/0.84a source.');
        }
        $end = (int)$probe['trailer_offset'];
        $counts = (array)$probe['counts'];
        $offsets = (array)$probe['table_offsets'];
        foreach (['names' => self::NAME_ROW_SIZE,
                  'imports' => self::OBJECT_ROW_SIZE,
                  'exports' => self::OBJECT_ROW_SIZE] as $section => $stride) {
            $count = (int)$counts[$section];
            $offset = (int)$offsets[$section];
            // Epic reads these arrays as exact-sized C++ structures from
            // their absolute offsets. Validate bounds before allocating.
            if ($count < 0 || $offset < 0 || $offset > $end
                || $count > intdiv($end - $offset, $stride)) {
                throw new RuntimeException('Original Unreal ' . $section . ' table exceeds its source file bounds.');
            }
        }
        $headerOffset = (int)$offsets['headers'];
        $headerLength = (int)$offsets['headers_length'];
        if ($headerOffset < 0 || $headerOffset > $end
            || $headerLength < 0 || $headerLength > $end - $headerOffset) {
            throw new RuntimeException('Original Unreal resource headers exceed the source file bounds.');
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot open original Unreal resource file.');
        }
        try {
            $names = self::table($handle, (int)$offsets['names'], (int)$counts['names'], self::NAME_ROW_SIZE, 'names');
            $imports = self::table($handle, (int)$offsets['imports'], (int)$counts['imports'], self::OBJECT_ROW_SIZE, 'imports');
            $exports = self::table($handle, (int)$offsets['exports'], (int)$counts['exports'], self::OBJECT_ROW_SIZE, 'exports');
        } finally {
            fclose($handle);
        }
        return [
            'format' => 'early_unreal_resource',
            'version' => $version,
            'metadata_decoded' => true,
            'resource_data_decoded' => false,
            'summary' => ['version' => $version, 'counts' => $counts, 'table_offsets' => $offsets],
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function table($handle, int $offset, int $count, int $stride, string $kind): array
    {
        if ($count === 0) {
            return [];
        }
        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            throw new RuntimeException('Cannot seek original Unreal ' . $kind . ' table.');
        }
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $bytes = fread($handle, $stride);
            if (!is_string($bytes) || strlen($bytes) !== $stride) {
                throw new RuntimeException('Truncated original Unreal ' . $kind . ' record ' . $i);
            }
            $rawName = substr($bytes, 0, self::NAME_WIDTH);
            $nul = strpos($rawName, "\0");
            $name = $nul === false ? $rawName : substr($rawName, 0, $nul);
            if ($kind === 'names') {
                $decoded = unpack('Vflags', $bytes, self::NAME_WIDTH);
                $records[] = [
                    'index' => $i, 'serialized_offset' => $offset + $i * $stride,
                    'name' => $name, 'flags' => (int)$decoded['flags'],
                ];
            } else {
                $decoded = unpack('Vversion/Vtype/VheaderSize', $bytes, self::NAME_WIDTH);
                $records[] = [
                    'index' => $i, 'serialized_offset' => $offset + $i * $stride,
                    'name' => $name,
                    'resource_version' => (int)$decoded['version'],
                    'resource_type' => (int)$decoded['type'],
                    'resource_header_size' => (int)$decoded['headerSize'],
                ];
            }
        }
        return $records;
    }
}
