<?php
/**
 * Native in-process reader for Unreal Setup UMOD-family containers.
 *
 * .umod, .ut2mod and .ut4mod share the Unreal Setup archive layout already
 * emitted by CatalogGeneratedUmodWriter: raw member payloads, a compact-index
 * directory table and a 20-byte footer containing magic/table/size/version/CRC.
 * No external executable or archive extension is involved.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogUmodBinaryCodec;

final class CatalogUmodArchiveReader
{
    private const EXTENSIONS = ['umod', 'ut2mod', 'ut4mod'];
    private const FOOTER_BYTES = 20;
    private const MAGIC = 0x9FE3C5A3;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function isName(string $name): bool
    {
        return in_array(
            strtolower((string)pathinfo($name, PATHINFO_EXTENSION)),
            self::EXTENSIONS,
            true
        );
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return self::EXTENSIONS;
    }

    /**
     * @return list<array{
     *   index:int,path:string,size:int,encrypted:bool,safe:bool,reason:string,
     *   backend:string,format:string,offset:int,flags:int
     * }>
     */
    public function entries(string $archivePath, string $archiveName): array
    {
        $this->requireSource($archivePath, $archiveName);
        $handle = fopen($archivePath, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not open UMOD-family archive.');
        }

        try {
            $footer = $this->readFooter($handle, $archivePath, true);
            $tableOffset = $footer['table'];
            $tableBytes = ($footer['size'] - self::FOOTER_BYTES) - $tableOffset;
            if ($tableBytes < 1 || $tableBytes > $this->maxDirectoryBytes()) {
                throw new \RuntimeException(
                    'UMOD directory size is invalid or exceeds the configured safety limit.'
                );
            }
            if (fseek($handle, $tableOffset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Could not seek to the UMOD directory table.');
            }

            $table = '';
            $remaining = $tableBytes;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException('Could not completely read the UMOD directory table.');
                }
                $table .= $chunk;
                $remaining -= strlen($chunk);
            }

            $cursor = 0;
            $count = CatalogUmodBinaryCodec::readCompactIndex($table, $cursor);
            if ($count < 0 || $count > $this->maxEntries()) {
                throw new \RuntimeException(
                    'UMOD contains an invalid number of entries; limit is '
                    . number_format($this->maxEntries()) . '.'
                );
            }

            $format = strtolower((string)pathinfo($archiveName, PATHINFO_EXTENSION));
            $entries = [];
            for ($index = 0; $index < $count; $index++) {
                $rawPath = CatalogUmodBinaryCodec::readUe1String($table, $cursor);
                if ($cursor + 12 > strlen($table)) {
                    throw new \RuntimeException('UMOD directory entry is truncated.');
                }
                $item = unpack('Voffset/Vsize/Vflags', substr($table, $cursor, 12));
                $cursor += 12;
                if (!is_array($item)) {
                    throw new \RuntimeException('Could not decode a UMOD directory entry.');
                }

                $itemOffset = (int)$item['offset'];
                $itemSize = (int)$item['size'];
                if ($itemOffset < 0 || $itemSize < 0 || $itemOffset + $itemSize > $tableOffset) {
                    throw new \RuntimeException(
                        'UMOD member points outside the payload: ' . $rawPath
                    );
                }

                [$safePath, $reason] = $this->safeMemberPath($rawPath);
                $entries[] = [
                    'index' => $index,
                    'path' => $safePath !== '' ? $safePath : str_replace('\\', '/', $rawPath),
                    'size' => $itemSize,
                    'encrypted' => false,
                    'safe' => $safePath !== '',
                    'reason' => $reason,
                    'backend' => 'umod',
                    'format' => $format,
                    'offset' => $itemOffset,
                    'flags' => (int)$item['flags'],
                ];
            }

            return $this->stableOrder($entries);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extract one already-listed UMOD member by bounded offset/size copy.
     * Caller owns the returned temporary file.
     *
     * @param array<string,mixed> $entry
     */
    public function extractToTemp(
        string $archivePath,
        string $archiveName,
        array $entry,
        int $maxBytes
    ): string {
        $this->requireSource($archivePath, $archiveName);
        if (empty($entry['safe'])) {
            throw new \RuntimeException(
                'UMOD member is unsafe: ' . (string)($entry['reason'] ?? 'invalid path')
            );
        }

        $expected = (int)($entry['size'] ?? -1);
        $offset = (int)($entry['offset'] ?? -1);
        $maxBytes = max(1, $maxBytes);
        if ($expected < 1 || $expected > $maxBytes || $offset < 0) {
            throw new \RuntimeException('UMOD member exceeds the configured extraction limit.');
        }

        $input = fopen($archivePath, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Could not reopen UMOD-family archive for extraction.');
        }
        $temporary = $this->temporaryPath();
        $output = null;

        try {
            // Re-read only the structural footer here. CRC validation happened
            // during listing; repeating a full-archive CRC for every member would
            // turn one archive into an O(files * archive-size) operation.
            $footer = $this->readFooter($input, $archivePath, false);
            if ($offset + $expected > $footer['table']) {
                throw new \RuntimeException('UMOD member no longer fits inside the archive payload.');
            }
            if (fseek($input, $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Could not seek to the UMOD member payload.');
            }
            $output = fopen($temporary, 'wb');
            if (!is_resource($output)) {
                throw new \RuntimeException('Could not create temporary UMOD member storage.');
            }

            $remaining = $expected;
            $written = 0;
            while ($remaining > 0) {
                $buffer = fread($input, min(1024 * 1024, $remaining));
                if (!is_string($buffer) || $buffer === '') {
                    throw new \RuntimeException(
                        'UMOD member stream stopped unexpectedly; bytes_copied=' . $written
                        . '; expected_bytes=' . $expected . '.'
                    );
                }
                $length = strlen($buffer);
                if (fwrite($output, $buffer) !== $length) {
                    throw new \RuntimeException('Could not write temporary UMOD member storage.');
                }
                $written += $length;
                $remaining -= $length;
            }
            if (!fflush($output)) {
                throw new \RuntimeException('Could not flush temporary UMOD member storage.');
            }
        } catch (\Throwable $error) {
            @unlink($temporary);
            throw $error;
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }

        $size = filesize($temporary);
        if ($size === false || (int)$size !== $expected) {
            @unlink($temporary);
            throw new \RuntimeException(
                'UMOD member output size does not match its declared size; expected '
                . number_format($expected) . ' bytes.'
            );
        }
        return $temporary;
    }

    /**
     * @param resource $handle
     * @return array{magic:int,table:int,size:int,version:int,crc:int}
     */
    private function readFooter($handle, string $archivePath, bool $verifyCrc): array
    {
        $fileSize = filesize($archivePath);
        if ($fileSize === false || (int)$fileSize < self::FOOTER_BYTES) {
            throw new \RuntimeException('UMOD-family archive is too small.');
        }
        if (fseek($handle, -self::FOOTER_BYTES, SEEK_END) !== 0) {
            throw new \RuntimeException('Could not seek to the UMOD footer.');
        }
        $bytes = fread($handle, self::FOOTER_BYTES);
        if (!is_string($bytes) || strlen($bytes) !== self::FOOTER_BYTES) {
            throw new \RuntimeException('Could not read the UMOD footer.');
        }
        $footer = unpack('Vmagic/Vtable/Vsize/Vversion/Vcrc', $bytes);
        if (!is_array($footer)) {
            throw new \RuntimeException('Could not decode the UMOD footer.');
        }
        $footer = array_map('intval', $footer);
        if ($footer['magic'] !== self::MAGIC) {
            throw new \RuntimeException('UMOD-family archive has invalid Unreal Setup magic.');
        }
        if ($footer['version'] !== 1) {
            throw new \RuntimeException('Unsupported UMOD-family archive version: ' . $footer['version'] . '.');
        }
        if ($footer['size'] !== (int)$fileSize) {
            throw new \RuntimeException('UMOD-family archive size footer does not match the source file.');
        }
        if ($footer['table'] < 0 || $footer['table'] >= $footer['size'] - self::FOOTER_BYTES) {
            throw new \RuntimeException('UMOD-family archive has an invalid directory-table offset.');
        }

        if ($verifyCrc) {
            $actual = CatalogUmodBinaryCodec::unrealMemCrcStream(
                $handle,
                $footer['size'] - self::FOOTER_BYTES
            );
            if (($actual & 0xFFFFFFFF) !== ($footer['crc'] & 0xFFFFFFFF)) {
                throw new \RuntimeException(
                    'UMOD-family archive CRC does not match its footer'
                    . '; expected=' . sprintf('%08X', $footer['crc'] & 0xFFFFFFFF)
                    . '; actual=' . sprintf('%08X', $actual & 0xFFFFFFFF)
                    . '; checked_bytes=' . number_format($footer['size'] - self::FOOTER_BYTES)
                    . '.'
                );
            }
        }
        return $footer;
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

    /** @param list<array<string,mixed>> $entries @return list<array<string,mixed>> */
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

    private function maxDirectoryBytes(): int
    {
        return max(
            1024 * 1024,
            min(128 * 1024 * 1024, (int)($this->config['archive']['max_directory_bytes'] ?? (32 * 1024 * 1024)))
        );
    }

    private function requireSource(string $archivePath, string $archiveName): void
    {
        if (!self::isName($archiveName)) {
            throw new \InvalidArgumentException(
                'Unsupported UMOD-family extension: ' . (string)pathinfo($archiveName, PATHINFO_EXTENSION)
            );
        }
        if (!is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('UMOD-family archive source is unavailable.');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'unrealdb-umod-');
        if (!is_string($path)) {
            throw new \RuntimeException('Could not allocate temporary UMOD member storage.');
        }
        return $path;
    }
}
