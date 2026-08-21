<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/**
 * Native-PHP compatibility reader for ZIP members that ext-zip/libarchive can
 * list but cannot decode, notably historic PKZIP Implode (6) and Deflate64 (9).
 *
 * The central directory is parsed directly so compressed member bytes remain
 * accessible even when ZipArchive::getStreamIndex() rejects the compression
 * method. No external executable or shell process is used.
 */
final class CatalogNativeZipArchiveReader
{
    private const EOCD_SIGNATURE = "PK\x05\x06";
    private const CENTRAL_SIGNATURE = "PK\x01\x02";
    private const LOCAL_SIGNATURE = "PK\x03\x04";
    private const EOCD_MIN_BYTES = 22;
    private const EOCD_SEARCH_BYTES = 65557;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function hasLegacyCompression(string $archivePath): bool
    {
        foreach ($this->entries($archivePath) as $entry) {
            if (in_array((int)$entry['compression_method'], [6, 9], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param callable(array<string,mixed>):array{extract:bool,max_bytes?:int,state?:mixed} $plan
     * @param callable(array<string,mixed>,?string,mixed):void $complete
     * @param null|callable():void $heartbeat
     * @return array{entries:int,decoded_bytes:int,format:string}
     */
    public function walk(
        string $archivePath,
        string $archiveName,
        int $maxDecodedBytes,
        callable $plan,
        callable $complete,
        ?callable $heartbeat = null
    ): array {
        $this->requireSource($archivePath, $archiveName);
        $entries = $this->entries($archivePath);
        $maxDecodedBytes = max(1, $maxDecodedBytes);
        $decodedBytes = 0;
        $processed = 0;

        foreach ($entries as $entry) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $processed++;
            $decision = $plan($entry);
            if (!is_array($decision) || !array_key_exists('extract', $decision)) {
                throw new \LogicException('Native ZIP plan must return an extract decision.');
            }
            $extract = (bool)$decision['extract'];
            $state = $decision['state'] ?? null;
            if (!$extract) {
                $complete($entry, null, $state);
                continue;
            }

            $entryLimit = max(1, (int)($decision['max_bytes'] ?? $maxDecodedBytes));
            $expectedBytes = max(0, (int)$entry['size']);
            if ($expectedBytes < 1 || $expectedBytes > $entryLimit) {
                throw new \RuntimeException('Native ZIP member has an invalid or oversized uncompressed size.');
            }
            $remainingTotal = $maxDecodedBytes - $decodedBytes;
            if ($remainingTotal < $expectedBytes) {
                throw new \RuntimeException(
                    'Archive expansion exceeds the configured total unpacked-data limit of '
                    . number_format($maxDecodedBytes) . ' bytes.'
                );
            }

            $temporary = $this->temporaryPath();
            $output = @fopen($temporary, 'wb');
            if (!is_resource($output)) {
                @unlink($temporary);
                throw new \RuntimeException('Could not create temporary native ZIP member storage.');
            }

            try {
                $result = $this->decodeEntry(
                    $archivePath,
                    $entry,
                    $output,
                    min($entryLimit, $remainingTotal),
                    $heartbeat
                );
                fflush($output);
                fclose($output);
                $output = null;

                $expectedCrc = strtolower((string)$entry['crc32']);
                if (!hash_equals($expectedCrc, strtolower((string)$result['crc32']))) {
                    throw new \RuntimeException(
                        'Native ZIP CRC32 mismatch for "' . (string)$entry['path'] . '"; expected '
                        . $expectedCrc . ', got ' . strtolower((string)$result['crc32']) . '.'
                    );
                }
                if ((int)$result['bytes'] !== $expectedBytes) {
                    throw new \RuntimeException(
                        'Native ZIP output size mismatch for "' . (string)$entry['path'] . '".'
                    );
                }
                $this->verifyTemporary($temporary, $expectedBytes, $entryLimit);
                $decodedBytes += $expectedBytes;

                // Preserve the coordinator contract: the callback owns staging
                // the temporary file before this method removes it.
                $complete($entry, $temporary, $state);
            } finally {
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        return [
            'entries' => $processed,
            'decoded_bytes' => $decodedBytes,
            'format' => 'zip-native-php',
        ];
    }

    /**
     * @return list<array{
     *   index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,
     *   backend:string,format:string,compression_method:int,flags:int,crc32:string,
     *   compressed_size:int,data_offset:int
     * }>
     */
    private function entries(string $archivePath): array
    {
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Native ZIP source is unavailable.');
        }
        $fileSize = filesize($archivePath);
        if ($fileSize === false || $fileSize < self::EOCD_MIN_BYTES) {
            throw new \RuntimeException('Native ZIP source is too small to contain an end-of-central-directory record.');
        }
        $fileSize = (int)$fileSize;
        $handle = @fopen($archivePath, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Native ZIP source could not be opened.');
        }

        try {
            [$eocdOffset, $eocd] = $this->findEocd($handle, $fileSize);
            $diskNumber = $this->u16($eocd, 4);
            $centralDisk = $this->u16($eocd, 6);
            $entriesOnDisk = $this->u16($eocd, 8);
            $entryCount = $this->u16($eocd, 10);
            $centralSize = $this->u32($eocd, 12);
            $recordedCentralOffset = $this->u32($eocd, 16);
            if ($diskNumber !== 0 || $centralDisk !== 0 || $entriesOnDisk !== $entryCount) {
                throw new \RuntimeException('Native ZIP reader does not support split or multi-disk archives.');
            }
            if ($entryCount === 0xffff || $centralSize === 0xffffffff || $recordedCentralOffset === 0xffffffff) {
                throw new \RuntimeException('Native legacy ZIP decoding does not support ZIP64 central directories.');
            }
            if ($entryCount > $this->maxEntries()) {
                throw new \RuntimeException(
                    'Archive contains too many entries; limit is ' . number_format($this->maxEntries()) . '.'
                );
            }

            // Deriving the physical central-directory start from the EOCD makes
            // prepended SFX stubs safe: old ZIP writers sometimes stored offsets
            // relative to the ZIP payload rather than the executable prefix.
            $physicalCentralOffset = $eocdOffset - $centralSize;
            if ($physicalCentralOffset < 0 || !$this->hasSignature($handle, $physicalCentralOffset, self::CENTRAL_SIGNATURE)) {
                $physicalCentralOffset = $recordedCentralOffset;
            }
            if ($physicalCentralOffset < 0 || $physicalCentralOffset + $centralSize > $eocdOffset) {
                throw new \RuntimeException('Native ZIP central-directory bounds are invalid.');
            }
            if (!$this->hasSignature($handle, $physicalCentralOffset, self::CENTRAL_SIGNATURE) && $entryCount > 0) {
                throw new \RuntimeException('Native ZIP central-directory signature was not found.');
            }
            $offsetAdjustment = $physicalCentralOffset - $recordedCentralOffset;

            if (fseek($handle, $physicalCentralOffset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Native ZIP central directory could not be positioned.');
            }
            $entries = [];
            for ($index = 0; $index < $entryCount; $index++) {
                $header = $this->readExact($handle, 46, 'central-directory header');
                if (substr($header, 0, 4) !== self::CENTRAL_SIGNATURE) {
                    throw new \RuntimeException('Native ZIP central-directory entry signature is invalid.');
                }
                $versionMade = $this->u16($header, 4);
                $flags = $this->u16($header, 8);
                $method = $this->u16($header, 10);
                $crc = $this->u32($header, 16);
                $compressedSize = $this->u32($header, 20);
                $uncompressedSize = $this->u32($header, 24);
                $nameLength = $this->u16($header, 28);
                $extraLength = $this->u16($header, 30);
                $commentLength = $this->u16($header, 32);
                $diskStart = $this->u16($header, 34);
                $externalAttributes = $this->u32($header, 38);
                $recordedLocalOffset = $this->u32($header, 42);
                if ($compressedSize === 0xffffffff || $uncompressedSize === 0xffffffff || $recordedLocalOffset === 0xffffffff) {
                    throw new \RuntimeException('Native legacy ZIP decoding does not support ZIP64 member fields.');
                }
                if ($diskStart !== 0) {
                    throw new \RuntimeException('Native ZIP reader does not support members stored on another disk.');
                }

                $rawName = $this->readExact($handle, $nameLength, 'central-directory filename');
                if ($extraLength > 0) {
                    $this->readExact($handle, $extraLength, 'central-directory extra data');
                }
                if ($commentLength > 0) {
                    $this->readExact($handle, $commentLength, 'central-directory comment');
                }
                $nextCentralOffset = ftell($handle);
                if (!is_int($nextCentralOffset)) {
                    throw new \RuntimeException('Native ZIP central-directory cursor could not be read.');
                }
                $name = $this->decodeName($rawName, $flags);
                $normalized = str_replace('\\', '/', $name);
                if ($normalized === '' || str_ends_with($normalized, '/')) {
                    continue;
                }

                [$safePath, $reason] = $this->safeMemberPath($name);
                $hostSystem = ($versionMade >> 8) & 0xff;
                $fileType = ($externalAttributes >> 16) & 0170000;
                if ($hostSystem === 3 && $fileType === 0120000) {
                    $safePath = '';
                    $reason = 'symbolic-link entries are not accepted';
                }
                $encrypted = ($flags & 0x0001) !== 0;
                $localOffset = $recordedLocalOffset + $offsetAdjustment;
                $dataOffset = $this->memberDataOffset($handle, $localOffset, $method, $rawName);
                if (fseek($handle, $nextCentralOffset, SEEK_SET) !== 0) {
                    throw new \RuntimeException('Native ZIP central-directory cursor could not be restored.');
                }
                if ($dataOffset < 0 || $dataOffset + $compressedSize > $physicalCentralOffset) {
                    throw new \RuntimeException('Native ZIP compressed member bounds are invalid for "' . $normalized . '".');
                }

                $entries[] = [
                    'index' => $index,
                    'path' => $safePath !== '' ? $safePath : $normalized,
                    'size' => $uncompressedSize,
                    'encrypted' => $encrypted,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'php-native-zip',
                    'format' => 'zip',
                    'compression_method' => $method,
                    'flags' => $flags,
                    'crc32' => sprintf('%08x', $crc),
                    'compressed_size' => $compressedSize,
                    'data_offset' => $dataOffset,
                ];
            }
            return $entries;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @param resource $output
     * @return array{bytes:int,crc32:string}
     */
    private function decodeEntry(
        string $archivePath,
        array $entry,
        $output,
        int $maxBytes,
        ?callable $heartbeat
    ): array {
        if (!empty($entry['encrypted'])) {
            throw new \RuntimeException('Encrypted/password-protected ZIP members are not supported.');
        }
        if (empty($entry['safe'])) {
            throw new \RuntimeException('Unsafe ZIP member path: ' . (string)($entry['reason'] ?? 'invalid path'));
        }

        $method = (int)$entry['compression_method'];
        $compressedSize = max(0, (int)$entry['compressed_size']);
        $expectedBytes = max(0, (int)$entry['size']);
        $input = @fopen($archivePath, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Native ZIP source could not be reopened for member extraction.');
        }
        try {
            if (fseek($input, (int)$entry['data_offset'], SEEK_SET) !== 0) {
                throw new \RuntimeException('Native ZIP member payload could not be positioned.');
            }
            return match ($method) {
                0 => $this->decodeStored($input, $output, $compressedSize, $expectedBytes, $maxBytes, $heartbeat),
                6 => (new CatalogZipImplodeDecoder())->decode(
                    $input,
                    $output,
                    $compressedSize,
                    $expectedBytes,
                    (int)$entry['flags'],
                    $maxBytes,
                    $heartbeat
                ),
                8 => $this->decodeDeflate($input, $output, $compressedSize, $expectedBytes, $maxBytes, $heartbeat),
                9 => (new CatalogZipDeflate64Decoder())->decode(
                    $input,
                    $output,
                    $compressedSize,
                    $expectedBytes,
                    $maxBytes,
                    $heartbeat
                ),
                default => $this->decodeViaZipExtension($archivePath, $entry, $output, $maxBytes, $heartbeat),
            };
        } finally {
            fclose($input);
        }
    }

    /** @param resource $input @param resource $output @return array{bytes:int,crc32:string} */
    private function decodeStored(
        $input,
        $output,
        int $compressedBytes,
        int $expectedBytes,
        int $maxBytes,
        ?callable $heartbeat
    ): array {
        if ($compressedBytes !== $expectedBytes) {
            throw new \RuntimeException('Stored ZIP member compressed and uncompressed sizes do not match.');
        }
        $writer = new CatalogZipOutputWriter(
            $output,
            $maxBytes,
            $expectedBytes,
            1,
            $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null
        );
        $remaining = $compressedBytes;
        while ($remaining > 0) {
            $take = min($remaining, 65536);
            $data = $this->readExact($input, $take, 'stored ZIP member');
            $writer->writeString($data);
            $remaining -= $take;
        }
        return $writer->finish();
    }

    /** @param resource $input @param resource $output @return array{bytes:int,crc32:string} */
    private function decodeDeflate(
        $input,
        $output,
        int $compressedBytes,
        int $expectedBytes,
        int $maxBytes,
        ?callable $heartbeat
    ): array {
        if (!function_exists('inflate_init') || !function_exists('inflate_add')) {
            throw new \RuntimeException('Deflated ZIP member requires the PHP zlib extension.');
        }
        $inflater = inflate_init(ZLIB_ENCODING_RAW);
        if ($inflater === false) {
            throw new \RuntimeException('PHP zlib could not initialize a raw DEFLATE stream.');
        }
        $writer = new CatalogZipOutputWriter(
            $output,
            $maxBytes,
            $expectedBytes,
            32768,
            $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null
        );
        $remaining = $compressedBytes;
        while ($remaining > 0) {
            $take = min($remaining, 65536);
            $chunk = $this->readExact($input, $take, 'deflated ZIP member');
            $remaining -= $take;
            $decoded = inflate_add($inflater, $chunk, $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if (!is_string($decoded)) {
                throw new \RuntimeException('PHP zlib failed while decoding a raw DEFLATE ZIP member.');
            }
            $writer->writeString($decoded);
        }
        return $writer->finish();
    }

    /** @param array<string,mixed> $entry @param resource $output @return array{bytes:int,crc32:string} */
    private function decodeViaZipExtension(
        string $archivePath,
        array $entry,
        $output,
        int $maxBytes,
        ?callable $heartbeat
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'ZIP compression method ' . (int)$entry['compression_method']
                . ' is not implemented by the native compatibility reader and ext-zip is unavailable.'
            );
        }
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('PHP ZipArchive could not reopen ZIP for compatibility extraction.');
        }
        try {
            if (method_exists($zip, 'getStreamIndex')) {
                $stream = @$zip->getStreamIndex((int)$entry['index'], \ZipArchive::FL_UNCHANGED);
            } else {
                $stream = @$zip->getStream((string)$entry['path']);
            }
            if (!is_resource($stream)) {
                throw new \RuntimeException(
                    'ZIP compression method ' . (int)$entry['compression_method']
                    . ' is unsupported by both the native PHP compatibility decoder and ZipArchive.'
                );
            }
            $writer = new CatalogZipOutputWriter(
                $output,
                $maxBytes,
                (int)$entry['size'],
                65536,
                $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null
            );
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if (!is_string($chunk)) {
                    throw new \RuntimeException('ZipArchive compatibility stream could not be read.');
                }
                if ($chunk === '') {
                    if (feof($stream)) {
                        break;
                    }
                    throw new \RuntimeException('ZipArchive compatibility stream stopped unexpectedly.');
                }
                $writer->writeString($chunk);
            }
            return $writer->finish();
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
            $zip->close();
        }
    }

    /** @param resource $handle @return array{int,string} */
    private function findEocd($handle, int $fileSize): array
    {
        $readBytes = min($fileSize, self::EOCD_SEARCH_BYTES);
        $start = $fileSize - $readBytes;
        if (fseek($handle, $start, SEEK_SET) !== 0) {
            throw new \RuntimeException('Native ZIP EOCD search could not position the source.');
        }
        $tail = $this->readExact($handle, $readBytes, 'ZIP end-of-central-directory search');
        $searchEnd = strlen($tail);
        while ($searchEnd >= 4) {
            $position = strrpos(substr($tail, 0, $searchEnd), self::EOCD_SIGNATURE);
            if ($position === false) {
                break;
            }
            if ($position + self::EOCD_MIN_BYTES <= strlen($tail)) {
                $candidate = substr($tail, $position, self::EOCD_MIN_BYTES);
                $commentLength = $this->u16($candidate, 20);
                if ($position + self::EOCD_MIN_BYTES + $commentLength === strlen($tail)) {
                    return [$start + $position, $candidate];
                }
            }
            $searchEnd = $position;
        }
        throw new \RuntimeException('Native ZIP end-of-central-directory record was not found.');
    }

    /** @param resource $handle */
    private function memberDataOffset($handle, int $localOffset, int $centralMethod, string $centralRawName): int
    {
        if ($localOffset < 0 || fseek($handle, $localOffset, SEEK_SET) !== 0) {
            throw new \RuntimeException('Native ZIP local member header offset is invalid.');
        }
        $header = $this->readExact($handle, 30, 'local ZIP member header');
        if (substr($header, 0, 4) !== self::LOCAL_SIGNATURE) {
            throw new \RuntimeException('Native ZIP local member header signature is invalid.');
        }
        $method = $this->u16($header, 8);
        $nameLength = $this->u16($header, 26);
        $extraLength = $this->u16($header, 28);
        if ($method !== $centralMethod) {
            throw new \RuntimeException('Native ZIP local/central compression methods disagree.');
        }
        $localRawName = $this->readExact($handle, $nameLength, 'local ZIP filename');
        if (!hash_equals($centralRawName, $localRawName)) {
            throw new \RuntimeException('Native ZIP local/central member names disagree.');
        }
        return $localOffset + 30 + $nameLength + $extraLength;
    }

    /** @param resource $handle */
    private function hasSignature($handle, int $offset, string $signature): bool
    {
        if ($offset < 0 || fseek($handle, $offset, SEEK_SET) !== 0) {
            return false;
        }
        $value = fread($handle, strlen($signature));
        return is_string($value) && hash_equals($signature, $value);
    }

    /** @param resource $handle */
    private function readExact($handle, int $bytes, string $label): string
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Native ZIP read length cannot be negative.');
        }
        if ($bytes === 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($handle, $bytes - strlen($data));
            if (!is_string($chunk) || $chunk === '') {
                throw new \RuntimeException('Native ZIP ' . $label . ' ended unexpectedly.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function u16(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) {
            throw new \RuntimeException('Native ZIP 16-bit field is out of bounds.');
        }
        $value = unpack('vvalue', substr($data, $offset, 2));
        return (int)($value['value'] ?? 0);
    }

    private function u32(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            throw new \RuntimeException('Native ZIP 32-bit field is out of bounds.');
        }
        $value = unpack('Vvalue', substr($data, $offset, 4));
        return (int)($value['value'] ?? 0);
    }

    private function decodeName(string $rawName, int $flags): string
    {
        if (($flags & 0x0800) !== 0) {
            return preg_match('//u', $rawName) === 1 ? $rawName : $rawName;
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($rawName, 'UTF-8', 'CP437');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $rawName;
    }

    /** @return array{0:string,1:string} */
    private function safeMemberPath(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            return ['', 'empty/control-character path'];
        }
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return ['', 'absolute path'];
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return ['', 'parent-directory traversal'];
            }
            $part = rtrim($part, " .\t\r\n");
            if ($part === '') {
                return ['', 'empty path component'];
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            return ['', 'empty normalized path'];
        }
        $safe = implode('/', $parts);
        if (strlen($safe) > 2048) {
            return ['', 'path is too long'];
        }
        return [$safe, ''];
    }

    private function requireSource(string $archivePath, string $archiveName): void
    {
        if (strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \InvalidArgumentException('Native ZIP compatibility reader only accepts ZIP archives.');
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Native ZIP source is unavailable.');
        }
    }

    private function verifyTemporary(string $path, int $expectedBytes, int $maxBytes): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Native ZIP decoder did not produce a regular temporary file.');
        }
        $size = filesize($path);
        if ($size === false || (int)$size !== $expectedBytes || (int)$size > $maxBytes) {
            throw new \RuntimeException('Native ZIP temporary file size verification failed.');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-zip-native-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate native ZIP temporary storage.');
        }
        return $path;
    }

    private function maxEntries(): int
    {
        return max(1, min(100000, (int)($this->config['archive']['max_entries'] ?? 10000)));
    }
}
