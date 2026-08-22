<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/**
 * Reads RAR5 FILECOPY redirection records without invoking an external process.
 *
 * RAR5 can store a second logical file path as a reference to another member's
 * data. PECL rar can enumerate those records but some bundled UnRAR versions do
 * not resolve getStream()/extract() for them. This parser exposes only the exact
 * target path recorded by the archive so CatalogExternalArchiveReader can decode
 * the referenced source entry through PECL rar and preserve normal size limits.
 */
final class CatalogRar5FileCopyMap
{
    private const SIGNATURE = "Rar!\x1A\x07\x01\x00";
    private const SIGNATURE_SEARCH_BYTES = 1048576;
    private const HEADER_FILE = 2;
    private const HEADER_END = 5;
    private const HFL_EXTRA = 0x0001;
    private const HFL_DATA = 0x0002;
    private const FHFL_UTIME = 0x0002;
    private const FHFL_CRC32 = 0x0004;
    private const EXTRA_REDIRECTION = 0x05;
    private const REDIRECTION_FILECOPY = 0x05;
    private const MAX_HEADER_BYTES = 2097152;
    private const MAX_RECORDS = 100000;

    /** @return array<string,string> normalized logical path => normalized source path */
    public function targets(string $archivePath): array
    {
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            return [];
        }
        $fileSize = filesize($archivePath);
        if ($fileSize === false || (int)$fileSize < strlen(self::SIGNATURE)) {
            return [];
        }
        $fileSize = (int)$fileSize;

        $handle = @fopen($archivePath, 'rb');
        if (!is_resource($handle)) {
            return [];
        }

