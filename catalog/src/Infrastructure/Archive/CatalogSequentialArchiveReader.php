<?php
/**
 * Streams archive containers in one forward-only pass when the format's primary
 * random-access reader cannot safely consume every member.
 *
 * Historic ZIP files using PKZIP Implode (method 6) or Deflate64 (method 9) are
 * routed to UnrealDB's native PHP compatibility decoder. RAR prefers the PHP rar
 * extension; 7z and RAR when ext-rar is unavailable use PHP libarchive. Native
 * UMOD-family containers deliberately bypass this reader. No archive executable
 * or shell process is used.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

final class CatalogSequentialArchiveReader
{
    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar'];
    private const FORMAT_SNIFF_BYTES = 65536;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function shouldUse(string $archivePath, string $archiveName): bool
    {
        if (CatalogUmodArchiveReader::isName($archiveName)) {
            return false;
        }
        $this->requireSource($archivePath, $archiveName);
        $format = $this->detectFormat($archivePath, $archiveName);

        if ($format === 'zip') {
            $nativeZip = new CatalogNativeZipArchiveReader($this->config);
            if ($nativeZip->hasLegacyCompression($archivePath)) {
                return true;
            }
        }

        // Ordinary ZIP files stay on ext-zip's efficient random-access path, but
        // opening the central directory is not enough proof that libzip can
        // actually decode every member. Historic UT archives can be listed by
        // ZipArchive while getStreamIndex() fails for legacy compression methods.
        // Probe each regular member without consuming it; if any stream cannot be
        // opened, replay the complete archive through the PHP libarchive reader.
        // Control-character filenames (classic Mac Icon metadata is common) also
        // use the sequential path so those unrepresentable entries can be ignored
        // without turning an otherwise healthy archive into partial recovery work.
        if ($format === 'zip' && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            try {
                $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
                if ($opened === true) {
                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                        if (!is_array($stat)) {
                            return true;
                        }
                        $rawPath = (string)($stat['name'] ?? '');
                        $normalized = str_replace('\\', '/', $rawPath);
                        if ($rawPath === '' || str_ends_with($normalized, '/')) {
                            continue;
                        }
                        if ($this->hasControlCharacters($rawPath)) {
                            return true;
                        }

                        if (method_exists($zip, 'getStreamIndex')) {
                            $stream = @$zip->getStreamIndex($index, \ZipArchive::FL_UNCHANGED);
                        } else {
                            $stream = @$zip->getStream($rawPath);
                        }
                        if (!is_resource($stream)) {
                            return true;
                        }
                        fclose($stream);
                    }
                    return false;
                }
            } finally {
                if (isset($opened) && $opened === true) {
                    $zip->close();
                }
            }
        }

        return true;
    }

    /**
     * Walk every regular member exactly once.
     *
     * ZIP method 6/9 archives use UnrealDB's native PHP decoder. RAR is delegated
     * directly to the PHP rar extension when it is loaded. Otherwise the installed
     * libarchive extension remains the compatibility reader for 7z/RAR and ZIP
     * cases unrelated to methods 6/9.
     *
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
        $format = $this->detectFormat($archivePath, $archiveName);
        $maxDecodedBytes = max(1, $maxDecodedBytes);

        if ($format === 'zip') {
            $nativeZip = new CatalogNativeZipArchiveReader($this->config);
            if ($nativeZip->hasLegacyCompression($archivePath)) {
                return $nativeZip->walk(
                    $archivePath,
                    $archiveName,
                    $maxDecodedBytes,
                    $plan,
                    $complete,
                    $heartbeat
                );
            }
        }

        // Do not send a RAR through libarchive first and attempt to recover later.
        // Unsupported filters/solid state can fail while the libarchive iterator
        // advances to the next header, outside a current-member stream catch. Once
        // ext-rar is present it is therefore the authoritative RAR decoder.
        if ($format === 'rar' && class_exists(\RarArchive::class)) {
            return (new CatalogExternalArchiveReader($this->config))->walk(
                $archivePath,
                $archiveName,
                $maxDecodedBytes,
                $plan,
                $complete,
                $heartbeat
            );
        }

        $this->requireLibarchive($format);
        $archive = $this->newArchive($archivePath, $format);
        $entries = 0;
        $decodedBytes = 0;
        $ordinal = 0;

        try {
            foreach ($archive as $archiveEntry) {
                $index = $ordinal++;
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                if (!is_object($archiveEntry)) {
                    continue;
                }

                $rawPath = trim((string)($archiveEntry->pathname ?? ''));
                $normalizedRawPath = str_replace('\\', '/', $rawPath);
                if ($rawPath === '' || str_ends_with($normalizedRawPath, '/')) {
                    continue;
                }

                // Classic Mac ZIPs commonly carry Finder icon metadata in a name
                // ending with a carriage return. Such a path cannot be represented
                // safely on the Windows catalog host and is never an Unreal
                // package. Ignore control-character members at the decoder layer
                // rather than manufacturing permanent partial-archive failures.
                if ($this->hasControlCharacters($rawPath)) {
                    continue;
                }

                [$safePath, $reason] = $this->safeMemberPath($rawPath);
                $declaredSize = $archiveEntry->size ?? null;
                $entry = [
                    'index' => $index,
                    'path' => $safePath !== '' ? $safePath : $normalizedRawPath,
                    'size' => $declaredSize !== null ? max(0, (int)$declaredSize) : 0,
                    'encrypted' => false,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'libarchive-sequential',
                    'format' => $format,
                ];
                $entries++;
                if ($entries > $this->maxEntries()) {
                    throw new \RuntimeException(
                        'Archive contains too many entries; limit is ' . number_format($this->maxEntries()) . '.'
                    );
                }

                $decision = $plan($entry);
                if (!is_array($decision) || !array_key_exists('extract', $decision)) {
                    throw new \LogicException('Sequential archive plan must return an extract decision.');
                }
                $extract = (bool)$decision['extract'];
                $entryLimit = max(1, (int)($decision['max_bytes'] ?? $maxDecodedBytes));
                $state = $decision['state'] ?? null;

                $remainingTotal = $maxDecodedBytes - $decodedBytes;
                if ($remainingTotal < 1) {
                    throw new \RuntimeException(
                        'Archive expansion exceeds the configured total unpacked-data limit of '
                        . number_format($maxDecodedBytes) . ' bytes.'
                    );
                }
                if ((int)$entry['size'] > 0 && (int)$entry['size'] > $remainingTotal) {
                    throw new \RuntimeException(
                        'Archive expansion exceeds the configured total unpacked-data limit of '
                        . number_format($maxDecodedBytes) . ' bytes.'
                    );
                }

                // ZIP entries are independently compressed. Once the coordinator
                // has decided that a ZIP member is unsupported/nested/reused, do
                // not decode its payload merely to reach the next header. This is
                // especially important for historic method-6 (implode) metadata:
                // the installed PHP decoders may not understand that method even
                // though later Unreal members remain independently addressable.
                if ($format === 'zip' && !$extract) {
                    $complete($entry, null, $state);
                    continue;
                }

                // libarchive can expose RAR directory records without a trailing
                // slash but with a declared zero size. There are no bytes to drain.
                if ($declaredSize !== null && (int)$declaredSize === 0 && !$extract) {
                    $complete($entry, null, $state);
                    continue;
                }

                $input = null;
                $output = null;
                $temporary = null;
                $actualBytes = 0;
                try {
                    $input = $archive->currentEntryStream();
                    if (!is_resource($input)) {
                        throw new \RuntimeException('Could not open libarchive current-member stream.');
                    }

                    $streamLimit = $extract ? min($entryLimit, $remainingTotal) : $remainingTotal;
                    if ($extract) {
                        $temporary = $this->temporaryPath();
                        $output = fopen($temporary, 'wb');
                        if (!is_resource($output)) {
                            throw new \RuntimeException('Could not create temporary archive member.');
                        }
                    }

                    $actualBytes = $this->consumeStream(
                        $input,
                        $output,
                        $streamLimit,
                        $format,
                        (string)$entry['path'],
                        max(0, (int)$entry['size'])
                    );
                    $decodedBytes += $actualBytes;
                    if ((int)$entry['size'] > 0 && $actualBytes !== (int)$entry['size']) {
                        throw new \RuntimeException(
                            'Archive member output size does not match its declared size; expected '
                            . number_format((int)$entry['size']) . ' bytes, got '
                            . number_format($actualBytes) . ' bytes.'
                        );
                    }
                    if ((int)$entry['size'] < 1) {
                        $entry['size'] = $actualBytes;
                    }
                    if ($extract && $actualBytes < 1) {
                        throw new \RuntimeException('Archive member produced no data.');
                    }

                    if (is_resource($output)) {
                        fflush($output);
                        fclose($output);
                        $output = null;
                    }
                    if (is_resource($input)) {
                        fclose($input);
                        $input = null;
                    }

                    if ($temporary !== null) {
                        $this->verifyTemporary($temporary, $actualBytes, $entryLimit);
                    }
                } catch (\Throwable $error) {
                    $label = $format === '7z' ? '7-Zip' : strtoupper($format);
                    throw new \RuntimeException(
                        $label . ' sequential archive member "' . (string)$entry['path'] . '" failed: '
                        . $this->errorText($error),
                        (int)$error->getCode(),
                        $error
                    );
                } finally {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                }

                // Coordinator callbacks can throw cancellation, lease-loss or DB
                // exceptions. Do not recast those as decoder failures.
                try {
                    $complete($entry, $temporary, $state);
                } finally {
                    if ($temporary !== null && is_file($temporary)) {
                        @unlink($temporary);
                    }
                }
            }
        } catch (\Throwable $error) {
            if ($format === 'rar' && $this->isRarDecoderCapabilityFailure($error)) {
                throw new \RuntimeException(
                    'RAR solid archive support unavailable: installed PHP libarchive cannot decode this RAR feature '
                    . 'and the PHP rar extension (RarArchive) is not loaded in this worker process. Decoder: '
                    . $this->errorText($error),
                    (int)$error->getCode(),
                    $error
                );
            }
            throw $error;
        }

        return [
            'entries' => $entries,
            'decoded_bytes' => $decodedBytes,
            'format' => $format,
        ];
    }

    /** @param resource $input @param resource|null $output */
    private function consumeStream(
        $input,
        $output,
        int $maxBytes,
        string $format,
        string $entryPath,
        int $expectedBytes = 0
    ): int {
        $written = 0;
        $expectedBytes = max(0, $expectedBytes);
        if ($expectedBytes > $maxBytes) {
            throw new \RuntimeException(
                'Archive member exceeded its configured sequential decode limit while reading '
                . $format . ' member ' . $entryPath . '.'
            );
        }

        while ($expectedBytes > 0 ? $written < $expectedBytes : !feof($input)) {
            $readBytes = 1024 * 1024;
            if ($expectedBytes > 0) {
                $readBytes = min($readBytes, $expectedBytes - $written);
            }

            $warning = '';
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = trim($message);
                return true;
            });
            try {
                $buffer = fread($input, $readBytes);
            } finally {
                restore_error_handler();
            }
            if (!is_string($buffer)) {
                throw new \RuntimeException(
                    'Could not read libarchive member stream.' . ($warning !== '' ? ' Decoder: ' . $warning : '')
                );
            }
            if ($buffer === '') {
                if ($expectedBytes < 1 && feof($input)) {
                    break;
                }
                $meta = stream_get_meta_data($input);
                throw new \RuntimeException(
                    'libarchive member stream stopped unexpectedly; bytes_consumed=' . $written
                    . ($expectedBytes > 0 ? '; expected_bytes=' . $expectedBytes : '')
                    . '; eof=' . (!empty($meta['eof']) ? 'true' : 'false')
                    . '; timed_out=' . (!empty($meta['timed_out']) ? 'true' : 'false') . '.'
                );
            }

            $length = strlen($buffer);
            $written += $length;
            if ($written > $maxBytes || ($expectedBytes > 0 && $written > $expectedBytes)) {
                throw new \RuntimeException(
                    'Archive member exceeded its configured sequential decode limit while reading '
                    . $format . ' member ' . $entryPath . '.'
                );
            }
            if (is_resource($output) && fwrite($output, $buffer) !== $length) {
                throw new \RuntimeException('Could not write temporary archive member.');
            }
        }
        return $written;
    }

    private function detectFormat(string $archivePath, string $archiveName): string
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            try {
                $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
                if ($opened === true) {
                    return 'zip';
                }
            } finally {
                if (isset($opened) && $opened === true) {
                    $zip->close();
                }
            }
        }

        $prefix = $this->readPrefix($archivePath, self::FORMAT_SNIFF_BYTES);
        $formats = [
            '7z' => ["7z\xBC\xAF\x27\x1C"],
            'rar' => ["Rar!\x1A\x07\x00", "Rar!\x1A\x07\x01\x00"],
            'zip' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        ];
        $bestFormat = '';
        $bestOffset = PHP_INT_MAX;
        foreach ($formats as $candidateFormat => $signatures) {
            foreach ($signatures as $signature) {
                $offset = strpos($prefix, $signature);
                if ($offset !== false && $offset < $bestOffset) {
                    $bestFormat = $candidateFormat;
                    $bestOffset = $offset;
                }
            }
        }
        if ($bestFormat !== '') {
            return $bestFormat;
        }

        $extension = strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ARCHIVE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported archive extension: ' . $extension);
        }
        return $extension;
    }

    private function newArchive(string $archivePath, string $format): object
    {
        $archive = new \libarchive\Archive($archivePath);
        $formats = match ($format) {
            'zip' => $this->definedFormats(['libarchive\\FORMAT_ZIP']),
            'rar' => $this->definedFormats(['libarchive\\FORMAT_RAR', 'libarchive\\FORMAT_RAR_V5']),
            '7z' => $this->definedFormats(['libarchive\\FORMAT_7ZIP']),
            default => [],
        };
        if ($formats !== [] && method_exists($archive, 'supportFormats')) {
            $first = array_shift($formats);
            $archive->supportFormats($first, ...$formats);
        }
        return $archive;
    }

    /** @param list<string> $names @return list<int> */
    private function definedFormats(array $names): array
    {
        $formats = [];
        foreach ($names as $name) {
            if (defined($name)) {
                $value = constant($name);
                if (is_int($value)) {
                    $formats[] = $value;
                }
            }
        }
        return $formats;
    }

    private function requireLibarchive(string $format): void
    {
        if (class_exists(\libarchive\Archive::class)
            && method_exists(\libarchive\Archive::class, 'currentEntryStream')) {
            return;
        }
        $label = $format === '7z' ? '7-Zip' : strtoupper($format);
        throw new \RuntimeException(
            $label . ' archive support requires PHP ext-archive/libarchive with currentEntryStream().'
        );
    }

    private function requireSource(string $archivePath, string $archiveName): void
    {
        $extension = strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ARCHIVE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported archive extension: ' . $extension);
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Archive source is unavailable.');
        }
    }

    private function readPrefix(string $path, int $limit): string
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Archive source could not be opened for format detection.');
        }
        try {
            $data = fread($handle, max(1, $limit));
            if (!is_string($data)) {
                throw new \RuntimeException('Archive source could not be read for format detection.');
            }
            return $data;
        } finally {
            fclose($handle);
        }
    }

    private function hasControlCharacters(string $path): bool
    {
        return str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1;
    }

    /** @return array{0:string,1:string} */
    private function safeMemberPath(string $path): array
    {
        if ($path === '' || $this->hasControlCharacters($path)) {
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

    private function isRarDecoderCapabilityFailure(\Throwable $error): bool
    {
        $message = strtolower($this->errorText($error));
        foreach ([
            'parsing filters is unsupported',
            'rar solid archive support unavailable',
            'could not read libarchive member stream',
            'libarchive member stream stopped unexpectedly',
            'could not open libarchive current-member stream',
            'error reading data block',
            'error moving to next header',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function verifyTemporary(string $path, int $expectedBytes, int $maxBytes): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('Sequential archive member did not produce a regular temporary file.');
        }
        $size = filesize($path);
        if ($size === false || (int)$size !== $expectedBytes || (int)$size > $maxBytes) {
            throw new \RuntimeException('Sequential archive member temporary file size verification failed.');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-archive-seq-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate temporary sequential archive-member storage.');
        }
        return $path;
    }

    private function maxEntries(): int
    {
        return max(1, min(100000, (int)($this->config['archive']['max_entries'] ?? 10000)));
    }

    private function errorText(\Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? $message : get_class($error);
    }
}
