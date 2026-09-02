<?php
/**
 * Safe unpack-only ZIP/7z/RAR/UMOD-family reader used by catalog ingestion jobs.
 *
 * Archive decoding is entirely in-process. ZIP prefers ext-zip (ZipArchive);
 * RAR and 7z use PHP archive extensions, while .umod/.ut2mod/.ut4mod use the
 * native Unreal Setup reader in CatalogUmodArchiveReader. No shell, command-line
 * archive binary, or platform-specific executable is used.
 *
 * Archive filename extensions are transport hints rather than authoritative
 * format declarations for ZIP/7z/RAR. Historic mirrors can contain mislabeled
 * archives and ZIP files may contain a prepended self-extracting stub. UMOD-family
 * containers are validated by their Unreal Setup footer/table/CRC structure.
 *
 * Archives are never extracted wholesale into a filesystem tree. Entries are
 * listed first, validated, then one requested regular file is extracted to a
 * controlled temporary file. This avoids path traversal and lets callers impose
 * their own Unreal-file policy before any member is unpacked.
 *
 * Released ext-archive 0.2.0 intentionally exposes only pathname/size/time/perm
 * entry metadata. Do not depend on unreleased virtual properties such as
 * isFile/isDir/isSymlink/hardlink/isEncrypted here. For libarchive extraction we
 * replace the current entry pathname with our own random temporary path before
 * calling extractCurrent(); archive-controlled paths are never written to disk.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

final class CatalogArchiveExtractor
{
    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar', 'umod', 'ut2mod', 'ut4mod'];
    private const FORMAT_SNIFF_BYTES = 65536;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @return list<string> */
    public static function archiveExtensions(): array
    {
        return self::ARCHIVE_EXTENSIONS;
    }

    public static function isArchiveName(string $name): bool
    {
        return in_array(strtolower((string)pathinfo($name, PATHINFO_EXTENSION)), self::ARCHIVE_EXTENSIONS, true);
    }

    /** @return array{zip:bool,libarchive:bool,rar:bool,seven_zip:bool,umod_family:bool} */
    public static function runtimeCapabilities(): array
    {
        $libarchive = class_exists(\libarchive\Archive::class)
            && method_exists(\libarchive\Archive::class, 'currentEntryStream');
        return [
            'zip' => class_exists(\ZipArchive::class) || $libarchive,
            'libarchive' => $libarchive,
            'rar' => class_exists(\RarArchive::class) || $libarchive,
            'seven_zip' => $libarchive,
            'umod_family' => true,
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
     *   backend:string,
     *   format:string
     * }>
     */
    public function entries(string $archivePath, string $archiveName): array
    {
        $this->requireArchive($archivePath, $archiveName);
        if (CatalogUmodArchiveReader::isName($archiveName)) {
            return (new CatalogUmodArchiveReader($this->config))->entries($archivePath, $archiveName);
        }

        $declaredExtension = strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION));
        $format = $this->detectArchiveFormat($archivePath, $declaredExtension);

        if ($format === 'zip' && class_exists(\ZipArchive::class)) {
            try {
                return $this->zipEntries($archivePath);
            } catch (\Throwable $zipError) {
                if (!$this->libarchiveAvailable()) {
                    throw $zipError;
                }
                try {
                    return $this->libarchiveEntries($archivePath, 'zip');
                } catch (\Throwable $libarchiveError) {
                    throw new \RuntimeException(
                        'Archive "' . $archiveName . '" could not be opened as ZIP. '
                        . 'ZipArchive: ' . $this->errorText($zipError) . ' '
                        . 'libarchive: ' . $this->errorText($libarchiveError) . ' '
                        . $this->formatDiagnostic($archivePath),
                        (int)$libarchiveError->getCode(),
                        $libarchiveError
                    );
                }
            }
        }

        $this->requireLibarchive($format);
        return $this->libarchiveEntries($archivePath, $format);
    }

    /**
     * Extract one previously listed entry to a temporary regular file.
     * Caller owns the returned file and must unlink it.
     *
     * @param array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string,format?:string} $entry
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

        $format = strtolower(trim((string)($entry['format'] ?? '')));
        if (!in_array($format, self::ARCHIVE_EXTENSIONS, true)) {
            $format = $this->detectArchiveFormat(
                $archivePath,
                strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION))
            );
        }

        return match ((string)($entry['backend'] ?? '')) {
            'zip' => $this->extractZipEntry($archivePath, $entry, $maxBytes),
            'libarchive' => $this->extractLibarchiveEntry($archivePath, $format, $entry, $maxBytes),
            'umod' => (new CatalogUmodArchiveReader($this->config))->extractToTemp(
                $archivePath,
                $archiveName,
                $entry,
                $maxBytes
            ),
            default => throw new \RuntimeException('Archive member backend is unavailable or invalid.'),
        };
    }

    /** @return list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string,format:string}> */
    private function zipEntries(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new \RuntimeException('Could not open ZIP archive (ZipArchive code ' . (string)$opened . ').');
        }

        $entries = [];
        try {
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
                    'format' => 'zip',
                ];
            }
        } finally {
            $zip->close();
        }

        return $this->stableOrder($entries);
    }

    /** @return list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string,format:string}> */
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
                // encrypted member cannot be decoded without a passphrase and
                // therefore fails safely during the bounded extraction attempt.
                'encrypted' => false,
                'safe' => $safePath !== '',
                'reason' => $reason,
                'backend' => 'libarchive',
                'format' => $extension,
            ];
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
        $primaryError = null;
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
            $primaryError = $error;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $zip->close();
        }

        if (!$primaryError instanceof \Throwable) {
            try {
                $this->verifyExtractedFile($temporary, (int)$entry['size'], $maxBytes);
                return $temporary;
            } catch (\Throwable $error) {
                $primaryError = $error;
            }
        }
        @unlink($temporary);

        // Some historic ZIPs have a usable same-name local member record even
        // when libzip's central-directory stream is truncated or stale. Recover
        // only that failed member and require local size + CRC32 verification;
        // already-successful siblings are not replayed.
        try {
            return (new CatalogZipLocalHeaderRecoveryReader($this->config))->extractExactMember(
                $archivePath,
                (string)$entry['path'],
                $maxBytes
            );
        } catch (\Throwable $recoveryError) {
            throw new \RuntimeException(
                'ZIP member "' . (string)$entry['path'] . '" failed normal extraction: '
                . $this->errorText($primaryError)
                . ' Local-header recovery also failed: ' . $this->errorText($recoveryError),
                (int)$recoveryError->getCode(),
                $recoveryError
            );
        }
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

            $currentSize = $archiveEntry->size ?? null;
            if ($currentSize === null || (int)$currentSize !== $expectedBytes || $expectedBytes < 1) {
                throw new \RuntimeException('Archive member size changed or is unavailable during extraction.');
            }

            $temporary = $this->temporaryPath();
            if (method_exists($archive, 'extractCurrent')) {
                try {
                    // tempnam() creates an empty file. Remove it before handing
                    // the path to libarchive's disk writer, then force the entry
                    // to our controlled absolute path rather than its archive path.
                    @unlink($temporary);
                    $archiveEntry->pathname = $temporary;
                    $archive->extractCurrent($archiveEntry);
                } catch (\Throwable $error) {
                    @unlink($temporary);
                    throw new \RuntimeException(
                        $this->libarchiveFailureMessage($extension, $targetPath, $expectedBytes, $error),
                        (int)$error->getCode(),
                        $error
                    );
                }

                $this->verifyExtractedFile($temporary, $expectedBytes, $maxBytes);
                return $temporary;
            }

            // Compatibility fallback for older/nonstandard builds that expose
            // currentEntryStream() but not extractCurrent(). Released 0.2.0 uses
            // the native extractCurrent() path above.
            $input = $archive->currentEntryStream();
            if (!is_resource($input)) {
                @unlink($temporary);
                throw new \RuntimeException('Could not open libarchive member stream.');
            }
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
                throw new \RuntimeException(
                    $this->libarchiveFailureMessage($extension, $targetPath, $expectedBytes, $error),
                    (int)$error->getCode(),
                    $error
                );
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
                $meta = stream_get_meta_data($input);
                throw new \RuntimeException(
                    $label . ' member stream stopped unexpectedly'
                    . '; bytes_copied=' . $written
                    . '; eof=' . (!empty($meta['eof']) ? 'true' : 'false')
                    . '; timed_out=' . (!empty($meta['timed_out']) ? 'true' : 'false') . '.'
                );
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

    private function libarchiveFailureMessage(
        string $extension,
        string $entryPath,
        int $expectedBytes,
        \Throwable $error
    ): string {
        $detail = trim($error->getMessage());
        if ($detail === '') {
            $detail = get_class($error);
        }
        $label = $extension === '7z' ? '7-Zip' : strtoupper($extension);
        return $label . ' archive member "' . $entryPath . '" could not be extracted by libarchive '
            . '(' . get_class($error) . ', declared ' . number_format($expectedBytes) . ' bytes): ' . $detail;
    }

    private function verifyExtractedFile(string $path, int $expectedBytes, int $maxBytes): void
    {
        if (!is_file($path) || is_link($path)) {
            @unlink($path);
            throw new \RuntimeException('Archive member did not produce a regular file.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || (int)$size > $maxBytes || (int)$size !== $expectedBytes) {
            $actual = $size === false ? 'unknown' : number_format((int)$size);
            @unlink($path);
            throw new \RuntimeException(
                'Archive member output size does not match its declared size; expected '
                . number_format($expectedBytes) . ' bytes, got ' . $actual . ' bytes.'
            );
        }
    }

    private function detectArchiveFormat(string $archivePath, string $declaredExtension): string
    {
        $declaredExtension = strtolower(trim($declaredExtension));
        if (!in_array($declaredExtension, self::ARCHIVE_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported archive extension: ' . $declaredExtension);
        }
        if (CatalogUmodArchiveReader::isName('archive.' . $declaredExtension)) {
            return $declaredExtension;
        }

        // ZipArchive is authoritative for ZIP and correctly accepts legal
        // prepended/self-extracting data that a byte-zero signature test rejects.
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
        $candidates = [
            '7z' => ["7z\xBC\xAF\x27\x1C"],
            'rar' => ["Rar!\x1A\x07\x00", "Rar!\x1A\x07\x01\x00"],
            'zip' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        ];
        $bestFormat = '';
        $bestOffset = PHP_INT_MAX;
        foreach ($candidates as $format => $signatures) {
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

        // No small-prefix signature is conclusive. Keep the declared format and
        // let the complete parser decide; this is important for unusual SFX
        // stubs whose embedded archive begins after the bounded sniff window.
        return $declaredExtension;
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

    private function formatDiagnostic(string $path): string
    {
        try {
            $prefix = $this->readPrefix($path, 16);
        } catch (\Throwable) {
            return 'First bytes unavailable.';
        }
        if ($prefix === '') {
            return 'Archive is empty.';
        }
        $hex = strtoupper(implode(' ', str_split(bin2hex($prefix), 2)));
        $ascii = preg_replace('/[^\x20-\x7E]/', '.', $prefix) ?? '';
        return 'First bytes: ' . $hex . ' (ASCII "' . $ascii . '").';
    }

    private function libarchiveAvailable(): bool
    {
        return class_exists(\libarchive\Archive::class)
            && method_exists(\libarchive\Archive::class, 'currentEntryStream');
    }

    private function errorText(\Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? $message : get_class($error);
    }

    private function requireLibarchive(string $extension): void
    {
        if ($this->libarchiveAvailable()) {
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
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return ['', 'absolute drive path'];
        }

        // A number of historic Unreal archives store entries as /System/Foo.u or
        // /Maps/Map.ut2. UnrealDB never writes archive-controlled paths directly to
        // disk; extraction goes to a random temporary file. Treat leading slashes
        // as an archive-root marker, then apply the normal traversal/component
        // checks to the resulting relative path. This recovers those archives
        // without weakening the '..' or drive-path protections.
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

    /** @param list<array{index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,backend:string,format?:string}> $entries */
    private function stableOrder(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            $path = strnatcasecmp((string)$left['path'], (string)$right['path']);
            return $path !== 0 ? $path : ((int)$left['index'] <=> (int)$right['index']);
        });
        return array_values($entries);
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
