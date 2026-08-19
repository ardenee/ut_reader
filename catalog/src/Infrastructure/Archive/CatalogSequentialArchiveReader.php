<?php
/**
 * Streams libarchive-backed containers in one forward-only pass.
 *
 * Solid RAR archives carry decompression state across member boundaries. A
 * separate list pass or reopening the archive for every selected member can
 * therefore make later members undecodable. This reader keeps one libarchive
 * handle alive, fully consumes every regular member in archive order and only
 * writes selected members to controlled temporary storage.
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
        $this->requireSource($archivePath, $archiveName);
        $format = $this->detectFormat($archivePath, $archiveName);

        // ext-zip can safely seek/reopen ordinary ZIP members and remains the
        // preferred ZIP implementation. RAR/7z always use the forward stream.
        if ($format === 'zip' && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            try {
                $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
                if ($opened === true) {
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
     * Walk every regular member exactly once on one libarchive handle.
     *
     * $plan receives safe metadata before the current member is consumed and
     * returns:
     *   - extract: whether bytes should be copied to a temporary file;
     *   - max_bytes: selected-member extraction ceiling;
     *   - state: arbitrary caller state returned to $complete.
     *
     * $complete runs only after the current member has been fully consumed. Its
     * temporary path is non-null only for extract=true and is deleted after the
     * callback returns, so callers must copy/stage it before returning.
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
        $this->requireLibarchive($format);
        $maxDecodedBytes = max(1, $maxDecodedBytes);

        $archive = $this->newArchive($archivePath, $format);
        $entries = 0;
        $decodedBytes = 0;
        $ordinal = 0;

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

            $input = null;
            $output = null;
            $temporary = null;
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

                $actualBytes = $this->consumeStream($input, $output, $streamLimit, $format, (string)$entry['path']);
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
                $complete($entry, $temporary, $state);
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
                if ($temporary !== null && is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        return [
            'entries' => $entries,
            'decoded_bytes' => $decodedBytes,
            'format' => $format,
        ];
    }

    /** @param resource $input @param resource|null $output */
    private function consumeStream($input, $output, int $maxBytes, string $format, string $entryPath): int
    {
        $written = 0;
        while (!feof($input)) {
            $buffer = fread($input, 1024 * 1024);
            if (!is_string($buffer)) {
                throw new \RuntimeException('Could not read libarchive member stream.');
            }
            if ($buffer === '') {
                if (feof($input)) {
                    break;
                }
                $meta = stream_get_meta_data($input);
                throw new \RuntimeException(
                    'libarchive member stream stopped unexpectedly; bytes_consumed=' . $written
                    . '; eof=' . (!empty($meta['eof']) ? 'true' : 'false')
                    . '; timed_out=' . (!empty($meta['timed_out']) ? 'true' : 'false') . '.'
                );
            }

            $length = strlen($buffer);
            $written += $length;
            if ($written > $maxBytes) {
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
        foreach ($formats as $format => $signatures) {
            foreach ($signatures as $signature) {
                $offset = strpos($prefix, $signature);
                if ($offset !== false && $offset < $bestOffset) {
                    $bestFormat = $format;
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
