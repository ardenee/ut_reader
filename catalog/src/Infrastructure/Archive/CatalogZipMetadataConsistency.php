<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/**
 * Detects old ZIPs whose final central directory disagrees with a complete local
 * member header. This is a routing hint only; decoded output is still size/CRC
 * verified before ingestion by CatalogSequentialArchiveReader.
 */
final class CatalogZipMetadataConsistency
{
    private const LOCAL_SIGNATURE = "PK\x03\x04";
    private const MAX_SCAN_BYTES = 16777216;

    public function hasTrustedLocalMetadataMismatch(string $archivePath): bool
    {
        if (!class_exists(\ZipArchive::class)
            || !is_file($archivePath)
            || !is_readable($archivePath)
            || is_link($archivePath)) {
            return false;
        }
        $fileSize = filesize($archivePath);
        if ($fileSize === false || (int)$fileSize < 30 || (int)$fileSize > self::MAX_SCAN_BYTES) {
            return false;
        }
        $fileSize = (int)$fileSize;

        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            return false;
        }
        $stats = [];
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    continue;
                }
                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $stats[$name] = [
                    'size' => max(0, (int)($stat['size'] ?? 0)),
                    'compressed_size' => max(0, (int)($stat['comp_size'] ?? 0)),
                    'crc32' => strtolower(sprintf('%08x', (int)($stat['crc'] ?? 0))),
                    'method' => isset($stat['comp_method']) ? (int)$stat['comp_method'] : null,
                ];
            }
        } finally {
            $zip->close();
        }
        if ($stats === []) {
            return false;
        }

        $scan = @fopen($archivePath, 'rb');
        $probe = @fopen($archivePath, 'rb');
        if (!is_resource($scan) || !is_resource($probe)) {
            if (is_resource($scan)) {
                fclose($scan);
            }
            if (is_resource($probe)) {
                fclose($probe);
            }
            return false;
        }

        try {
            $offset = 0;
            $carry = '';
            while (!feof($scan)) {
                $chunk = fread($scan, 1024 * 1024);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $window = $carry . $chunk;
                $baseOffset = $offset - strlen($carry);
                $cursor = 0;
                while (($position = strpos($window, self::LOCAL_SIGNATURE, $cursor)) !== false) {
                    $candidateOffset = $baseOffset + $position;
                    if ($candidateOffset >= 0 && $this->candidateDisagrees(
                        $probe,
                        $candidateOffset,
                        $fileSize,
                        $stats
                    )) {
                        return true;
                    }
                    $cursor = $position + 1;
                }
                $carry = strlen($window) > 3 ? substr($window, -3) : $window;
                $offset += strlen($chunk);
            }
        } finally {
            fclose($scan);
            fclose($probe);
        }
        return false;
    }

    /**
     * @param resource $handle
     * @param array<string,array{size:int,compressed_size:int,crc32:string,method:?int}> $stats
     */
    private function candidateDisagrees($handle, int $offset, int $fileSize, array $stats): bool
    {
        if ($offset + 30 > $fileSize || fseek($handle, $offset, SEEK_SET) !== 0) {
            return false;
        }
        $header = fread($handle, 30);
        if (!is_string($header) || strlen($header) !== 30 || substr($header, 0, 4) !== self::LOCAL_SIGNATURE) {
            return false;
        }

        $flags = $this->u16($header, 6);
        $method = $this->u16($header, 8);
        $crc = $this->u32($header, 14);
        $compressed = $this->u32($header, 18);
        $uncompressed = $this->u32($header, 22);
        $nameLength = $this->u16($header, 26);
        $extraLength = $this->u16($header, 28);

        // Bit 3 means the local size/CRC fields are intentionally deferred to a
        // data descriptor and are therefore not authoritative comparison data.
        if (($flags & 0x0008) !== 0 || $nameLength < 1 || $nameLength > 2048) {
            return false;
        }
        $dataOffset = $offset + 30 + $nameLength + $extraLength;
        if ($dataOffset <= $offset || $compressed < 1 || $uncompressed < 1 || $dataOffset + $compressed > $fileSize) {
            return false;
        }

        $rawName = fread($handle, $nameLength);
        if (!is_string($rawName) || strlen($rawName) !== $nameLength) {
            return false;
        }
        $name = str_replace('\\', '/', $rawName);
        $stat = $stats[$name] ?? null;
        if (!is_array($stat)) {
            return false;
        }
        if ($stat['method'] !== null && $stat['method'] !== $method) {
            return false;
        }

        $localCrc = strtolower(sprintf('%08x', $crc));
        return $stat['size'] !== $uncompressed
            || $stat['compressed_size'] !== $compressed
            || !hash_equals($stat['crc32'], $localCrc);
    }

    private function u16(string $data, int $offset): int
    {
        $value = unpack('vvalue', substr($data, $offset, 2));
        return (int)($value['value'] ?? 0);
    }

    private function u32(string $data, int $offset): int
    {
        $value = unpack('Vvalue', substr($data, $offset, 4));
        return (int)($value['value'] ?? 0);
    }
}
