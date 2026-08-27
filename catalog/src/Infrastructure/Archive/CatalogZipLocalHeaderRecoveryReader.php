<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/**
 * Recovers ZIPs whose final central directory contains stale size/CRC metadata.
 *
 * The normal libarchive ZIP iterator rejects these archives before a member can
 * be handed to the coordinator. This reader instead keeps the final central
 * directory as the logical member list, finds exact same-name local headers,
 * and decodes Stored/DEFLATE payloads using the local header's own bounded
 * compressed size, uncompressed size and CRC32. A local member is accepted only
 * after exact size + CRC verification. No external executable/process is used.
 */
final class CatalogZipLocalHeaderRecoveryReader
{
    private const LOCAL_SIGNATURE = "PK\x03\x04";
    private const SCAN_CHUNK_BYTES = 1048576;

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
        $centralEntries = $this->centralEntries($archivePath);
        $localCandidates = $this->localCandidates($archivePath);
        $maxDecodedBytes = max(1, $maxDecodedBytes);
        $decodedBytes = 0;
        $processed = 0;

        foreach ($centralEntries as $centralEntry) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $processed++;
            $path = (string)$centralEntry['path'];
            $candidates = $localCandidates[$path] ?? [];
            $candidate = $this->bestCandidate($centralEntry, $candidates);

            if (is_array($candidate)) {
                $entry = $centralEntry;
                $entry['size'] = (int)$candidate['size'];
                $entry['crc32'] = (string)$candidate['crc32'];
                $entry['compressed_size'] = (int)$candidate['compressed_size'];
                $entry['compression_method'] = (int)$candidate['compression_method'];
                $entry['flags'] = (int)$candidate['flags'];
                $entry['backend'] = 'php-native-zip-local-recovery';
            } else {
                $entry = $centralEntry;
                $entry['backend'] = 'php-native-zip-local-recovery';
            }

            $decision = $plan($entry);
            if (!is_array($decision) || !array_key_exists('extract', $decision)) {
                throw new \LogicException('ZIP local-header recovery plan must return an extract decision.');
            }
            $extract = (bool)$decision['extract'];
            $state = $decision['state'] ?? null;
            $entryLimit = max(1, (int)($decision['max_bytes'] ?? $maxDecodedBytes));

            if (!$extract) {
                $complete($entry, null, $state);
                continue;
            }

            if (!is_array($candidate)) {
                $complete(
                    $entry,
                    null,
                    [
                        'kind' => 'failed',
                        'reason' => 'ZIP central directory references "' . $path
                            . '" but no same-name recoverable local member header exists.',
                    ]
                );
                continue;
            }

            $expectedBytes = max(0, (int)$candidate['size']);
            if ($expectedBytes < 1 || $expectedBytes > $entryLimit) {
                $complete(
                    $entry,
                    null,
                    [
                        'kind' => 'failed',
                        'reason' => 'Recovered ZIP local member "' . $path . '" has invalid/oversized size '
                            . number_format($expectedBytes) . ' bytes.',
                    ]
                );
                continue;
            }
            $remainingTotal = $maxDecodedBytes - $decodedBytes;
            if ($remainingTotal < $expectedBytes) {
                throw new \RuntimeException(
                    'Archive expansion exceeds the configured total unpacked-data limit of '
                    . number_format($maxDecodedBytes) . ' bytes.'
                );
            }

            $temporary = $this->temporaryPath();
            $failure = '';
            $result = $this->decodeCandidate(
                $archivePath,
                $candidate,
                $temporary,
                min($entryLimit, $remainingTotal),
                $heartbeat,
                $failure
            );
            if (!is_array($result)) {
                @unlink($temporary);
                $complete(
                    $entry,
                    null,
                    [
                        'kind' => 'failed',
                        'reason' => 'Recovered ZIP local member "' . $path . '" could not be decoded: '
                            . ($failure !== '' ? $failure : 'unknown local-header decode failure'),
                    ]
                );
                continue;
            }

            $actualBytes = (int)$result['bytes'];
            $decodedBytes += $actualBytes;
            try {
                $complete($entry, $temporary, $state);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        return [
            'entries' => $processed,
            'decoded_bytes' => $decodedBytes,
            'format' => 'zip-local-header-recovery',
        ];
    }

