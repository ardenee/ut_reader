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

    // The ZIP specification only requires EOCD to be within 65,557 bytes of EOF,
    // but historical download mirrors sometimes append transport/readme/padding
    // data after a valid archive. Scan a bounded compatibility window and validate
    // every candidate against the central directory before accepting it.
    private const EOCD_SEARCH_BYTES = 16777216;

    // Some historical ZIP writers recorded local-header offsets without later
    // data-descriptor/rewriter adjustments. Only use this bounded recovery search
    // when the recorded offset itself is not a valid header for the member.
    private const LOCAL_HEADER_RECOVERY_BACKTRACK_BYTES = 65536;
    private const LOCAL_HEADER_RECOVERY_FORWARD_BYTES = 4194304;

    /** @var list<string> */
    private const CP437_HIGH_CHARS = [
        'Ç', 'ü', 'é', 'â', 'ä', 'à', 'å', 'ç', 'ê', 'ë', 'è', 'ï', 'î', 'ì', 'Ä', 'Å',
        'É', 'æ', 'Æ', 'ô', 'ö', 'ò', 'û', 'ù', 'ÿ', 'Ö', 'Ü', '¢', '£', '¥', '₧', 'ƒ',
        'á', 'í', 'ó', 'ú', 'ñ', 'Ñ', 'ª', 'º', '¿', '⌐', '¬', '½', '¼', '¡', '«', '»',
        '░', '▒', '▓', '│', '┤', '╡', '╢', '╖', '╕', '╣', '║', '╗', '╝', '╜', '╛', '┐',
        '└', '┴', '┬', '├', '─', '┼', '╞', '╟', '╚', '╔', '╩', '╦', '╠', '═', '╬', '╧',
        '╨', '╤', '╥', '╙', '╘', '╒', '╓', '╫', '╪', '┘', '┌', '█', '▄', '▌', '▐', '▀',
        'α', 'ß', 'Γ', 'π', 'Σ', 'σ', 'µ', 'τ', 'Φ', 'Θ', 'Ω', 'δ', '∞', 'φ', 'ε', '∩',
        '≡', '±', '≥', '≤', '⌠', '⌡', '÷', '≈', '°', '∙', '·', '√', 'ⁿ', '²', '■', ' ',
    ];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function hasLegacyCompression(string $archivePath): bool
    {
        // Legacy detection deliberately depends only on the central directory.
        // A stale local offset on an unrelated ordinary member must not prevent
        // discovery of a later method-6/method-9 entry.
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
     *   compressed_size:int,local_offset:int,central_boundary:int
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

                $rawName = $this->readExact($handle, $nameLength, 'central-directory filename');
                $extra = $extraLength > 0
                    ? $this->readExact($handle, $extraLength, 'central-directory extra data')
                    : '';
                if ($commentLength > 0) {
                    $this->readExact($handle, $commentLength, 'central-directory comment');
                }

                if ($compressedSize === 0xffffffff
                    || $uncompressedSize === 0xffffffff
                    || $recordedLocalOffset === 0xffffffff
                    || $diskStart === 0xffff) {
                    [$uncompressedSize, $compressedSize, $recordedLocalOffset, $diskStart]
                        = $this->resolveZip64MemberFields(
                            $extra,
                            $uncompressedSize,
                            $compressedSize,
                            $recordedLocalOffset,
                            $diskStart
                        );
                }
                if ($diskStart !== 0) {
                    throw new \RuntimeException('Native ZIP reader does not support members stored on another disk.');
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

                // Keep the central-directory local offset as metadata only. It is
                // resolved/validated lazily if this member is actually extracted.
                // This prevents one stale ordinary member offset from blocking
                // discovery of a later method-6/method-9 member.
                $localOffset = $recordedLocalOffset + $offsetAdjustment;

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
                    'local_offset' => $localOffset,
                    'central_boundary' => $physicalCentralOffset,
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
        $centralBoundary = (int)($entry['central_boundary'] ?? 0);
        $zipFailure = null;

        // Stored and normal DEFLATE entries do not require our raw compatibility
        // decoder. Prefer ext-zip by central-directory index, which also avoids
        // depending on stale local-header offsets present in some old archives.
        if (in_array($method, [0, 8], true)) {
            try {
                return $this->decodeViaZipExtension($archivePath, $entry, $output, $maxBytes, $heartbeat);
            } catch (\RuntimeException $error) {
                $zipFailure = $error;
                if (ftruncate($output, 0) === false || fseek($output, 0, SEEK_SET) !== 0) {
                    throw new \RuntimeException('Could not reset native ZIP temporary output after ZipArchive fallback.', 0, $error);
                }
            }
        }

        $input = @fopen($archivePath, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Native ZIP source could not be reopened for member extraction.');
        }
        try {
            $dataOffset = $this->memberDataOffset(
                $input,
                (int)($entry['local_offset'] ?? -1),
                $method,
                (string)$entry['path'],
                $centralBoundary
            );
            if ($dataOffset < 0 || $compressedSize < 0 || $dataOffset + $compressedSize > $centralBoundary) {
                throw new \RuntimeException(
                    'Native ZIP compressed member bounds are invalid for "' . (string)$entry['path'] . '".'
                );
            }
            if (fseek($input, $dataOffset, SEEK_SET) !== 0) {
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
        } catch (\Throwable $nativeFailure) {
            if ($zipFailure instanceof \Throwable) {
                throw new \RuntimeException(
                    'ZipArchive could not decode "' . (string)$entry['path']
                    . '" (' . $zipFailure->getMessage() . '); native local-header recovery also failed: '
                    . $nativeFailure->getMessage(),
                    0,
                    $nativeFailure
                );
            }
            throw $nativeFailure;
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
        $tailLength = strlen($tail);
        $searchEnd = $tailLength;
        while ($searchEnd >= 4) {
            $position = strrpos(substr($tail, 0, $searchEnd), self::EOCD_SIGNATURE);
            if ($position === false) {
                break;
            }
            if ($position + self::EOCD_MIN_BYTES <= $tailLength) {
                $candidate = substr($tail, $position, self::EOCD_MIN_BYTES);
                $commentLength = $this->u16($candidate, 20);
                $recordEnd = $position + self::EOCD_MIN_BYTES + $commentLength;
                $absoluteOffset = $start + $position;
                if ($recordEnd <= $tailLength && $this->isPlausibleEocd($handle, $absoluteOffset, $candidate)) {
                    return [$absoluteOffset, $candidate];
                }
            }
            $searchEnd = $position;
        }
        throw new \RuntimeException('Native ZIP end-of-central-directory record was not found.');
    }

    /** @param resource $handle */
    private function isPlausibleEocd($handle, int $eocdOffset, string $candidate): bool
    {
        try {
            $diskNumber = $this->u16($candidate, 4);
            $centralDisk = $this->u16($candidate, 6);
            $entriesOnDisk = $this->u16($candidate, 8);
            $entryCount = $this->u16($candidate, 10);
            $centralSize = $this->u32($candidate, 12);
            $recordedCentralOffset = $this->u32($candidate, 16);
        } catch (\Throwable) {
            return false;
        }

        if ($diskNumber !== 0 || $centralDisk !== 0 || $entriesOnDisk !== $entryCount) {
            return false;
        }
        if ($entryCount === 0xffff || $centralSize === 0xffffffff || $recordedCentralOffset === 0xffffffff) {
            return false;
        }
        if ($entryCount === 0) {
            return $centralSize === 0;
        }
        if ($centralSize < 46 || $centralSize > $eocdOffset) {
            return false;
        }

        $physicalCentralOffset = $eocdOffset - $centralSize;
        if ($this->hasSignature($handle, $physicalCentralOffset, self::CENTRAL_SIGNATURE)) {
            return true;
        }
        return $recordedCentralOffset >= 0
            && $recordedCentralOffset < $eocdOffset
            && $this->hasSignature($handle, $recordedCentralOffset, self::CENTRAL_SIGNATURE);
    }

    /** @param resource $handle */
    private function memberDataOffset(
        $handle,
        int $recordedLocalOffset,
        int $centralMethod,
        string $centralPath,
        int $centralBoundary
    ): int {
        $exact = $this->localHeaderCandidate(
            $handle,
            $recordedLocalOffset,
            $centralMethod,
            $centralPath,
            $centralBoundary
        );
        if (is_array($exact)) {
            return (int)$exact['data_offset'];
        }

        $scanStart = max(0, $recordedLocalOffset - self::LOCAL_HEADER_RECOVERY_BACKTRACK_BYTES);
        $scanEnd = min(
            max(0, $centralBoundary),
            max(0, $recordedLocalOffset) + self::LOCAL_HEADER_RECOVERY_FORWARD_BYTES
        );
        if ($scanEnd <= $scanStart || $scanEnd - $scanStart < 4) {
            throw new \RuntimeException(
                'Native ZIP local member header signature is invalid at recorded offset '
                . number_format($recordedLocalOffset) . ' for "' . $centralPath . '".'
            );
        }
        if (fseek($handle, $scanStart, SEEK_SET) !== 0) {
            throw new \RuntimeException('Native ZIP local-header recovery search could not position the source.');
        }
        $window = $this->readExact($handle, $scanEnd - $scanStart, 'local-header recovery search');

        $bestNameMatch = null;
        $bestNameDistance = PHP_INT_MAX;
        $bestMethodMatch = null;
        $bestMethodDistance = PHP_INT_MAX;
        $cursor = 0;
        while (($relative = strpos($window, self::LOCAL_SIGNATURE, $cursor)) !== false) {
            $candidateOffset = $scanStart + $relative;
            $candidate = $this->localHeaderCandidate(
                $handle,
                $candidateOffset,
                $centralMethod,
                $centralPath,
                $centralBoundary
            );
            if (is_array($candidate)) {
                $distance = abs($candidateOffset - $recordedLocalOffset);
                if (!empty($candidate['path_match'])) {
                    if ($distance < $bestNameDistance) {
                        $bestNameDistance = $distance;
                        $bestNameMatch = $candidate;
                    }
                } elseif ($distance < $bestMethodDistance) {
                    $bestMethodDistance = $distance;
                    $bestMethodMatch = $candidate;
                }
            }
            $cursor = $relative + 1;
        }

        $resolved = is_array($bestNameMatch) ? $bestNameMatch : $bestMethodMatch;
        if (is_array($resolved)) {
            return (int)$resolved['data_offset'];
        }

        throw new \RuntimeException(
            'Native ZIP could not recover a valid local member header for "' . $centralPath
            . '" near recorded offset ' . number_format($recordedLocalOffset) . '.'
        );
    }

    /**
     * @param resource $handle
     * @return null|array{data_offset:int,path_match:bool}
     */
    private function localHeaderCandidate(
        $handle,
        int $offset,
        int $centralMethod,
        string $centralPath,
        int $centralBoundary
    ): ?array {
        if ($offset < 0 || $centralBoundary < 1 || $offset + 30 > $centralBoundary) {
            return null;
        }
        if (fseek($handle, $offset, SEEK_SET) !== 0) {
            return null;
        }
        $header = fread($handle, 30);
        if (!is_string($header) || strlen($header) !== 30 || substr($header, 0, 4) !== self::LOCAL_SIGNATURE) {
            return null;
        }

        $flags = $this->u16($header, 6);
        $method = $this->u16($header, 8);
        $nameLength = $this->u16($header, 26);
        $extraLength = $this->u16($header, 28);
        if ($method !== $centralMethod || $nameLength < 1) {
            return null;
        }

        $dataOffset = $offset + 30 + $nameLength + $extraLength;
        if ($dataOffset <= $offset || $dataOffset > $centralBoundary) {
            return null;
        }

        $rawName = fread($handle, $nameLength);
        if (!is_string($rawName) || strlen($rawName) !== $nameLength) {
            return null;
        }
        try {
            $localPath = str_replace('\\', '/', $this->decodeName($rawName, $flags));
        } catch (\Throwable) {
            $localPath = '';
        }
        $centralNormalized = str_replace('\\', '/', $centralPath);

        return [
            'data_offset' => $dataOffset,
            'path_match' => $localPath !== '' && hash_equals($centralNormalized, $localPath),
        ];
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

    /**
     * Resolve ZIP64 sentinel values from the central-directory extra field.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function resolveZip64MemberFields(
        string $extra,
        int $uncompressedSize,
        int $compressedSize,
        int $localOffset,
        int $diskStart
    ): array {
        $zip64 = $this->extraField($extra, 0x0001);
        if ($zip64 === null) {
            throw new \RuntimeException('ZIP64 member fields are present but the ZIP64 extra record is missing.');
        }

        $cursor = 0;
        if ($uncompressedSize === 0xffffffff) {
            $uncompressedSize = $this->u64($zip64, $cursor, 'ZIP64 uncompressed size');
            $cursor += 8;
        }
        if ($compressedSize === 0xffffffff) {
            $compressedSize = $this->u64($zip64, $cursor, 'ZIP64 compressed size');
            $cursor += 8;
        }
        if ($localOffset === 0xffffffff) {
            $localOffset = $this->u64($zip64, $cursor, 'ZIP64 local-header offset');
            $cursor += 8;
        }
        if ($diskStart === 0xffff) {
            if ($cursor + 4 > strlen($zip64)) {
                throw new \RuntimeException('ZIP64 disk-start field is truncated.');
            }
            $diskStart = $this->u32($zip64, $cursor);
        }

        return [$uncompressedSize, $compressedSize, $localOffset, $diskStart];
    }

    private function extraField(string $extra, int $wantedId): ?string
    {
        $cursor = 0;
        $length = strlen($extra);
        while ($cursor + 4 <= $length) {
            $id = $this->u16($extra, $cursor);
            $size = $this->u16($extra, $cursor + 2);
            $cursor += 4;
            if ($cursor + $size > $length) {
                throw new \RuntimeException('ZIP central-directory extra field is truncated.');
            }
            $payload = substr($extra, $cursor, $size);
            if ($id === $wantedId) {
                return $payload;
            }
            $cursor += $size;
        }
        return null;
    }

    private function u64(string $data, int $offset, string $label): int
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException($label . ' requires a 64-bit PHP runtime.');
        }
        if ($offset < 0 || $offset + 8 > strlen($data)) {
            throw new \RuntimeException($label . ' is truncated.');
        }
        $parts = unpack('Vlow/Vhigh', substr($data, $offset, 8));
        $low = (int)($parts['low'] ?? 0);
        $high = (int)($parts['high'] ?? 0);
        if ($high > 0x7fffffff) {
            throw new \RuntimeException($label . ' exceeds the supported signed 64-bit range.');
        }
        return ($high << 32) | $low;
    }

    private function decodeName(string $rawName, int $flags): string
    {
        if (($flags & 0x0800) !== 0) {
            if (preg_match('//u', $rawName) !== 1) {
                throw new \RuntimeException('ZIP filename is marked UTF-8 but contains invalid UTF-8 bytes.');
            }
            return $rawName;
        }

        // ZIP's historical default filename character set is IBM Code Page 437.
        // Do not depend on mbstring/iconv aliases here: Windows PHP builds differ
        // in which legacy encoding names they expose, and mb_convert_encoding()
        // throws ValueError before decompression on builds where CP437 is absent.
        $decoded = '';
        $length = strlen($rawName);
        for ($index = 0; $index < $length; $index++) {
            $byte = ord($rawName[$index]);
            $decoded .= $byte < 0x80 ? $rawName[$index] : self::CP437_HIGH_CHARS[$byte - 0x80];
        }
        return $decoded;
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
