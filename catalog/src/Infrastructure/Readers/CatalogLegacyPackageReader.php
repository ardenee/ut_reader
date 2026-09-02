<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogUE2PackageReader` for catalog legacy package reader.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use OutOfBoundsException;
use RuntimeException;
use Throwable;

/**
 * Canonical memory-bounded random-access parser for UE1/UE2 package metadata.
 *
 * "Legacy" describes the UE1/UE2 serialized package family. This is not a
 * fallback or second-choice reader: CatalogReaderResolver routes all catalog
 * UE1/UE2 parsing through this implementation. CatalogUE1PackageReader and
 * CatalogUE2PackageReader are only thin engine-key wrappers over this shared
 * parser core.
 */
final class CatalogLegacyBinaryStream
{
    /** @var resource */
    private $handle;
    private int $size;
    private int $position = 0;

    public function __construct(string $path)
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new RuntimeException('Legacy package is missing or empty: ' . basename($path));
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open legacy package: ' . basename($path));
        }
        $this->handle = $handle;
        $this->size = (int)$size;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function remaining(): int
    {
        return $this->size - $this->position;
    }

    public function peekHex(int $offset, int $length = 32): string
    {
        if ($offset < 0 || $offset >= $this->size || $length < 1) {
            return '';
        }

        $saved = $this->position;
        $available = min($length, $this->size - $offset);
        if (fseek($this->handle, $offset, SEEK_SET) !== 0) {
            return '';
        }

        $data = fread($this->handle, $available);
        @fseek($this->handle, $saved, SEEK_SET);
        if (!is_string($data)) {
            return '';
        }

        return strtoupper(bin2hex($data));
    }

    public function seek(int $position): void
    {
        if ($position < 0 || $position > $this->size) {
            throw new OutOfBoundsException('Legacy package seek is outside the file: ' . $position . '/' . $this->size);
        }
        if (fseek($this->handle, $position, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek within the legacy package.');
        }
        $this->position = $position;
    }

    public function bytes(int $count): string
    {
        if ($count < 0 || $this->position + $count > $this->size) {
            throw new OutOfBoundsException(
                'Legacy package read exceeds the file: need=' . $count
                . ' position=' . $this->position . ' size=' . $this->size
            );
        }
        if ($count === 0) {
            return '';
        }

        $data = '';
        while (strlen($data) < $count) {
            $part = fread($this->handle, $count - strlen($data));
            if (!is_string($part) || $part === '') {
                throw new RuntimeException('Legacy package read stopped before the requested bytes were available.');
            }
            $data .= $part;
        }
        $this->position += $count;
        return $data;
    }

    public function u8(): int
    {
        return ord($this->bytes(1));
    }

    public function u16(): int
    {
        return (int)unpack('vvalue', $this->bytes(2))['value'];
    }

    public function u32(): int
    {
        return (int)unpack('Vvalue', $this->bytes(4))['value'];
    }

    public function i32(): int
    {
        $value = $this->u32();
        return ($value & 0x80000000) !== 0 ? $value - 0x100000000 : $value;
    }

    public function compactIndex(): int
    {
        $byte = $this->u8();
        $negative = ($byte & 0x80) !== 0;
        $more = ($byte & 0x40) !== 0;
        $value = $byte & 0x3f;
        $shift = 6;
        $count = 1;

        while ($more) {
            if (++$count > 5) {
                throw new RuntimeException('Invalid compact package index length.');
            }
            $byte = $this->u8();
            $more = ($byte & 0x80) !== 0;
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        }
        return $negative ? -$value : $value;
    }

    public function packageIndex(int $version): int
    {
        // UE1/UE2 package references, FName indices, FString lengths and
        // export serial size/offset fields are serialized with AR_INDEX
        // (FCompactIndex). UT2004 does not switch these fields to int32 at
        // package version 178; ordinary INT fields such as PackageIndex/outer
        // remain explicitly read with i32() by their callers.
        return $this->compactIndex();
    }

    public function cstring(int $maximum = 65536): string
    {
        $value = '';
        for ($index = 0; $index < $maximum && $this->remaining() > 0; $index++) {
            $character = $this->bytes(1);
            if ($character === "\0") {
                return self::toUtf8($value);
            }
            $value .= $character;
        }
        throw new RuntimeException('Legacy package string has no terminator within the safe limit.');
    }

    public function fstring(int $version): string
    {
        return $this->stringByLength($this->packageIndex($version));
    }

    private function stringByLength(int $length): string
    {
        if ($length === 0) {
            return '';
        }
        if ($length > 0) {
            if ($length > 65536 || $length > $this->remaining()) {
                throw new OutOfBoundsException('Invalid legacy FString byte length: ' . $length);
            }
            $raw = $this->bytes($length);
            $terminator = strpos($raw, "\0");
            if ($terminator !== false) {
                $raw = substr($raw, 0, $terminator);
            }
            return self::toUtf8($raw);
        }

        $characters = -$length;
        $bytes = $characters * 2;
        if ($characters > 32768 || $bytes > $this->remaining()) {
            throw new OutOfBoundsException('Invalid legacy wide FString length: ' . $length);
        }
        $raw = $this->bytes($bytes);
        for ($offset = 0; $offset + 1 < strlen($raw); $offset += 2) {
            if ($raw[$offset] === "\0" && $raw[$offset + 1] === "\0") {
                $raw = substr($raw, 0, $offset);
                break;
            }
        }
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        return $converted === false ? '' : $converted;
    }

    public static function toUtf8(string $raw): string
    {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        return $converted === false ? $raw : $converted;
    }
}

abstract class CatalogLegacyPackageReaderBase
{
    private string $path;
    private string $engineKey;
    /** @var array<string,mixed> */
    private array $header = [];
    /** @var list<array<string,mixed>> */
    private array $names = [];
    /** @var list<array<string,mixed>> */
    private array $imports = [];
    /** @var list<array<string,mixed>> */
    private array $exports = [];
    /** @var list<string> */
    private array $issues = [];

    protected function __construct(string $path, string $engineKey)
    {
        $this->path = $path;
        $this->engineKey = $engineKey;
        try {
            $this->parse();
        } catch (Throwable $error) {
            $message = trim($error->getMessage());
            $this->issues[] = get_class($error) . ': '
                . ($message !== '' ? $message : 'Legacy package parsing failed.')
                . ' File: ' . str_replace('\\', '/', $error->getFile()) . ':' . $error->getLine();
            $this->header = $this->blankHeader();
            $this->names = [];
            $this->imports = [];
            $this->exports = [];
        }
    }

    private function parse(): void
    {
        $reader = new CatalogLegacyBinaryStream($this->path);
        $tag = $reader->u32();
        if (!\UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedLittleEndianValue($tag)) {
            throw new RuntimeException(sprintf('Bad package tag 0x%08X.', $tag));
        }
        $packed = $reader->u32();
        $version = $packed & 0xffff;
        $licensee = ($packed >> 16) & 0xffff;
        $flags = $reader->u32();

        $this->header = $this->blankHeader();
        $this->header['signature'] = $tag;
        $this->header['tag'] = $tag;
        $this->header['packedVersion'] = $packed;
        $this->header['version'] = $version;
        $this->header['licensee'] = $licensee;
        $this->header['licenseeVersion'] = $licensee;
        $this->header['pkgFlags'] = $flags;
        $this->header['packageFlags'] = $flags;
        $this->header['nameCount'] = $reader->i32();
        $this->header['nameOffset'] = $reader->i32();
        $this->header['exportCount'] = $reader->i32();
        $this->header['exportOffset'] = $reader->i32();
        $this->header['importCount'] = $reader->i32();
        $this->header['importOffset'] = $reader->i32();

        $this->validateTable('Names', (int)$this->header['nameCount'], (int)$this->header['nameOffset'], $reader->size());
        $this->validateTable('Exports', (int)$this->header['exportCount'], (int)$this->header['exportOffset'], $reader->size());
        $this->validateTable('Imports', (int)$this->header['importCount'], (int)$this->header['importOffset'], $reader->size());

        if ($version < 68) {
            $this->header['heritageCount'] = $reader->i32();
            $this->header['heritageOffset'] = $reader->i32();
            $spaceBeforeNames = (int)$this->header['nameOffset'] - $reader->tell();
            if ($this->engineKey === 'UE1' && $spaceBeforeNames >= 16) {
                $this->readGuid($reader);
            }
            $this->header['generations'] = [[
                'e' => (int)$this->header['exportCount'],
                'n' => (int)$this->header['nameCount'],
                'exportCount' => (int)$this->header['exportCount'],
                'nameCount' => (int)$this->header['nameCount'],
            ]];
        } else {
            $this->readGuid($reader);
            $generationCount = $reader->i32();
            if ($generationCount < 0 || $generationCount > 100000) {
                throw new RuntimeException('Invalid legacy package generation count: ' . $generationCount);
            }
            $this->header['genCount'] = $generationCount;
            for ($index = 0; $index < $generationCount; $index++) {
                $exports = $reader->i32();
                $names = $reader->i32();
                $this->header['generations'][] = [
                    'e' => $exports,
                    'n' => $names,
                    'exportCount' => $exports,
                    'nameCount' => $names,
                ];
            }
        }

        $this->readNames($version);
        $this->readImports($version);
        $this->readExports($version);
    }

    private function readGuid(CatalogLegacyBinaryStream $reader): void
    {
        $parts = [$reader->u32(), $reader->u32(), $reader->u32(), $reader->u32()];
        $this->header['guidArray'] = $parts;
        $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $parts[0], $parts[1], $parts[2], $parts[3]);
        $this->header['guidDwords'] = $this->header['guid'];
        $this->header['guidRaw'] = strtoupper(bin2hex(pack('V4', $parts[0], $parts[1], $parts[2], $parts[3])));
    }

    private function readNames(int $version): void
    {
        $reader = new CatalogLegacyBinaryStream($this->path);
        $reader->seek((int)$this->header['nameOffset']);
        $count = (int)$this->header['nameCount'];
        for ($index = 0; $index < $count; $index++) {
            $entryOffset = $reader->tell();
            try {
                $name = $version < 64 ? $reader->cstring() : $reader->fstring($version);
                $flags = $reader->u32();
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'Name table entry parse failed'
                    . ': index=' . $index
                    . ', entry_offset=' . $entryOffset
                    . ', current_offset=' . $reader->tell()
                    . ', table_offset=' . (int)$this->header['nameOffset']
                    . ', table_count=' . $count
                    . ', package_size=' . $reader->size()
                    . ', entry_head_hex=' . $reader->peekHex($entryOffset, 32)
                    . '. ' . $error->getMessage(),
                    0,
                    $error
                );
            }
            $this->names[] = [
                'index' => $index,
                'name' => $name,
                'text' => $name,
                'flags' => $flags,
                'objectFlags' => $flags,
            ];
        }
    }

    private function readImports(int $version): void
    {
        $reader = new CatalogLegacyBinaryStream($this->path);
        $reader->seek((int)$this->header['importOffset']);
        $count = (int)$this->header['importCount'];
        for ($index = 0; $index < $count; $index++) {
            $entryOffset = $reader->tell();
            try {
                $classPackage = $reader->packageIndex($version);
                $className = $reader->packageIndex($version);
                $outer = $reader->i32();
                $objectName = $reader->packageIndex($version);
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'Import table entry parse failed'
                    . ': index=' . $index
                    . ', entry_offset=' . $entryOffset
                    . ', current_offset=' . $reader->tell()
                    . ', table_offset=' . (int)$this->header['importOffset']
                    . ', table_count=' . $count
                    . ', package_size=' . $reader->size()
                    . ', entry_head_hex=' . $reader->peekHex($entryOffset, 32)
                    . '. ' . $error->getMessage(),
                    0,
                    $error
                );
            }
            $classPackageText = $this->nameByIndex($classPackage);
            $classNameText = $this->nameByIndex($className);
            $objectNameText = $this->nameByIndex($objectName);
            $this->imports[] = [
                'index' => $index,
                'classPackage' => $classPackage,
                'className' => $className,
                'outerIndex' => $outer,
                'outer' => $outer,
                'objectName' => $objectName,
                'classPackageText' => $classPackageText,
                'classNameText' => $classNameText,
                'objectNameText' => $objectNameText,
                'ClassPackage' => ['index' => $classPackage, 'number' => 0, 'text' => $classPackageText],
                'ClassName' => ['index' => $className, 'number' => 0, 'text' => $classNameText],
                'OuterIndex' => $outer,
                'ObjectName' => ['index' => $objectName, 'number' => 0, 'text' => $objectNameText],
            ];
        }
    }

    private function readExports(int $version): void
    {
        $reader = new CatalogLegacyBinaryStream($this->path);
        $reader->seek((int)$this->header['exportOffset']);
        $count = (int)$this->header['exportCount'];
        for ($index = 0; $index < $count; $index++) {
            $entryOffset = $reader->tell();
            try {
                $class = $reader->packageIndex($version);
                $super = $reader->packageIndex($version);
                $outer = $reader->i32();
                $objectName = $reader->packageIndex($version);
                $flags = $reader->u32();
                $serialSize = $reader->packageIndex($version);
                $serialOffset = $serialSize > 0 ? $reader->packageIndex($version) : 0;
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'Export table entry parse failed'
                    . ': index=' . $index
                    . ', entry_offset=' . $entryOffset
                    . ', current_offset=' . $reader->tell()
                    . ', table_offset=' . (int)$this->header['exportOffset']
                    . ', table_count=' . $count
                    . ', package_size=' . $reader->size()
                    . ', entry_head_hex=' . $reader->peekHex($entryOffset, 32)
                    . '. ' . $error->getMessage(),
                    0,
                    $error
                );
            }
            $this->exports[] = [
                'index' => $index,
                'classIndex' => $class,
                'class' => $class,
                'superIndex' => $super,
                'super' => $super,
                'packageIndex' => $outer,
                'outerIndex' => $outer,
                'outer' => $outer,
                'objectName' => $objectName,
                'nameIndex' => $objectName,
                'nameNumber' => 0,
                'objectNameText' => $this->nameByIndex($objectName),
                'objectFlags' => $flags,
                'serialSize' => $serialSize,
                'serialOffset' => $serialOffset,
                'archetype' => 0,
                'components' => [],
                'componentMap' => [],
                'exportFlags' => 0,
            ];
        }
    }

    private function validateTable(string $label, int $count, int $offset, int $fileSize): void
    {
        if ($count < 0 || $count > 2000000) {
            throw new RuntimeException('Invalid ' . $label . ' table count: ' . $count);
        }
        if ($offset < 0 || $offset > $fileSize || ($count > 0 && $offset === $fileSize)) {
            throw new RuntimeException('Invalid ' . $label . ' table offset: ' . $offset . '/' . $fileSize);
        }
    }

    /** @return array<string,mixed> */
    private function blankHeader(): array
    {
        return [
            'signature' => 0,
            'tag' => 0,
            'version' => 0,
            'licensee' => 0,
            'licenseeVersion' => 0,
            'pkgFlags' => 0,
            'packageFlags' => 0,
            'nameCount' => 0,
            'nameOffset' => 0,
            'exportCount' => 0,
            'exportOffset' => 0,
            'importCount' => 0,
            'importOffset' => 0,
            'heritageCount' => 0,
            'heritageOffset' => 0,
            'dependsOffset' => 0,
            'guid' => '',
            'guidRaw' => '',
            'guidDwords' => '',
            'generations' => [],
            'chunks' => [],
            'compressedChunks' => [],
            'compressed' => false,
            'compressionFlags' => 0,
            'cFlags' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public function getHeader(): array
    {
        return $this->header;
    }

    /** @return list<array<string,mixed>> */
    public function getNames(): array
    {
        return $this->names;
    }

    /** @return list<array<string,mixed>> */
    public function getImports(): array
    {
        return $this->imports;
    }

    /** @return list<array<string,mixed>> */
    public function getExports(): array
    {
        return $this->exports;
    }

    /** @return list<string> */
    public function getDebugErrors(): array
    {
        return $this->issues;
    }

    /** @return list<string> */
    public function validatePackage(): array
    {
        return $this->issues;
    }

    /** @return array<string,mixed> */
    public function getCompressionInfo(): array
    {
        return ['isCompressed' => false, 'flags' => 0, 'chunks' => [], 'totalCompressed' => 0, 'totalUncompressed' => 0];
    }

    /** @return list<array<string,mixed>> */
    public function getRawHeaderFields(): array
    {
        return [];
    }

    public function getFileSize(): string
    {
        $size = filesize($this->path);
        return $size === false ? '' : number_format((int)$size) . ' bytes';
    }

    public function nameByIndex(int $index, int $number = 0): string
    {
        if ($index < 0 || !isset($this->names[$index])) {
            return '';
        }
        $name = (string)($this->names[$index]['name'] ?? '');
        return $number !== 0 && $name !== '' ? $name . '_' . $number : $name;
    }

    public function displayNameFromRef(int $reference): string
    {
        if ($reference === 0) {
            return '';
        }
        if ($reference > 0) {
            $row = $this->exports[$reference - 1] ?? null;
            return is_array($row) ? (string)($row['objectNameText'] ?? '') : '';
        }
        $row = $this->imports[-$reference - 1] ?? null;
        return is_array($row) ? (string)($row['objectNameText'] ?? '') : '';
    }

    public function getExportProperties(int $exportIndex): array
    {
        return [];
    }

    public function getExportProperty(int $exportIndex, string $name, mixed $default = null): mixed
    {
        return $default;
    }

    public function readPropertiesForExport(int $exportIndex): array
    {
        return [];
    }
}

final class CatalogUE1PackageReader extends CatalogLegacyPackageReaderBase
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'UE1');
    }
}

final class CatalogUE2PackageReader extends CatalogLegacyPackageReaderBase
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'UE2');
    }
}