    /**
     * Recover one exact ZIP member from authoritative local-header metadata.
     *
     * This is used only after the normal ZipArchive member read has failed. The
     * recovered bytes are accepted only when the same-name local header provides
     * bounded size/compressed-size metadata and the decoded CRC32 matches it.
     */
    public function extractExactMember(
        string $archivePath,
        string $entryPath,
        int $maxBytes
    ): string {
        $this->requireSource($archivePath, 'recovery.zip');
        $entryPath = ltrim(str_replace('\\\\', '/', trim($entryPath)), '/');
        if ($entryPath === '') {
            throw new \InvalidArgumentException('ZIP local-header recovery requires an exact member path.');
        }

        $central = null;
        foreach ($this->centralEntries($archivePath) as $candidate) {
            if (hash_equals($entryPath, (string)($candidate['path'] ?? ''))) {
                $central = $candidate;
                break;
            }
        }
        if (!is_array($central)) {
            throw new \RuntimeException(
                'ZIP local-header recovery could not find central-directory member "' . $entryPath . '".'
            );
        }

        $candidatesByPath = $this->localCandidates($archivePath);
        $candidate = $this->bestCandidate($central, $candidatesByPath[$entryPath] ?? []);
        if (!is_array($candidate)) {
            throw new \RuntimeException(
                'ZIP central directory references "' . $entryPath
                . '" but no same-name recoverable local member header exists.'
            );
        }

        $expected = max(0, (int)($candidate['size'] ?? 0));
        $maxBytes = max(1, $maxBytes);
        if ($expected < 1 || $expected > $maxBytes) {
            throw new \RuntimeException(
                'Recovered ZIP local member "' . $entryPath . '" has invalid/oversized size '
                . number_format($expected) . ' bytes.'
            );
        }

        $temporary = $this->temporaryPath();
        $failure = '';
        $result = $this->decodeCandidate(
            $archivePath,
            $candidate,
            $temporary,
            $maxBytes,
            null,
            $failure
        );
        if (!is_array($result)) {
            @unlink($temporary);
            throw new \RuntimeException(
                'Recovered ZIP local member "' . $entryPath . '" could not be decoded: '
                . ($failure !== '' ? $failure : 'unknown local-header decode failure')
            );
        }

        clearstatcache(true, $temporary);
        $actual = filesize($temporary);
        if ($actual === false || (int)$actual !== (int)$result['bytes'] || (int)$actual !== $expected) {
            @unlink($temporary);
            throw new \RuntimeException(
                'Recovered ZIP local member "' . $entryPath . '" output size verification failed.'
            );
        }
        return $temporary;
    }