        try {
            $prefix = fread($handle, min($fileSize, self::SIGNATURE_SEARCH_BYTES));
            if (!is_string($prefix)) {
                return [];
            }
            $signatureOffset = strpos($prefix, self::SIGNATURE);
            if ($signatureOffset === false) {
                return [];
            }

            $offset = (int)$signatureOffset + strlen(self::SIGNATURE);
            $targets = [];
            $records = 0;
            while ($offset + 5 <= $fileSize && $records++ < self::MAX_RECORDS) {
                if (fseek($handle, $offset, SEEK_SET) !== 0) {
                    break;
                }
                $crcBytes = fread($handle, 4);
                if (!is_string($crcBytes) || strlen($crcBytes) !== 4) {
                    break;
                }

                $sizeFieldStart = ftell($handle);
                if (!is_int($sizeFieldStart)) {
                    break;
                }
                [$headerSize, $sizeFieldBytes] = $this->readVintFromStream($handle);
                if ($headerSize < 1 || $headerSize > self::MAX_HEADER_BYTES) {
                    break;
                }
                $headerData = fread($handle, $headerSize);
                if (!is_string($headerData) || strlen($headerData) !== $headerSize) {
                    break;
                }

                $storedCrc = $this->u32($crcBytes, 0);
                $sizeField = $this->encodeVint($headerSize);
                if (strlen($sizeField) !== $sizeFieldBytes
                    || strtolower(sprintf('%08x', $storedCrc)) !== strtolower(hash('crc32b', $sizeField . $headerData))) {
                    break;
                }

                $cursor = 0;
                $headerType = $this->readVint($headerData, $cursor);
                $headerFlags = $this->readVint($headerData, $cursor);
                $extraSize = ($headerFlags & self::HFL_EXTRA) !== 0 ? $this->readVint($headerData, $cursor) : 0;
                $dataSize = ($headerFlags & self::HFL_DATA) !== 0 ? $this->readVint($headerData, $cursor) : 0;
                if ($extraSize < 0 || $dataSize < 0 || $extraSize > $headerSize || $dataSize > $fileSize) {
                    break;
                }

                if ($headerType === self::HEADER_FILE) {
                    $filePath = $this->parseFilePath($headerData, $cursor, $headerFlags, $extraSize);
                    if ($filePath !== null && $extraSize > 0) {
                        $extraOffset = $headerSize - $extraSize;
                        if ($extraOffset >= $cursor && $extraOffset <= $headerSize) {
                            $sourcePath = $this->parseFileCopyTarget(substr($headerData, $extraOffset, $extraSize));
                            if ($sourcePath !== null) {
                                $targets[$filePath] = $sourcePath;
                            }
                        }
                    }
                }

                $next = $offset + 4 + $sizeFieldBytes + $headerSize + $dataSize;
                if ($next <= $offset || $next > $fileSize) {
                    break;
                }
                $offset = $next;
                if ($headerType === self::HEADER_END) {
                    break;
                }
            }
            return $targets;
        } catch (\Throwable) {
            return [];
        } finally {
            fclose($handle);
        }
    }

    private function parseFilePath(string $headerData, int &$cursor, int $headerFlags, int $extraSize): ?string
    {
        $headerSize = strlen($headerData);
        $extraOffset = $headerSize - $extraSize;
        $fileFlags = $this->readVint($headerData, $cursor);
        $this->readVint($headerData, $cursor); // unpacked size
        $this->readVint($headerData, $cursor); // attributes
        if (($fileFlags & self::FHFL_UTIME) !== 0) {
            $cursor += 4;
        }
        if (($fileFlags & self::FHFL_CRC32) !== 0) {
            $cursor += 4;
        }
        if ($cursor < 0 || $cursor > $extraOffset) {
            return null;
        }
        $this->readVint($headerData, $cursor); // compression information
        $this->readVint($headerData, $cursor); // host OS
        $nameLength = $this->readVint($headerData, $cursor);
        if ($nameLength < 1 || $nameLength > 2048 || $cursor + $nameLength > $extraOffset) {
            return null;
        }
        $name = substr($headerData, $cursor, $nameLength);
        $cursor += $nameLength;
        return $this->normalizePath($name);
    }

    private function parseFileCopyTarget(string $extra): ?string
    {
        $cursor = 0;
        $length = strlen($extra);
        while ($cursor < $length) {
            $recordSize = $this->readVint($extra, $cursor);
            if ($recordSize < 1 || $cursor + $recordSize > $length) {
                return null;
            }
            $recordEnd = $cursor + $recordSize;
            $type = $this->readVint($extra, $cursor);
            if ($type === self::EXTRA_REDIRECTION) {
                $redirectionType = $this->readVint($extra, $cursor);
                $this->readVint($extra, $cursor); // flags
                $nameLength = $this->readVint($extra, $cursor);
                if ($redirectionType === self::REDIRECTION_FILECOPY
                    && $nameLength > 0
                    && $nameLength <= 2048
                    && $cursor + $nameLength <= $recordEnd) {
                    return $this->normalizePath(substr($extra, $cursor, $nameLength));
                }
            }
            $cursor = $recordEnd;
        }
        return null;
    }

    private function normalizePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            return null;
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $part = rtrim($part, " .\t\r\n");
            if ($part === '') {
                return null;
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            return null;
        }
        return implode('/', $parts);
    }

    /** @param resource $handle @return array{0:int,1:int} */
    private function readVintFromStream($handle): array
    {
        $value = 0;
        $shift = 0;
        for ($count = 1; $count <= 10; $count++) {
            $byte = fread($handle, 1);
            if (!is_string($byte) || strlen($byte) !== 1) {
                throw new \RuntimeException('Truncated RAR5 vint.');
            }
            $number = ord($byte);
            $value |= ($number & 0x7f) << $shift;
            if (($number & 0x80) === 0) {
                return [$value, $count];
            }
            $shift += 7;
            if ($shift > 56) {
                throw new \RuntimeException('RAR5 vint is too large.');
            }
        }
        throw new \RuntimeException('RAR5 vint is too long.');
    }

    private function readVint(string $data, int &$cursor): int
    {
        $value = 0;
        $shift = 0;
        $length = strlen($data);
        for ($count = 0; $count < 10; $count++) {
            if ($cursor >= $length) {
                throw new \RuntimeException('Truncated RAR5 header vint.');
            }
            $number = ord($data[$cursor++]);
            $value |= ($number & 0x7f) << $shift;
            if (($number & 0x80) === 0) {
                return $value;
            }
            $shift += 7;
            if ($shift > 56) {
                throw new \RuntimeException('RAR5 header vint is too large.');
            }
        }
        throw new \RuntimeException('RAR5 header vint is too long.');
    }

    private function encodeVint(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('RAR5 vint cannot be negative.');
        }
        $encoded = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            if ($value > 0) {
                $byte |= 0x80;
            }
            $encoded .= chr($byte);
        } while ($value > 0);
        return $encoded;
    }

    private function u32(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            throw new \RuntimeException('RAR5 uint32 is out of bounds.');
        }
        $value = unpack('Vvalue', substr($data, $offset, 4));
        return (int)($value['value'] ?? 0);
    }
}
