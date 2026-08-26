<?php
/**
 * PHP-extension-only RAR compatibility reader.
 *
 * Historical class name retained to keep the archive coordinator change small.
 * This class never launches a command, executable or shell process. It uses the
 * PECL `rar` extension (RarArchive/RarEntry), backed by the bundled UnRAR
 * library, for RAR features that libarchive does not implement.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

final class CatalogExternalArchiveReader
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function isAvailable(): bool
    {
        return true;
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
        if (!class_exists(\RarArchive::class)) {
            throw new \RuntimeException(
                'RAR solid archive support unavailable: the PHP rar extension (RarArchive) is not installed. '
                . 'The installed libarchive decoder cannot handle this RAR feature; source archive retained.'
            );
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Archive source is unavailable for PHP RAR decoding.');
        }

        // CatalogSequentialArchiveReader selects this decoder only after the
        // source bytes have been identified as RAR. Do not reject a valid RAR
        // merely because an archive member was historically misnamed *.zip (or
        // another extension). Content identity is authoritative; archiveName is
        // retained only for diagnostics/provenance.
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = trim($message);
            return true;
        });
        try {
            $archive = \RarArchive::open($archivePath);
        } finally {
            restore_error_handler();
        }
        if (!$archive instanceof \RarArchive) {
            throw new \RuntimeException(
                'PHP rar extension could not open the RAR archive.' . ($warning !== '' ? ' Decoder: ' . $warning : '')
            );
        }

        try {
            $entries = $archive->getEntries();
            if (!is_array($entries)) {
                throw new \RuntimeException('PHP rar extension could not enumerate the RAR archive.');
            }
            if (count($entries) > $this->maxEntries()) {
                throw new \RuntimeException(
                    'Archive contains too many entries; limit is ' . number_format($this->maxEntries()) . '.'
                );
            }

            $entryIndex = $this->indexEntriesBySafePath($entries);
            $fileCopyTargets = (new CatalogRar5FileCopyMap())->targets($archivePath);
            $maxDecodedBytes = max(1, $maxDecodedBytes);
            $decodedBytes = 0;
            $processed = 0;

            foreach ($entries as $rarEntry) {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                if (!is_object($rarEntry)
                    || !method_exists($rarEntry, 'getName')
                    || !method_exists($rarEntry, 'getUnpackedSize')
                    || !method_exists($rarEntry, 'getStream')) {
                    continue;
                }
                if (method_exists($rarEntry, 'isDirectory') && $rarEntry->isDirectory()) {
                    continue;
                }

                $rawPath = (string)$rarEntry->getName();
                [$safePath, $reason] = $this->safeMemberPath($rawPath);
                $declaredSize = max(0, (int)$rarEntry->getUnpackedSize());
                $encrypted = method_exists($rarEntry, 'isEncrypted') && (bool)$rarEntry->isEncrypted();
                $entry = [
                    'index' => $processed,
                    'path' => $safePath !== '' ? $safePath : str_replace('\\', '/', $rawPath),
                    'size' => $declaredSize,
                    'encrypted' => $encrypted,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'php-rar-extension',
                    'format' => 'rar',
                ];
                $processed++;

                $decision = $plan($entry);
                if (!is_array($decision) || !array_key_exists('extract', $decision)) {
                    throw new \LogicException('PHP RAR plan must return an extract decision.');
                }
                $extract = (bool)$decision['extract'];
                $state = $decision['state'] ?? null;
                $entryLimit = max(1, (int)($decision['max_bytes'] ?? $maxDecodedBytes));
                $remainingTotal = $maxDecodedBytes - $decodedBytes;
                if ($remainingTotal < 1) {
                    throw new \RuntimeException(
                        'Archive expansion exceeds the configured total unpacked-data limit of '
                        . number_format($maxDecodedBytes) . ' bytes.'
                    );
                }
                if ($declaredSize > 0 && $declaredSize > $remainingTotal) {
                    throw new \RuntimeException(
                        'Archive expansion exceeds the configured total unpacked-data limit of '
                        . number_format($maxDecodedBytes) . ' bytes.'
                    );
                }

                if (!$extract) {
                    $complete($entry, null, $state);
                    continue;
                }
                if ($declaredSize > $entryLimit) {
                    throw new \RuntimeException(
                        'Archive member exceeded its configured PHP RAR decode limit while reading '
                        . (string)$entry['path'] . '.'
                    );
                }

                $streamWarning = '';
                set_error_handler(static function (int $severity, string $message) use (&$streamWarning): bool {
                    $streamWarning = trim($message);
                    return true;
                });
                try {
                    $stream = $rarEntry->getStream();
                } finally {
                    restore_error_handler();
                }
                if (!is_resource($stream)) {
                    throw new \RuntimeException(
                        'PHP rar extension could not open RAR member stream for "' . (string)$entry['path'] . '".'
                        . ($streamWarning !== '' ? ' Decoder: ' . $streamWarning : '')
                    );
                }
                $temporary = $this->temporaryPath();
                $output = @fopen($temporary, 'wb');
                if (!is_resource($output)) {
                    fclose($stream);
                    @unlink($temporary);
                    throw new \RuntimeException('Could not create temporary PHP RAR member file.');
                }

                try {
                    try {
                        $actualBytes = $this->copyStream(
                            $stream,
                            $output,
                            min($entryLimit, $remainingTotal),
                            $declaredSize,
                            $heartbeat
                        );
                    } catch (\Throwable $streamError) {
                        if (!$this->isImmediateStreamFailure($streamError) || !method_exists($rarEntry, 'extract')) {
                            throw $streamError;
                        }

                        fclose($stream);
                        $stream = null;
                        fclose($output);
                        $output = null;
                        @unlink($temporary);
                        if ($heartbeat !== null) {
                            $heartbeat();
                        }

                        try {
                            $actualBytes = $this->extractEntryToTemporary(
                                $rarEntry,
                                $temporary,
                                $declaredSize,
                                min($entryLimit, $remainingTotal),
                                $streamError
                            );
                        } catch (\Throwable $extractError) {
                            $sourcePath = $this->resolveFileCopySource((string)$entry['path'], $fileCopyTargets);
                            $sourceEntry = $sourcePath !== null ? ($entryIndex[$sourcePath] ?? null) : null;
                            if (!is_object($sourceEntry)) {
                                throw $extractError;
                            }
                            $actualBytes = $this->extractFileCopySourceToTemporary(
                                $sourceEntry,
                                $sourcePath,
                                $temporary,
                                $declaredSize,
                                min($entryLimit, $remainingTotal),
                                $extractError,
                                $heartbeat
                            );
                        }
                        if ($heartbeat !== null) {
                            $heartbeat();
                        }
                    }

                    if ($declaredSize > 0 && $actualBytes !== $declaredSize) {
                        throw new \RuntimeException(
                            'PHP RAR member output size does not match its declared size; expected '
                            . number_format($declaredSize) . ' bytes, got ' . number_format($actualBytes) . ' bytes.'
                        );
                    }
                    if ($actualBytes < 1) {
                        throw new \RuntimeException('PHP RAR member produced no data.');
                    }
                    if ($declaredSize < 1) {
                        $entry['size'] = $actualBytes;
                    }
                    $decodedBytes += $actualBytes;
                    if (is_resource($output)) {
                        fflush($output);
                        fclose($output);
                        $output = null;
                    }
                    if (is_resource($stream)) {
                        fclose($stream);
                        $stream = null;
                    }
                    $this->verifyTemporary($temporary, $actualBytes, $entryLimit);
                    $complete($entry, $temporary, $state);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
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
                'format' => 'rar-php-extension',
            ];
        } finally {
            $archive->close();
        }
    }

    /** @param resource $input @param resource $output */
    private function copyStream(
        $input,
        $output,
        int $maxBytes,
        int $expectedBytes,
        ?callable $heartbeat
    ): int {
        $written = 0;
        while ($expectedBytes > 0 ? $written < $expectedBytes : !feof($input)) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
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
                    'Could not read PHP RAR member stream after ' . number_format($written) . ' bytes.'
                    . ($warning !== '' ? ' Decoder: ' . $warning : '')
                );
            }
            if ($buffer === '') {
                if ($expectedBytes < 1 && feof($input)) {
                    break;
                }
                throw new \RuntimeException(
                    'PHP RAR member stream stopped unexpectedly after ' . number_format($written) . ' bytes.'
                    . ($warning !== '' ? ' Decoder: ' . $warning : '')
                );
            }
            $length = strlen($buffer);
            $written += $length;
            if ($written > $maxBytes || ($expectedBytes > 0 && $written > $expectedBytes)) {
                throw new \RuntimeException('RAR member exceeded its configured import limit.');
            }
            if (fwrite($output, $buffer) !== $length) {
                throw new \RuntimeException('Could not write temporary PHP RAR member data.');
            }
        }
        return $written;
    }

    private function isImmediateStreamFailure(\Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        return str_contains($message, 'after 0 bytes')
            && (str_contains($message, 'php rar member stream stopped unexpectedly')
                || str_contains($message, 'could not read php rar member stream'));
    }

    private function extractEntryToTemporary(
        object $rarEntry,
        string $temporary,
        int $declaredSize,
        int $maxBytes,
        \Throwable $streamError
    ): int {
        @unlink($temporary);
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = trim($message);
            return true;
        });
        try {
            try {
                $extracted = $rarEntry->extract('', $temporary);
            } catch (\Throwable $extractError) {
                throw new \RuntimeException(
                    'PHP RAR member stream failed at byte zero and RarEntry::extract() also failed: '
                    . $this->errorText($extractError)
                    . '. Stream: ' . $this->errorText($streamError),
                    (int)$extractError->getCode(),
                    $extractError
                );
            }
        } finally {
            restore_error_handler();
        }

        if ($extracted !== true) {
            throw new \RuntimeException(
                'PHP RAR member stream failed at byte zero and RarEntry::extract() returned failure.'
                . ($warning !== '' ? ' Decoder: ' . $warning : '')
                . ' Stream: ' . $this->errorText($streamError)
            );
        }

        clearstatcache(true, $temporary);
        if (!is_file($temporary) || is_link($temporary)) {
            @unlink($temporary);
            throw new \RuntimeException('RarEntry::extract() did not produce a regular temporary file.');
        }
        $size = filesize($temporary);
        if ($size === false || (int)$size < 1) {
            @unlink($temporary);
            throw new \RuntimeException('RarEntry::extract() produced no readable member data.');
        }
        $actualBytes = (int)$size;
        if ($actualBytes > $maxBytes) {
            @unlink($temporary);
            throw new \RuntimeException('RarEntry::extract() exceeded the configured archive-member limit.');
        }
        if ($declaredSize > 0 && $actualBytes !== $declaredSize) {
            @unlink($temporary);
            throw new \RuntimeException(
                'RarEntry::extract() output size does not match its declared size; expected '
                . number_format($declaredSize) . ' bytes, got ' . number_format($actualBytes) . ' bytes.'
            );
        }
        return $actualBytes;
    }

    /**
     * Materialize the exact RAR5 FILECOPY source recorded by the archive. The
     * reference map comes from CRC-validated RAR5 headers, so no size/name guess
     * is used to choose the source member.
     */
    private function extractFileCopySourceToTemporary(
        object $sourceEntry,
        string $sourcePath,
        string $temporary,
        int $declaredSize,
        int $maxBytes,
        \Throwable $referenceError,
        ?callable $heartbeat
    ): int {
        if (!method_exists($sourceEntry, 'getStream') || !method_exists($sourceEntry, 'getUnpackedSize')) {
            throw $referenceError;
        }
        $sourceSize = max(0, (int)$sourceEntry->getUnpackedSize());
        if ($declaredSize > 0 && $sourceSize > 0 && $sourceSize !== $declaredSize) {
            throw new \RuntimeException(
                'RAR5 FILECOPY source size does not match the logical member size for "' . $sourcePath . '".'
            );
        }
        if ($sourceSize > $maxBytes) {
            throw new \RuntimeException('RAR5 FILECOPY source exceeds the configured archive-member limit.');
        }

        @unlink($temporary);
        $stream = $sourceEntry->getStream();
        if (!is_resource($stream)) {
            throw new \RuntimeException(
                'RAR5 FILECOPY source stream could not be opened for "' . $sourcePath . '". Reference failure: '
                . $this->errorText($referenceError)
            );
        }
        $output = @fopen($temporary, 'wb');
        if (!is_resource($output)) {
            fclose($stream);
            @unlink($temporary);
            throw new \RuntimeException('Could not create temporary RAR5 FILECOPY output.');
        }

        try {
            $actual = $this->copyStream(
                $stream,
                $output,
                $maxBytes,
                $sourceSize > 0 ? $sourceSize : $declaredSize,
                $heartbeat
            );
            fflush($output);
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw new \RuntimeException(
                'RAR5 FILECOPY source "' . $sourcePath . '" could not be decoded: ' . $this->errorText($error),
                (int)$error->getCode(),
                $error
            );
        } finally {
            fclose($stream);
            fclose($output);
        }

        if ($actual < 1 || ($declaredSize > 0 && $actual !== $declaredSize)) {
            @unlink($temporary);
            throw new \RuntimeException('RAR5 FILECOPY source produced an invalid output size.');
        }
        $this->verifyTemporary($temporary, $actual, $maxBytes);
        return $actual;
    }

    /** @param array<int,mixed> $entries @return array<string,object> */
    private function indexEntriesBySafePath(array $entries): array
    {
        $index = [];
        foreach ($entries as $entry) {
            if (!is_object($entry) || !method_exists($entry, 'getName')) {
                continue;
            }
            if (method_exists($entry, 'isDirectory') && $entry->isDirectory()) {
                continue;
            }
            [$safe] = $this->safeMemberPath((string)$entry->getName());
            if ($safe !== '') {
                $index[$safe] = $entry;
            }
        }
        return $index;
    }

    /** @param array<string,string> $targets */
    private function resolveFileCopySource(string $path, array $targets): ?string
    {
        $current = str_replace('\\', '/', $path);
        $seen = [];
        for ($depth = 0; $depth < 32; $depth++) {
            if (isset($seen[$current])) {
                return null;
            }
            $seen[$current] = true;
            $next = $targets[$current] ?? null;
            if (!is_string($next) || $next === '') {
                return $depth > 0 ? $current : null;
            }
            $current = $next;
        }
        return null;
    }

    /** @return array{0:string,1:string} */
    private function safeMemberPath(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) {
            return ['', 'empty/control-character path'];
        }
        $path = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return ['', 'absolute drive path'];
        }
        $path = ltrim($path, '/');
        if ($path === '') {
            return ['', 'empty normalized path'];
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
            throw new \RuntimeException('PHP RAR member did not produce a regular temporary file.');
        }
        $size = filesize($path);
        if ($size === false || (int)$size !== $expectedBytes || (int)$size > $maxBytes) {
            throw new \RuntimeException('PHP RAR member temporary file size verification failed.');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-rar-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate temporary PHP RAR member storage.');
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