    /** @return list<array<string,mixed>> */
    private function centralEntries(string $archivePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZIP local-header recovery requires PHP ZipArchive for logical member listing.');
        }
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('PHP ZipArchive could not open ZIP for local-header recovery.');
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    continue;
                }
                $rawPath = (string)($stat['name'] ?? '');
                $normalized = str_replace('\\', '/', $rawPath);
                if ($normalized === '' || str_ends_with($normalized, '/')) {
                    continue;
                }
                [$safePath, $reason] = $this->safeMemberPath($normalized);
                $entries[] = [
                    'index' => $index,
                    'path' => $safePath !== '' ? $safePath : $normalized,
                    'size' => max(0, (int)($stat['size'] ?? 0)),
                    'encrypted' => false,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'php-native-zip-local-recovery',
                    'format' => 'zip',
                    'compression_method' => isset($stat['comp_method']) ? (int)$stat['comp_method'] : -1,
                    'crc32' => strtolower(sprintf('%08x', (int)($stat['crc'] ?? 0))),
                    'compressed_size' => max(0, (int)($stat['comp_size'] ?? 0)),
                ];
            }
            if ($entries === []) {
                throw new \RuntimeException('ZIP local-header recovery found no central-directory file entries.');
            }
            return $entries;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function localCandidates(string $archivePath): array
    {
        $fileSize = filesize($archivePath);
        if ($fileSize === false || (int)$fileSize < 30) {
            throw new \RuntimeException('ZIP source is too small for local-header recovery.');
        }
        $fileSize = (int)$fileSize;
        $scan = @fopen($archivePath, 'rb');
        $probe = @fopen($archivePath, 'rb');
        if (!is_resource($scan) || !is_resource($probe)) {
            if (is_resource($scan)) {
                fclose($scan);
            }
            if (is_resource($probe)) {
                fclose($probe);
            }
            throw new \RuntimeException('ZIP source could not be opened for local-header recovery scan.');
        }

        $candidates = [];
        try {
            $absolute = 0;
            $carry = '';
            while (!feof($scan)) {
                $chunk = fread($scan, self::SCAN_CHUNK_BYTES);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $window = $carry . $chunk;
                $base = $absolute - strlen($carry);
                $cursor = 0;
                while (($relative = strpos($window, self::LOCAL_SIGNATURE, $cursor)) !== false) {
                    $offset = $base + $relative;
                    $candidate = $this->parseLocalCandidate($probe, $offset, $fileSize);
                    if (is_array($candidate)) {
                        $candidates[(string)$candidate['path']][] = $candidate;
                    }
                    $cursor = $relative + 1;
                }
                $carry = strlen($window) > 3 ? substr($window, -3) : $window;
                $absolute += strlen($chunk);
            }
        } finally {
            fclose($scan);
            fclose($probe);
        }
        return $candidates;
    }

    /** @param resource $handle @return null|array<string,mixed> */
    private function parseLocalCandidate($handle, int $offset, int $fileSize): ?array
    {
        if ($offset < 0 || $offset + 30 > $fileSize || fseek($handle, $offset, SEEK_SET) !== 0) {
            return null;
        }
        $header = fread($handle, 30);
        if (!is_string($header) || strlen($header) !== 30 || substr($header, 0, 4) !== self::LOCAL_SIGNATURE) {
            return null;
        }

        $flags = $this->u16($header, 6);
        $method = $this->u16($header, 8);
        $crc = $this->u32($header, 14);
        $compressed = $this->u32($header, 18);
        $uncompressed = $this->u32($header, 22);
        $nameLength = $this->u16($header, 26);
        $extraLength = $this->u16($header, 28);

        // Data-descriptor members do not carry authoritative size/CRC values in
        // the local header, so they cannot participate in this recovery path.
        if (($flags & 0x0008) !== 0
            || ($flags & 0x0001) !== 0
            || !in_array($method, [0, 8], true)
            || $nameLength < 1
            || $nameLength > 2048
            || $compressed < 1
            || $uncompressed < 1) {
            return null;
        }

        $dataOffset = $offset + 30 + $nameLength + $extraLength;
        if ($dataOffset <= $offset || $dataOffset + $compressed > $fileSize) {
            return null;
        }
        $rawName = fread($handle, $nameLength);
        if (!is_string($rawName) || strlen($rawName) !== $nameLength) {
            return null;
        }
        try {
            $decoded = $this->decodeName($rawName, $flags);
        } catch (\Throwable) {
            return null;
        }
        [$safePath] = $this->safeMemberPath($decoded);
        if ($safePath === '') {
            return null;
        }

        return [
            'path' => $safePath,
            'offset' => $offset,
            'data_offset' => $dataOffset,
            'flags' => $flags,
            'compression_method' => $method,
            'crc32' => strtolower(sprintf('%08x', $crc)),
            'compressed_size' => $compressed,
            'size' => $uncompressed,
        ];
    }

    /**
     * @param array<string,mixed> $central
     * @param list<array<string,mixed>> $candidates
     * @return null|array<string,mixed>
     */
    private function bestCandidate(array $central, array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }
        $centralMethod = (int)($central['compression_method'] ?? -1);
        $compatible = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => $centralMethod < 0
                || (int)$candidate['compression_method'] === $centralMethod
        ));
        if ($compatible === []) {
            return null;
        }

        foreach ($compatible as $candidate) {
            if ((int)$candidate['size'] === (int)($central['size'] ?? -1)
                && (int)$candidate['compressed_size'] === (int)($central['compressed_size'] ?? -1)
                && hash_equals((string)$candidate['crc32'], (string)($central['crc32'] ?? ''))) {
                return $candidate;
            }
        }
        usort($compatible, static fn(array $a, array $b): int => (int)$a['offset'] <=> (int)$b['offset']);
        return $compatible[0];
    }

    /**
     * @param array<string,mixed> $candidate
     * @param null|callable():void $heartbeat
     * @param string $failure
     * @return null|array{bytes:int,crc32:string}
     */
    private function decodeCandidate(
        string $archivePath,
        array $candidate,
        string $temporary,
        int $maxBytes,
        ?callable $heartbeat,
        string &$failure
    ): ?array {
        $input = @fopen($archivePath, 'rb');
        if (!is_resource($input)) {
            $failure = 'archive source could not be opened';
            return null;
        }
        $output = @fopen($temporary, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            $failure = 'temporary output could not be opened';
            return null;
        }

        try {
            if (fseek($input, (int)$candidate['data_offset'], SEEK_SET) !== 0) {
                $failure = 'local payload could not be positioned';
                return null;
            }
            $compressed = (int)$candidate['compressed_size'];
            $expected = (int)$candidate['size'];
            $method = (int)$candidate['compression_method'];
            if ($expected > $maxBytes) {
                $failure = 'local member exceeds configured extraction limit';
                return null;
            }

            if ($method === 0) {
                if ($compressed !== $expected) {
                    $failure = 'stored local member compressed/uncompressed sizes differ';
                    return null;
                }
                $writer = new CatalogZipOutputWriter(
                    $output,
                    $maxBytes,
                    $expected,
                    1,
                    $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null
                );
                $remaining = $compressed;
                while ($remaining > 0) {
                    $take = min(65536, $remaining);
                    $chunk = fread($input, $take);
                    if (!is_string($chunk) || strlen($chunk) !== $take) {
                        $failure = 'stored local member ended unexpectedly';
                        return null;
                    }
                    $writer->writeString($chunk);
                    $remaining -= $take;
                }
                $result = $writer->finish();
            } else {
                if (!function_exists('inflate_init') || !function_exists('inflate_add')) {
                    $failure = 'PHP zlib raw DEFLATE support is unavailable';
                    return null;
                }
                $inflater = inflate_init(ZLIB_ENCODING_RAW);
                if ($inflater === false) {
                    $failure = 'PHP zlib could not initialize raw DEFLATE';
                    return null;
                }
                $writer = new CatalogZipOutputWriter(
                    $output,
                    $maxBytes,
                    $expected,
                    32768,
                    $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null
                );
                $remaining = $compressed;
                while ($remaining > 0) {
                    $take = min(65536, $remaining);
                    $chunk = fread($input, $take);
                    if (!is_string($chunk) || strlen($chunk) !== $take) {
                        $failure = 'deflated local member ended unexpectedly';
                        return null;
                    }
                    $remaining -= $take;
                    $decoded = @inflate_add(
                        $inflater,
                        $chunk,
                        $remaining === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH
                    );
                    if (!is_string($decoded)) {
                        $failure = 'PHP zlib rejected the local DEFLATE stream';
                        return null;
                    }
                    $writer->writeString($decoded);
                }
                $result = $writer->finish();
            }

            if ((int)$result['bytes'] !== $expected) {
                $failure = 'decoded size does not match local header';
                return null;
            }
            if (!hash_equals((string)$candidate['crc32'], strtolower((string)$result['crc32']))) {
                $failure = 'decoded CRC32 does not match local header';
                return null;
            }
            fflush($output);
            return $result;
        } catch (\RuntimeException $error) {
            $failure = trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error);
            return null;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function decodeName(string $rawName, int $flags): string
    {
        if (($flags & 0x0800) !== 0) {
            if (preg_match('//u', $rawName) !== 1) {
                throw new \RuntimeException('ZIP local filename is marked UTF-8 but contains invalid UTF-8 bytes.');
            }
            return $rawName;
        }
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

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-zip-local-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate ZIP local-recovery temporary storage.');
        }
        return $path;
    }

    private function requireSource(string $archivePath, string $archiveName): void
    {
        if (strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \InvalidArgumentException('ZIP local-header recovery only accepts ZIP archives.');
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('ZIP local-header recovery source is unavailable.');
        }
    }
}
