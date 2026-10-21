<?php
/**
 * Source-aligned Unreal v0.867 UObjectBase table, distinct from UE1 .u imports/exports.
 *
 * Native authority:
 * Src/Engine/UnObj.cpp:46-68 serializes FName name, FName class,
 * DWORD flags, DWORD header offset; exports append four DWORDs.
 * Src/Inc/UnLinker.h:710-729 loads one-based WORD FName indices;
 * :501-625 imports are records with FileHeaderOffset=0, exported
 * classes append object-reference+FName+two DWORD class preload fields.
 *
 * This is metadata, not the class-specific SerializeHeader/SerializeData.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;

final class CatalogUE1OriginalUnrealfileObjectBaseReader
{
    /** @return array<string,mixed> */
    public static function read(string $path, ?int $loadContextFlags = null): array
    {
        $metadata = CatalogUE1OriginalUnrealfileMetadataReader::read($path);
        // Source LoadNames maps entries to NAME_None when not required by
        // the explicitly selected editor/client/server runtime context.
        $effectiveNames = null;
        if ($loadContextFlags !== null) {
            if (($loadContextFlags & CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS) === 0
                || ($loadContextFlags & ~CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS) !== 0) {
                throw new RuntimeException('Unrealfile name resolution requires a source-defined load context.');
            }
            $effectiveNames = CatalogLegacyNameMapPreprocessor::effectiveNameMap(
                $metadata['names'], $loadContextFlags, 31
            );
        }
        $summary = $metadata['summary'];
        $count = (int)$summary['object_count'];
        $offset = (int)$summary['objects_offset'];
        $names = $metadata['names'];
        if ($effectiveNames !== null) {
            $metadata['resolved_name_context_flags'] = $loadContextFlags;
            $metadata['effective_names'] = $effectiveNames;
        }
        if ($count === 0) {
            $metadata['object_bases_decoded'] = true;
            $metadata['object_bases'] = [];
            return $metadata;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open original Unrealfile object table.');
        }
        try {
            $size = (int)(fstat($handle)['size'] ?? 0);
            if ($offset < (int)$summary['serialized_summary_end'] || $offset > $size
                || $count > intdiv($size - $offset, 12)
                || fseek($handle, $offset, SEEK_SET) !== 0) {
                throw new RuntimeException('Original Unrealfile object-base table exceeds file bounds.');
            }
            $objects = [];
            for ($index = 0; $index < $count; $index++) {
                $start = ftell($handle);
                $base = unpack('vnameIndex/vclassIndex/Vflags/VheaderOffset', self::exact($handle, 12));
                $nameIndex = (int)$base['nameIndex'];
                $classIndex = (int)$base['classIndex'];
                $name = self::mappedName($nameIndex, $names);
                $class = self::mappedName($classIndex, $names);
                $headerOffset = (int)$base['headerOffset'];
                $entry = [
                    'index' => $index,
                    'serialized_offset' => $start,
                    'name_index' => $nameIndex,
                    'name' => $name,
                    'class_index' => $classIndex,
                    'class_name' => $class,
                    'flags' => (int)$base['flags'],
                    'file_header_offset' => $headerOffset,
                    'kind' => $headerOffset === 0 ? 'import' : 'export',
                ];
                if ($effectiveNames !== null) {
                    // Native Unrealfile NAME_INDEX is one-based. The
                    // serialized NameMap table itself starts at index zero.
                    $entry['effective_name'] = $nameIndex === 0
                        ? 'None' : (string)$effectiveNames[$nameIndex - 1];
                    $entry['effective_class_name'] = $classIndex === 0
                        ? 'None' : (string)$effectiveNames[$classIndex - 1];
                }

                if ($headerOffset !== 0) {
                    $info = unpack('Vcrc/VdataOffset/VheaderSize/VdataSize', self::exact($handle, 16));
                    $headerSize = (int)$info['headerSize'];
                    $dataSize = (int)$info['dataSize'];
                    $dataOffset = (int)$info['dataOffset'];
                    if ($headerOffset > $size || $headerSize > $size - $headerOffset
                        || $dataOffset > $size || $dataSize > $size - $dataOffset) {
                        throw new RuntimeException('Original Unrealfile exported object ' . $index
                            . ' has header/data outside the physical file.');
                    }
                    $entry += [
                        'file_crc' => (int)$info['crc'],
                        'file_data_offset' => $dataOffset,
                        'file_header_size' => $headerSize,
                        'file_data_size' => $dataSize,
                    ];

                    // Epic saves this immediately after the exported UObjectBase,
                    // but only for exports of the native UClass base class.
                    if ($class !== null && strcasecmp($class, 'Class') === 0) {
                        $preload = unpack('VparentObjectReference/vpackageNameIndex/'
                            . 'VclassFlags/VresourceHeaderSize', self::exact($handle, 14));
                        $packageIndex = (int)$preload['packageNameIndex'];
                        $parentReference = (int)$preload['parentObjectReference'];
                        // Epic v0.867 FArchiveResLoad::operator<<(UObject*)
                        // decodes DWORD ResMap[index-1], with 0 meaning NULL.
                        // The map's size is Summary.NumObjects. Do not treat
                        // this as a name index or invent a resolved identity.
                        if ($parentReference > $count) {
                            throw new RuntimeException('Original Unrealfile class preload has '
                                . 'invalid one-based object reference ' . $parentReference
                                . '/' . $count . '.');
                        }
                        $entry['class_preload'] = [
                            'parent_object_reference' => $parentReference,
                            'parent_object_index' => $parentReference === 0 ? null : $parentReference - 1,
                            'package_name_index' => $packageIndex,
                            'package_name' => self::mappedName($packageIndex, $names),
                            'class_flags' => (int)$preload['classFlags'],
                            'resource_this_header_size' => (int)$preload['resourceHeaderSize'],
                        ];
                    }
                }
                $objects[] = $entry;
            }
            $metadata['object_bases'] = $objects;
            $metadata['object_bases_decoded'] = true;
            return $metadata;
        } finally {
            fclose($handle);
        }
    }

    /** Epic ULinkerLoad maps disk FName index 0 to NAME_None; all others are 1-based. */
    private static function mappedName(int $diskIndex, array $names): ?string
    {
        if ($diskIndex === 0) {
            return null;
        }
        if (!array_key_exists($diskIndex - 1, $names)) {
            throw new RuntimeException('Original Unrealfile invalid name index ' . $diskIndex);
        }
        return (string)$names[$diskIndex - 1]['name'];
    }

    private static function exact($handle, int $len): string
    {
        $data = '';
        while (strlen($data) < $len) {
            $part = fread($handle, $len - strlen($data));
            if (!is_string($part) || $part === '') {
                throw new RuntimeException('Truncated original Unrealfile UObjectBase record.');
            }
            $data .= $part;
        }
        return $data;
    }
}
