<?php
/**
 * Safe unpack-only ZIP/7z/RAR reader used by catalog ingestion jobs.
 *
 * Archive decoding is entirely in-process through PHP extensions. No shell,
 * command-line 7-Zip/unrar binary, or platform-specific executable is used.
 * ZIP prefers ext-zip (ZipArchive); RAR and 7z use ext-archive
 * (cataphract/libarchive), which also provides a ZIP fallback.
 *
 * Archives are never extracted wholesale into a filesystem tree. Entries are
 * listed first, validated, then one requested regular file is streamed to a
 * temporary file. This avoids path traversal and lets callers impose their own
 * Unreal-file policy before any member is unpacked.
 *
 * Released ext-archive 0.2.0 intentionally exposes only pathname/size/time/perm
 * entry metadata. Do not depend on unreleased virtual properties such as
 * isFile/isDir/isSymlink/hardlink/isEncrypted here. The 0.2.0 compatibility
 * path remains safe because member paths are never used as extraction targets,
 * only positive-size Unreal members are accepted by callers, extraction is
 * bounded, and the resulting regular temporary file must match the declared
 * uncompressed size exactly.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

final class CatalogArchiveExtractor
{
    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar'];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function isArchiveName(string $name): bool
    {
        return in_array(strtolower((string)pathinfo($name, PATHINFO_EXTENSION)), self::ARCHIVE_EXTENSIONS, true);
    }

    /** @return array{zip:bool,libarchive:bool,rar:bool,seven_zip:bool} */
    public static function runtimeCapabilities(): array
    {
        $libarchive = class_exists(\libarchive\Archive::class)
            && method_exists(\libarchive\Archive::class, 'currentEntryStream');
        return [
            'zip' => class_exists(\ZipArchive::class) || $libarchive,
            'libarchive' => $libarchive,
            'rar' => $libarchive,
            'seven_zip' => $libarchive,
        ];
    }

    /**
     * @return list<array{
     *   index:int,
     *   path:string,
     *   size:int,
     *   encrypted:bool,
     *   safe:bool,
     *   reason:string,
     *   backend:string
     * }>
     */
    public function entries(string $archivePath, string $archiveName): array
    {
        $this->requireArchive($archivePath, $archiveName);
        $extension = strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION));

        if ($extension === 'zip' && class_exists(\ZipArchive::class)) {
            return $this->zipEntries($archivePath);
        }

        $this->requireLibarchive($extension);
        return $this->libarchiveEntries($archivePath, $extension);
    }

    /**
     * Extract one previously listed entry to a temporary regular file.
     * Caller owns the returned file and must unlink it.
     *
     * @param array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string} $entry
     */
    public function extractToTemp(string $archivePath, string $archiveName, array $entry, int $maxBytes): string
    {
        $this->requireArchive($archivePath, $archiveName);
        if (empty($entry['safe'])) {
            throw new \RuntimeException('Archive member is unsafe: ' . (string)($entry['reason'] ?? 'invalid path'));
        }
        if (!empty($entry['encrypted'])) {
            throw new \RuntimeException('Encrypted/password-protected archive members are not supported.');
        }

        $expected = (int)($entry['size'] ?? -1);
        $maxBytes = max(1, $maxBytes);
        if ($expected < 1 || $expected > $maxBytes) {
            throw new \RuntimeException('Archive member exceeds the configured extraction limit.');
        }

        return match ((string)($entry['backend'] ?? '')) {
            'zip' => $this->extractZipEntry($archivePath, $entry, $maxBytes),
            'libarchive' => $this->extractLibarchiveEntry(
                $archivePath,
                strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION)),
                $entry,
                $maxBytes
            ),
            default => throw new \RuntimeException('Archive member backend is unavailable or invalid.'),
        };
    }

    /** @return list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string}> */
    private function zipEntries(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('Could not open ZIP archive (ZipArchive code ' . (string)$opened . ').');
        }

        $entries = [];
        $maxEntries = $this->maxEntries();
        try {
            if ($zip->numFiles > $maxEntries) {
                throw new \RuntimeException('Archive contains too many entries; limit is ' . number_format($maxEntries) . '.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    continue;
                }
                $rawPath = (string)($stat['name'] ?? '');
                if ($rawPath === '' || str_ends_with(str_replace('\\', '/', $rawPath), '/')) {
                    continue;
                }

                $symlink = false;
                if (method_exists($zip, 'getExternalAttributesIndex')) {
                    $opsys = 0;
                    $attributes = 0;
                    if ($zip->getExternalAttributesIndex($index, $opsys, $attributes, \ZipArchive::FL_UNCHANGED)) {
                        $mode = ($attributes >> 16) & 0170000;
                        $symlink = $mode === 0120000;
                    }
                }

                $encrypted = false;
                if (method_exists($zip, 'getEncryptionName')) {
                    $encryption = $zip->getEncryptionName($index);
                    $encrypted = is_string($encryption) && $encryption !== '' && strtolower($encryption) !== 'none';
                }

                [$safePath, $reason] = $this->safeMemberPath($rawPath);
                if ($symlink) {
                    $safePath = '';
                    $reason = 'symbolic-link entries are not accepted';
                }
                $entries[] = [
                    'index' => $index,
                    'path' => $safePath !== '' ? $safePath : str_replace('\\', '/', $rawPath),
                    'size' => max(0, (int)($stat['size'] ?? 0)),
                    'encrypted' => $encrypted,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'zip',
                ];
            }
        } finally {
            $zip->close();
        }

        return $this->stableOrder($entries);
    }

    /** @return list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string}> */
    private function libarchiveEntries(string $archivePath, string $extension): array
    {
        $archive = $this->newLibarchive($archivePath, $extension);
        $entries = [];
        $ordinal = 0;
        foreach ($archive as $archiveEntry) {
            $index = $ordinal++;
            if (!is_object($archiveEntry)) {
                continue;
            }

            // ext-archive 0.2.0 exposes pathname and size, but not file-type,
            // link or encryption virtual properties. Directory entries from the
            // archive formats we accept are represented by a trailing slash and
            // can be skipped without relying on unreleased metadata.
            $rawPath = trim((string)($archiveEntry->pathname ?? ''));
            $normalizedRawPath = str_replace('\\', '/', $rawPath);
            if ($rawPath === '' || str_ends_with($normalizedRawPath, '/')) {
                continue;
            }

            [$safePath, $reason] = $this->safeMemberPath($rawPath);
            $sizeValue = $archiveEntry->size ?? null;
            $entries[] = [
                'index' => $index,
                'path' => $safePath !== '' ? $safePath : $normalizedRawPath,
                'size' => $sizeValue !== null ? max(0, (int)$sizeValue) : 0,
                // Released 0.2.0 does not expose encryption metadata. An
                // encrypted member cannot be decoded through currentEntryStream
                // without a passphrase and therefore fails safely during the
                // bounded extraction attempt.
                'encrypted' => false,
                'safe' => $safePath !== '',
                'reason' => $reason,
                'backend' => 'libarchive',
            ];
            if (count($entries) > $this->maxEntries()) {
                throw new \RuntimeException(
                    'Archive contains too many entries; limit is ' . number_format($this->maxEntries()) . '.'
                );
            }
        }

        return $this->stableOrder($entries);
    }

    /** @param array{index:int,path:string,size:int} $entry */
    private function extractZipEntry(string $archivePath, array $entry, int $maxBytes): string
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('Could not reopen ZIP archive for extraction.');
        }

        $temporary = $this->temporaryPath();
        $input = null;
        $output = null;
        try {
            if (method_exists($zip, 'getStreamIndex')) {
                $input = $zip->getStreamIndex((int)$entry['index'], \ZipArchive::FL_UNCHANGED);
            } else {
                $input = $zip->getStream((string)$entry['path']);
            }
            if (!is_resource($input)) {
                throw new \RuntimeException('Could not open ZIP member stream.');
            }
            $output = fopen($temporary, 'wb');
            if (!is_resource($output)) {
                throw new \RuntimeException('Could not create temporary archive member.');
            }
            $this->copyBoundedStream($input, $output, $maxBytes, 'ZIP');
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $zip->close();
        }

        $this->verifyExtractedFile($temporary, (int)$entry['size'], $maxBytes);
        return $temporary;
    }

    /** @param array{index:int,path:string,size:int} $entry */
    private function extractLibarchiveEntry(string $archivePath, string $extension, array $entry, int $maxBytes): string
    {
        $this->requireLibarchive($extension);
        $archive = $this->newLibarchive($archivePath, $extension);
        $targetIndex = (int)$entry['index'];
        $targetPath = (string)$entry['path'];
        $expectedBytes = (int)$entry['size'];
        $ordinal = 0;

        foreach ($archive as $archiveEntry) {
            $index = $ordinal++;
            if ($index !== $targetIndex) {
                continue;
            }
            if (!is_object($archiveEntry)) {
                break;
            }

            $rawPath = trim((string)($archiveEntry->pathname ?? ''));
            [$safePath, $reason] = $this->safeMemberPath($rawPath);
            if ($safePath === '' || !hash_equals($targetPath, $safePath)) {
                throw new \RuntimeException(
                    'Archive member identity changed between listing and extraction'
                    . ($reason !== '' ? ': ' . $reason : '.')
                );
            }

            // Revalidate the only regular-entry metadata guaranteed by released
            // ext-archive 0.2.0. A link/directory entry has no useful positive
            // Unreal payload and will fail the positive/exact-size gate rather
            // than being written through an archive-controlled path.
            $currentSize = $archiveEntry->size ?? null;
            if ($currentSize === null || (int)$currentSize !== $expectedBytes || $expectedBytes < 1) {
                throw new \RuntimeException('Archive member size changed or is unavailable during extraction.');
            }

            $input = $archive->currentEntryStream();
            if (!is_resource($input)) {
                throw new \RuntimeException('Could not open libarchive member stream.');
            }
            $temporary = $this->temporaryPath();
            $output = fopen($temporary, 'wb');
            if (!is_resource($output)) {
                fclose($input);
                @unlink($temporary);
                throw new \RuntimeException('Could not create temporary archive member.');
            }

            try {
                $this->copyBoundedStream($input, $output, $maxBytes, 'libarchive');
            } catch (\Throwable $error) {
                @unlink($temporary);
                throw $error;
            } finally {
                fclose($input);
                fclose($output);
            }

            $this->verifyExtractedFile($temporary, $expectedBytes, $maxBytes);
            return $temporary;
        }

        throw new \RuntimeException('Archive member could not be located again for extraction.');
    }

    /** @param resource $input @param resource $output */
    private function copyBoundedStream($input, $output, int $maxBytes, string $label): void
    {
        $written = 0;
        while (!feof($input)) {
            $buffer = fread($input, 1024 * 1024);
            if (!is_string($buffer)) {
                throw new \RuntimeException('Could not read ' . $label . ' member stream.');
            }
            if ($buffer === '') {
                if (feof($input)) {
                    break;
                }
                throw new \RuntimeException($label . ' member stream stopped unexpectedly.');
            }
            $written += strlen($buffer);
            if ($written > $maxBytes) {
                throw new \RuntimeException('Archive member exceeded the configured extraction limit while unpacking.');
            }
            if (fwrite($output, $buffer) !== strlen($buffer)) {
                throw new \RuntimeException('Could not write temporary archive member.');
            }
        }
        fflush($output);
    }

    private function verifyExtractedFile(string $path, int $expectedBytes, int $maxBytes): void
    {
        if (!is_file($path) || is_link($path)) {
            @unlink($path);
            throw new \RuntimeException('Archive member did not produce a regular file.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || (int)$size > $maxBytes || (int)$size !== $expectedBytes) {
            @unlink($path);
            throw new \RuntimeException('Archive member output size does not match its declared size.');
        }
    }

    private function requireLibarchive(string $extension): void
    {
        if (class_exists(\libarchive\Archive::class)
            && method_exists(\libarchive\Archive::class, 'currentEntryStream')) {
            return;
        }
        $label = $extension === '7z' ? '7-Zip' : strtoupper($extension);
        throw new \RuntimeException(
            $label . ' archive support requires the PHP ext-archive/libarchive extension with currentEntryStream(). '
            . 'UnrealDB does not execute command-line archive tools.'
        );
    }

    private function newLibarchive(string $archivePath, string $extension): object
    {
        $this->requireLibarchive($extension);
        $archive = new \libarchive\Archive($archivePath);
        $formats = match ($extension) {
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

    /** @param list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string}> $entries */
    private function stableOrder(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            $path = strnatcasecmp((string)$left['path'], (string)$right['path']);
            return $path !== 0 ? $path : ((int)$left['index'] <=> (int)$right['index']);
        });
        return array_values($entries);
    }

    private function maxEntries(): int
    {
        return max(1, min(100000, (int)($this->config['archive']['max_entries'] ?? 10000)));
    }

    private function requireArchive(string $archivePath, string $archiveName): void
    {
        if (!self::isArchiveName($archiveName)) {
            throw new \InvalidArgumentException('Unsupported archive extension: ' . (string)pathinfo($archiveName, PATHINFO_EXTENSION));
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Archive source is unavailable.');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-archive-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate temporary archive-member storage.');
        }
        return $path;
    }
}
