<?php
/**
 * Parses the UE5 file-package-store container header carried by an IoStore ContainerHeader chunk.
 * The result keeps package IDs and relative-view provenance source-shaped for UEDB5 staging.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5IoStoreContainerHeaderReader
{
    public const SIGNATURE = 0x496f436e;
    public const VERSION_NO_EXPORT_INFO = 3;
    public const VERSION_SOFT_PACKAGE_REFERENCES = 4;
    public const VERSION_SOFT_PACKAGE_REFERENCES_OFFSET = 5;
    public const VERSION_LATEST = 5;

    /** @return array<string,mixed> */
    public static function parse(string $bytes): array
    {
        $reader = new Uedb5BinaryReader($bytes, 'IoStore container header');
        $signature = $reader->u32le();
        if ($signature !== self::SIGNATURE) {
            throw new RuntimeException('IoStore container header signature mismatch.');
        }
        $version = $reader->u32le();
        if ($version < self::VERSION_NO_EXPORT_INFO || $version > self::VERSION_LATEST) {
            throw new RuntimeException('Unsupported IoStore container header version: ' . $version);
        }

        $containerId = $reader->u64HexLe();
        $packageIds = self::readPackageIdArray($reader, 'PackageIds');
        $storeBytes = self::readByteArray($reader, 'StoreEntries');
        $optionalPackageIds = self::readPackageIdArray($reader, 'OptionalSegmentPackageIds');
        $optionalStoreBytes = self::readByteArray($reader, 'OptionalSegmentStoreEntries');
        $redirectNames = self::readNameBatch($reader);

        $localized = [];
        $localizedCount = self::readCount($reader, 'LocalizedPackages');
        for ($index = 0; $index < $localizedCount; $index++) {
            $sourcePackageId = $reader->u64HexLe();
            $mappedName = self::readMappedName($reader, $redirectNames);
            $localized[] = [
                'source_package_id' => $sourcePackageId,
                'source_package_name' => $mappedName,
            ];
        }

        $redirects = [];
        $redirectCount = self::readCount($reader, 'PackageRedirects');
        for ($index = 0; $index < $redirectCount; $index++) {
            $sourcePackageId = $reader->u64HexLe();
            $targetPackageId = $reader->u64HexLe();
            $mappedName = self::readMappedName($reader, $redirectNames);
            $redirects[] = [
                'source_package_id' => $sourcePackageId,
                'target_package_id' => $targetPackageId,
                'source_package_name' => $mappedName,
            ];
        }

        $softReferences = [
            'contains_soft_package_references' => false,
            'package_ids' => [],
            'package_references' => array_fill(0, count($packageIds), []),
            'serial_info' => null,
        ];
        if ($version === self::VERSION_SOFT_PACKAGE_REFERENCES) {
            $softReferences = self::readSoftReferences($reader, count($packageIds), null);
        } elseif ($version >= self::VERSION_SOFT_PACKAGE_REFERENCES_OFFSET) {
            $serialOffset = $reader->i64le();
            $serialSize = $reader->i64le();
            if ($serialSize < 0 || $serialSize > $reader->remaining()) {
                throw new RuntimeException('IoStore soft-reference serial size is outside the container header.');
            }
            $payloadStart = $reader->tell();
            if ($serialSize > 0) {
                $softReader = new Uedb5BinaryReader(
                    $reader->read($serialSize),
                    'IoStore container soft references'
                );
                $softReferences = self::readSoftReferences(
                    $softReader,
                    count($packageIds),
                    ['offset' => $serialOffset, 'size' => $serialSize]
                );
                if ($softReader->remaining() !== 0) {
                    throw new RuntimeException('IoStore soft-reference payload has unexpected trailing bytes.');
                }
            } else {
                $softReferences['serial_info'] = ['offset' => $serialOffset, 'size' => $serialSize];
            }
            if ($serialOffset >= 0 && $serialSize > 0 && $serialOffset !== $payloadStart) {
                throw new RuntimeException('IoStore soft-reference serial offset does not point at its payload.');
            }
        }

        if ($reader->remaining() !== 0) {
            throw new RuntimeException(
                'IoStore container header has ' . $reader->remaining() . ' unexpected trailing bytes.'
            );
        }

        $storeEntries = self::parseStoreEntries($storeBytes, $packageIds, false);
        $optionalStoreEntries = self::parseStoreEntries($optionalStoreBytes, $optionalPackageIds, true);

        return [
            'signature' => sprintf('%08X', $signature),
            'version' => $version,
            'container_id' => $containerId,
            'package_ids' => $packageIds,
            'store_entries' => $storeEntries,
            'optional_segment_package_ids' => $optionalPackageIds,
            'optional_segment_store_entries' => $optionalStoreEntries,
            'redirect_name_map' => $redirectNames,
            'localized_packages' => $localized,
            'package_redirects' => $redirects,
            'soft_package_references' => $softReferences,
        ];
    }

    /** @return list<string> */
    private static function readPackageIdArray(Uedb5BinaryReader $reader, string $label): array
    {
        $count = self::readCount($reader, $label);
        if ($count > intdiv($reader->remaining(), 8)) {
            throw new RuntimeException('IoStore ' . $label . ' exceeds the available bytes.');
        }
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = $reader->u64HexLe();
        }
        return $ids;
    }

    private static function readByteArray(Uedb5BinaryReader $reader, string $label): string
    {
        $count = self::readCount($reader, $label);
        if ($count > $reader->remaining()) {
            throw new RuntimeException('IoStore ' . $label . ' exceeds the available bytes.');
        }
        return $reader->read($count);
    }

    private static function readCount(Uedb5BinaryReader $reader, string $label): int
    {
        $count = $reader->i32le();
        if ($count < 0) {
            throw new RuntimeException('IoStore ' . $label . ' has a negative array count.');
        }
        return $count;
    }

    /**
     * @return list<array{index:int,hash:string,is_utf16:bool,length:int,raw:string,text:string}>
     */
    private static function readNameBatch(Uedb5BinaryReader $reader): array
    {
        $count = $reader->u32le();
        if ($count === 0) {
            return [];
        }
        $stringBytes = $reader->u32le();
        $hashVersion = $reader->u64HexLe();
        if ($count > intdiv($reader->remaining(), 10)) {
            throw new RuntimeException('IoStore redirect name batch count exceeds available metadata.');
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
            throw new RuntimeException('IoStore redirect name strings exceed the container header.');
        }
        $strings = new Uedb5BinaryReader($reader->read($stringBytes), 'IoStore redirect name strings');

        $names = [];
        foreach ($headers as $index => $header) {
            $byteLength = (int)$header['length'] * ((bool)$header['is_utf16'] ? 2 : 1);
            $raw = $strings->read($byteLength);
            $text = $raw;
            if ((bool)$header['is_utf16']) {
                $converted = function_exists('iconv') ? @iconv('UTF-16LE', 'UTF-8', $raw) : false;
                if (!is_string($converted)) {
                    throw new RuntimeException('IoStore UTF-16 redirect name could not be decoded.');
                }
                $text = $converted;
            }
            $names[] = [
                'index' => $index,
                'hash' => $hashes[$index],
                'hash_version' => $hashVersion,
                'is_utf16' => (bool)$header['is_utf16'],
                'length' => (int)$header['length'],
                'raw' => strtoupper(bin2hex($raw)),
                'text' => $text,
            ];
        }
        if ($strings->remaining() !== 0) {
            throw new RuntimeException('IoStore redirect name batch string lengths do not consume the declared bytes.');
        }
        return $names;
    }

    /**
     * @param list<array<string,mixed>> $names
     * @return array<string,mixed>
     */
    private static function readMappedName(Uedb5BinaryReader $reader, array $names): array
    {
        $rawIndex = $reader->u32le();
        $number = $reader->u32le();
        $decoded = Uedb5BinaryReader::decodeMappedNameIndex($rawIndex);
        if ((int)$decoded['type'] !== 1) {
            throw new RuntimeException('IoStore redirect/localization FMappedName is not a container-name-map entry.');
        }
        $nameIndex = (int)$decoded['index'];
        if (!isset($names[$nameIndex])) {
            throw new RuntimeException('IoStore redirect/localization FMappedName index is outside RedirectsNameMap.');
        }
        $baseText = (string)$names[$nameIndex]['text'];
        $displayText = $baseText;
        if ($baseText !== null && $number > 0) {
            $displayText .= '_' . ($number - 1);
        }
        return [
            'raw_index' => sprintf('%08X', $rawIndex),
            'type' => (int)$decoded['type'],
            'name_index' => $nameIndex,
            'number' => $number,
            'base_text' => $baseText,
            'text' => $displayText,
        ];
    }

    /**
     * @param list<string> $packageIds
     * @return list<array<string,mixed>>
     */
    private static function parseStoreEntries(string $bytes, array $packageIds, bool $optional): array
    {
        $entryCount = count($packageIds);
        $tocBytes = $entryCount * 16;
        if (strlen($bytes) < $tocBytes) {
            throw new RuntimeException('IoStore package-store entry TOC is shorter than the package ID list.');
        }

        $entries = [];
        for ($index = 0; $index < $entryCount; $index++) {
            $entryOffset = $index * 16;
            $imports = self::parseRelativeArrayView($bytes, $entryOffset, 8, 'package imports');
            $shaders = self::parseRelativeArrayView($bytes, $entryOffset + 8, 8, 'shader hashes');

            $importedPackageIds = [];
            foreach ($imports as $raw) {
                $importedPackageIds[] = strtoupper(bin2hex(strrev($raw)));
            }
            $shaderMapHashes = [];
            foreach ($shaders as $raw) {
                $shaderMapHashes[] = strtoupper(bin2hex(strrev($raw)));
            }
            $entries[] = [
                'entry_index' => $index,
                'package_id' => $packageIds[$index],
                'optional_segment' => $optional,
                'imported_package_ids' => $importedPackageIds,
                'shader_map_hashes' => $shaderMapHashes,
            ];
        }
        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function parseRelativeArrayView(
        string $bytes,
        int $headerOffset,
        int $elementSize,
        string $label
    ): array {
        if ($headerOffset < 0 || $headerOffset + 8 > strlen($bytes)) {
            throw new RuntimeException('IoStore ' . $label . ' view header is outside StoreEntries.');
        }
        $header = new Uedb5BinaryReader(substr($bytes, $headerOffset, 8), 'IoStore ' . $label . ' view');
        $count = $header->u32le();
        $relativeOffset = $header->u32le();
        if ($count === 0) {
            if ($relativeOffset !== 0) {
                throw new RuntimeException('IoStore empty ' . $label . ' view has a non-zero data offset.');
            }
            return [];
        }

        $dataOffset = $headerOffset + $relativeOffset;
        $byteCount = $count * $elementSize;
        if ($dataOffset < 0 || $byteCount < 0 || $dataOffset + $byteCount > strlen($bytes)) {
            throw new RuntimeException('IoStore ' . $label . ' view points outside StoreEntries.');
        }
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = substr($bytes, $dataOffset + ($index * $elementSize), $elementSize);
        }
        return $rows;
    }

    /**
     * @param array{offset:int,size:int}|null $serialInfo
     * @return array<string,mixed>
     */
    private static function readSoftReferences(
        Uedb5BinaryReader $reader,
        int $packageEntryCount,
        ?array $serialInfo
    ): array {
        $serializedContains = $reader->u32le(); // FArchive::SerializeBool uses legacy uint32.
        if ($serializedContains > 1) {
            throw new RuntimeException('IoStore soft-reference boolean is not a valid serialized bool.');
        }
        $contains = $serializedContains !== 0;
        if (!$contains) {
            return [
                'contains_soft_package_references' => false,
                'package_ids' => [],
                'package_references' => array_fill(0, $packageEntryCount, []),
                'serial_info' => $serialInfo,
            ];
        }

        $softIds = self::readPackageIdArray($reader, 'SoftPackageReferences.PackageIds');
        $indicesBytes = self::readByteArray($reader, 'SoftPackageReferences.PackageIndices');
        $tocBytes = $packageEntryCount * 8;
        if (strlen($indicesBytes) < $tocBytes) {
            throw new RuntimeException('IoStore soft-reference view TOC is shorter than the package entry list.');
        }

        $packageReferences = [];
        for ($packageIndex = 0; $packageIndex < $packageEntryCount; $packageIndex++) {
            $views = self::parseRelativeArrayView($indicesBytes, $packageIndex * 8, 4, 'soft package indices');
            $resolved = [];
            foreach ($views as $rawIndex) {
                $indexReader = new Uedb5BinaryReader($rawIndex, 'IoStore soft package index');
                $softIndex = $indexReader->u32le();
                if (!isset($softIds[$softIndex])) {
                    throw new RuntimeException('IoStore soft package reference index is outside the deduplicated ID list.');
                }
                $resolved[] = [
                    'package_id_index' => $softIndex,
                    'package_id' => $softIds[$softIndex],
                ];
            }
            $packageReferences[] = $resolved;
        }

        return [
            'contains_soft_package_references' => true,
            'package_ids' => $softIds,
            'package_references' => $packageReferences,
            'serial_info' => $serialInfo,
        ];
    }
}
