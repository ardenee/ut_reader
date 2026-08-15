<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `BlockedCompressedMetadataContainer` for blocked compressed metadata
 *          container.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use RuntimeException;
use Throwable;

/** Builds the version-2 random-access, block-compressed metadata container. */
final class BlockedCompressedMetadataContainer
{
    public const FORMAT_VERSION = 2;
    public const CODEC_BLOCK_GZIP = 2;
    public const DEFAULT_BLOCK_SIZE = 500;

    private const MAGIC = "UEDBM2\0\0";
    private const HEADER_LENGTH = 20;
    private const COPY_BUFFER_BYTES = 1024 * 1024;

    /**
     * Compatibility in-memory builder. Production publication uses buildToFile().
     *
     * @param array<string,mixed> $snapshot
     * @return array{bytes:string,uncompressed_size:int,block_count:int,manifest:array<string,mixed>}
     */
    public static function build(array $snapshot, int $blockSize = self::DEFAULT_BLOCK_SIZE): array
    {
        self::assertZlib();
        $payload = '';
        $built = self::buildPayload(
            $snapshot,
            $blockSize,
            static function (string $compressed) use (&$payload): void {
                // .= can extend the sole string buffer in place. Unlike retaining
                // every block in an array and implode(), it does not require a
                // second complete payload allocation at the end of block creation.
                $payload .= $compressed;
            }
        );
        $fileId = (int)$built['file_id'];
        $manifestJson = self::encodeManifest((array)$built['manifest']);
        $bytes = self::header($manifestJson) . $manifestJson . $payload;
        unset($payload);
        self::verifyBytes($bytes, $fileId);

        return [
            'bytes' => $bytes,
            'uncompressed_size' => (int)$built['uncompressed_size'] + strlen($manifestJson),
            'block_count' => (int)$built['block_count'],
            'manifest' => (array)$built['manifest'],
        ];
    }

    /**
     * Build directly to disk with peak container memory bounded to one metadata
     * block, one compressed block and the manifest. This is the production path.
     *
     * @param array<string,mixed> $snapshot
     * @return array{path:string,compressed_size:int,payload_sha256:string,uncompressed_size:int,block_count:int,manifest:array<string,mixed>}
     */
    public static function buildToFile(
        array $snapshot,
        string $path,
        int $blockSize = self::DEFAULT_BLOCK_SIZE
    ): array {
        self::assertZlib();
        if (trim($path) === '') {
            throw new RuntimeException('A compact metadata output path is required.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create compact metadata output directory: ' . $directory);
        }

        $payloadPath = $path . '.payload.' . bin2hex(random_bytes(8));
        $payload = @fopen($payloadPath, 'w+b');
        if (!is_resource($payload)) {
            throw new RuntimeException('Could not create compact metadata payload staging file.');
        }

        try {
            $built = self::buildPayload(
                $snapshot,
                $blockSize,
                static function (string $compressed) use ($payload): void {
                    self::writeAll($payload, $compressed);
                }
            );
            $manifestJson = self::encodeManifest((array)$built['manifest']);
            $header = self::header($manifestJson);

            if (!@rewind($payload)) {
                throw new RuntimeException('Could not rewind compact metadata payload staging file.');
            }
            $target = @fopen($path, 'wb');
            if (!is_resource($target)) {
                throw new RuntimeException('Could not create compact metadata container: ' . $path);
            }
            try {
                self::writeAll($target, $header);
                self::writeAll($target, $manifestJson);
                while (!feof($payload)) {
                    $chunk = fread($payload, self::COPY_BUFFER_BYTES);
                    if ($chunk === false) {
                        throw new RuntimeException('Could not read compact metadata payload staging file.');
                    }
                    if ($chunk !== '') {
                        self::writeAll($target, $chunk);
                    }
                }
                if (!fflush($target)) {
                    throw new RuntimeException('Could not flush compact metadata container.');
                }
            } finally {
                fclose($target);
            }
        } catch (Throwable $error) {
            @unlink($path);
            throw $error;
        } finally {
            fclose($payload);
            @unlink($payloadPath);
        }

        $verified = self::verifyFile($path, (int)$built['file_id']);
        return [
            'path' => $path,
            'compressed_size' => (int)$verified['compressed_size'],
            'payload_sha256' => (string)$verified['payload_sha256'],
            'uncompressed_size' => (int)$built['uncompressed_size'] + strlen($manifestJson),
            'block_count' => (int)$built['block_count'],
            'manifest' => (array)$built['manifest'],
        ];
    }

    /** @return array<string,mixed> */
    public static function verifyBytes(string $bytes, int $expectedFileId): array
    {
        if (strlen($bytes) < self::HEADER_LENGTH) {
            throw new RuntimeException('Blocked metadata container is too small.');
        }
        $header = unpack('a8magic/vversion/vcodec/Vmanifest_length/Vreserved', substr($bytes, 0, self::HEADER_LENGTH));
        if (!is_array($header) || (string)$header['magic'] !== self::MAGIC) {
            throw new RuntimeException('Blocked metadata container magic is invalid.');
        }
        if ((int)$header['version'] !== self::FORMAT_VERSION || (int)$header['codec'] !== self::CODEC_BLOCK_GZIP) {
            throw new RuntimeException('Blocked metadata container version or codec is unsupported.');
        }
        $manifestLength = (int)$header['manifest_length'];
        if ($manifestLength < 2 || self::HEADER_LENGTH + $manifestLength > strlen($bytes)) {
            throw new RuntimeException('Blocked metadata manifest length is invalid.');
        }
        try {
            $manifest = json_decode(
                substr($bytes, self::HEADER_LENGTH, $manifestLength),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Blocked metadata manifest is invalid JSON.', 0, $error);
        }
        if (!is_array($manifest) || (int)($manifest['file']['id'] ?? 0) !== $expectedFileId) {
            throw new RuntimeException('Blocked metadata manifest identity mismatch.');
        }

        $payloadStart = self::HEADER_LENGTH + $manifestLength;
        $verifiedBlocks = 0;
        foreach ((array)($manifest['sections'] ?? []) as $section => $blocks) {
            if (!in_array($section, ['names', 'imports', 'exports', 'dependencies'], true)) {
                throw new RuntimeException('Blocked metadata manifest contains an unknown section.');
            }
            foreach ((array)$blocks as $block) {
                if (!is_array($block)) {
                    throw new RuntimeException('Blocked metadata manifest contains an invalid block.');
                }
                $offset = (int)($block['offset'] ?? -1);
                $length = (int)($block['compressed_length'] ?? 0);
                if ($offset < 0 || $length < 1 || $payloadStart + $offset + $length > strlen($bytes)) {
                    throw new RuntimeException('Blocked metadata block bounds are invalid.');
                }
                $compressed = substr($bytes, $payloadStart + $offset, $length);
                self::verifyCompressedBlock($compressed, $block);
                $verifiedBlocks++;
            }
        }

        return ['manifest' => $manifest, 'block_count' => $verifiedBlocks];
    }

    /**
     * Verify a container directly from disk while keeping only one compressed
     * block and its decoded JSON in memory at a time.
     *
     * @return array{manifest:array<string,mixed>,block_count:int,payload_sha256:string,compressed_size:int}
     */
    public static function verifyFile(
        string $path,
        int $expectedFileId,
        ?string $expectedPayloadSha256 = null
    ): array {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size < self::HEADER_LENGTH) {
            throw new RuntimeException('Blocked metadata container is missing or too small: ' . $path);
        }
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Could not open blocked metadata container: ' . $path);
        }

        $hash = hash_init('sha256');
        try {
            $headerBytes = self::readExactly($stream, self::HEADER_LENGTH);
            hash_update($hash, $headerBytes);
            $header = unpack('a8magic/vversion/vcodec/Vmanifest_length/Vreserved', $headerBytes);
            if (!is_array($header) || (string)$header['magic'] !== self::MAGIC) {
                throw new RuntimeException('Blocked metadata container magic is invalid.');
            }
            if ((int)$header['version'] !== self::FORMAT_VERSION || (int)$header['codec'] !== self::CODEC_BLOCK_GZIP) {
                throw new RuntimeException('Blocked metadata container version or codec is unsupported.');
            }

            $manifestLength = (int)$header['manifest_length'];
            if ($manifestLength < 2 || self::HEADER_LENGTH + $manifestLength > (int)$size) {
                throw new RuntimeException('Blocked metadata manifest length is invalid.');
            }
            $manifestBytes = self::readExactly($stream, $manifestLength);
            hash_update($hash, $manifestBytes);
            try {
                $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Blocked metadata manifest is invalid JSON.', 0, $error);
            }
            if (!is_array($manifest) || (int)($manifest['file']['id'] ?? 0) !== $expectedFileId) {
                throw new RuntimeException('Blocked metadata manifest identity mismatch.');
            }

            $expectedOffset = 0;
            $verifiedBlocks = 0;
            foreach ((array)($manifest['sections'] ?? []) as $section => $blocks) {
                if (!in_array($section, ['names', 'imports', 'exports', 'dependencies'], true)) {
                    throw new RuntimeException('Blocked metadata manifest contains an unknown section.');
                }
                foreach ((array)$blocks as $block) {
                    if (!is_array($block)) {
                        throw new RuntimeException('Blocked metadata manifest contains an invalid block.');
                    }
                    $offset = (int)($block['offset'] ?? -1);
                    $length = (int)($block['compressed_length'] ?? 0);
                    if ($offset !== $expectedOffset || $length < 1 || $expectedOffset + $length > (int)$size) {
                        throw new RuntimeException('Blocked metadata block bounds or ordering are invalid.');
                    }
                    $compressed = self::readExactly($stream, $length);
                    hash_update($hash, $compressed);
                    self::verifyCompressedBlock($compressed, $block);
                    $expectedOffset += $length;
                    $verifiedBlocks++;
                }
            }

            $expectedSize = self::HEADER_LENGTH + $manifestLength + $expectedOffset;
            if ($expectedSize !== (int)$size) {
                throw new RuntimeException(
                    'Blocked metadata container has unexpected trailing or missing bytes: expected='
                    . $expectedSize . ', actual=' . (int)$size . '.'
                );
            }
            $payloadSha256 = hash_final($hash, true);
            if ($expectedPayloadSha256 !== null
                && !hash_equals($expectedPayloadSha256, $payloadSha256)) {
                throw new RuntimeException('Blocked metadata container SHA-256 mismatch.');
            }

            return [
                'manifest' => $manifest,
                'block_count' => $verifiedBlocks,
                'payload_sha256' => $payloadSha256,
                'compressed_size' => (int)$size,
            ];
        } finally {
            fclose($stream);
        }
    }

    public static function path(string $storageRoot, int $gameId, int $fileId): string
    {
        $root = rtrim($storageRoot, "\\/");
        $shard = str_pad((string)intdiv($fileId, 1000), 6, '0', STR_PAD_LEFT);
        return $root . DIRECTORY_SEPARATOR . 'metadata'
            . DIRECTORY_SEPARATOR . $gameId
            . DIRECTORY_SEPARATOR . $shard
            . DIRECTORY_SEPARATOR . $fileId . '.uedb2';
    }

    /**
     * Build every section with at most one source chunk and one compressed block
     * materialized at a time.
     *
     * @param array<string,mixed> $snapshot
     * @param callable(string):void $consumeCompressed
     * @return array{file_id:int,manifest:array<string,mixed>,uncompressed_size:int,block_count:int}
     */
    private static function buildPayload(array $snapshot, int $blockSize, callable $consumeCompressed): array
    {
        $blockSize = max(100, min(2000, $blockSize));
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('The metadata snapshot has no valid file ID.');
        }

        $manifest = [
            'format' => 'unrealdb.blocked-file-metadata',
            'format_version' => self::FORMAT_VERSION,
            'codec' => 'gzip-blocks',
            'block_size' => $blockSize,
            'file' => [
                'id' => $fileId,
                'game_id' => (int)($file['game_id'] ?? 0),
                'package_name' => (string)($file['package_name'] ?? ''),
                'original_name' => (string)($file['original_name'] ?? ''),
            ],
            'counts' => [
                'names' => count((array)($snapshot['names'] ?? [])),
                'imports' => count((array)($snapshot['imports'] ?? [])),
                'exports' => count((array)($snapshot['exports'] ?? [])),
                'dependencies' => count((array)($snapshot['dependencies'] ?? [])),
            ],
            'sections' => [
                'names' => [],
                'imports' => [],
                'exports' => [],
                'dependencies' => [],
            ],
        ];

        $payloadOffset = 0;
        $uncompressedSize = 0;
        $blockCount = 0;
        $paths = (array)($snapshot['paths'] ?? []);

        foreach (['names', 'imports', 'exports', 'dependencies'] as $section) {
            $rows = (array)($snapshot[$section] ?? []);
            $rowCount = count($rows);
            for ($rowStart = 0; $rowStart < $rowCount; $rowStart += $blockSize) {
                $chunk = array_slice($rows, $rowStart, $blockSize);
                $block = self::encodeBlock($section, $chunk, $paths);
                try {
                    $json = json_encode(
                        $block,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    );
                } catch (JsonException $error) {
                    throw new RuntimeException(
                        'Could not encode ' . $section . ' metadata block: ' . $error->getMessage(),
                        0,
                        $error
                    );
                }
                if (!is_string($json)) {
                    throw new RuntimeException('Could not encode ' . $section . ' metadata block.');
                }
                $compressed = gzencode($json, 6, ZLIB_ENCODING_GZIP);
                if (!is_string($compressed) || $compressed === '') {
                    throw new RuntimeException('Could not compress ' . $section . ' metadata block.');
                }

                $compressedLength = strlen($compressed);
                $manifest['sections'][$section][] = [
                    'row_start' => $rowStart,
                    'row_count' => count($chunk),
                    'offset' => $payloadOffset,
                    'compressed_length' => $compressedLength,
                    'uncompressed_length' => strlen($json),
                    'sha256' => hash('sha256', $compressed),
                ];
                $consumeCompressed($compressed);
                $payloadOffset += $compressedLength;
                $uncompressedSize += strlen($json);
                $blockCount++;
                unset($block, $json, $compressed, $chunk);
            }
        }

        return [
            'file_id' => $fileId,
            'manifest' => $manifest,
            'uncompressed_size' => $uncompressedSize,
            'block_count' => $blockCount,
        ];
    }

    /** @param array<string,mixed> $manifest */
    private static function encodeManifest(array $manifest): string
    {
        try {
            $json = json_encode(
                $manifest,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Could not encode metadata manifest: ' . $error->getMessage(), 0, $error);
        }
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode metadata manifest.');
        }
        return $json;
    }

    private static function header(string $manifestJson): string
    {
        return pack(
            'a8vvVV',
            self::MAGIC,
            self::FORMAT_VERSION,
            self::CODEC_BLOCK_GZIP,
            strlen($manifestJson),
            0
        );
    }

    private static function assertZlib(): void
    {
        if (!function_exists('gzencode') || !function_exists('gzdecode')) {
            throw new RuntimeException('The PHP zlib extension is required for block-compressed metadata.');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $paths
     * @return array{strings:list<string>,rows:list<list<mixed>>}
     */
    private static function encodeBlock(string $section, array $rows, array $paths): array
    {
        $strings = [];
        $stringIds = [];
        $intern = static function (?string $value) use (&$strings, &$stringIds): ?int {
            if ($value === null || $value === '') {
                return null;
            }
            $key = 's:' . $value;
            if (array_key_exists($key, $stringIds)) {
                return $stringIds[$key];
            }
            $id = count($strings);
            $strings[] = $value;
            $stringIds[$key] = $id;
            return $id;
        };

        $encoded = [];
        foreach ($rows as $row) {
            switch ($section) {
                case 'names':
                    $encoded[] = [
                        (int)$row['name_index'],
                        $intern((string)$row['name_text']),
                        $row['flags'] !== null ? (string)$row['flags'] : null,
                    ];
                    break;

                case 'imports':
                    $index = (int)$row['import_index'];
                    $path = (array)($paths['imports'][$index] ?? []);
                    $encoded[] = [
                        $index,
                        $intern(trim((string)($row['class_package'] ?? ''))),
                        $intern(trim((string)($row['class_name'] ?? ''))),
                        $intern((string)$row['object_name']),
                        (int)$row['outer_index'],
                        $intern((string)($path['full'] ?? $row['full_path'] ?? '')),
                        $intern((string)($path['root'] ?? $row['root_package'] ?? '')),
                        $intern((string)($path['relative'] ?? $row['relative_object_path'] ?? '')),
                        (int)$row['is_common'],
                    ];
                    break;

                case 'exports':
                    $index = (int)$row['export_index'];
                    $path = (array)($paths['exports'][$index] ?? []);
                    $encoded[] = [
                        $index,
                        $intern(trim((string)($row['class_name'] ?? ''))),
                        $intern((string)$row['object_name']),
                        (int)$row['outer_index'],
                        $intern((string)($path['local'] ?? $row['local_path'] ?? '')),
                        $intern((string)($path['full'] ?? $row['full_path'] ?? '')),
                        $row['object_flags'] !== null ? (string)$row['object_flags'] : null,
                        $row['serial_size'] !== null ? (string)$row['serial_size'] : null,
                        $row['serial_offset'] !== null ? (string)$row['serial_offset'] : null,
                    ];
                    break;

                case 'dependencies':
                    [$status, $source, $confidence] = CompressedMetadataLegacySnapshot::dependencyCodes(
                        strtolower(trim((string)$row['status']))
                    );
                    $encoded[] = [
                        (int)$row['import_index'],
                        $intern((string)$row['required_package']),
                        $intern((string)$row['required_object_path']),
                        $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                        $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                        $status,
                        $source,
                        $confidence,
                    ];
                    break;

                default:
                    throw new RuntimeException('Unsupported blocked metadata section: ' . $section);
            }
        }

        return ['strings' => $strings, 'rows' => $encoded];
    }

    /** @param array<string,mixed> $block */
    private static function verifyCompressedBlock(string $compressed, array $block): void
    {
        if (!hash_equals((string)($block['sha256'] ?? ''), hash('sha256', $compressed))) {
            throw new RuntimeException('Blocked metadata block checksum mismatch.');
        }
        $json = gzdecode($compressed);
        if (!is_string($json) || strlen($json) !== (int)($block['uncompressed_length'] ?? -1)) {
            throw new RuntimeException('Blocked metadata block decompression failed.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Blocked metadata block is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded) || count((array)($decoded['rows'] ?? [])) !== (int)($block['row_count'] ?? -1)) {
            throw new RuntimeException('Blocked metadata block row count mismatch.');
        }
    }

    /** @param resource $stream */
    private static function readExactly($stream, int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Invalid blocked metadata read length.');
        }
        $buffer = '';
        while (strlen($buffer) < $length && !feof($stream)) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Could not read blocked metadata container.');
            }
            $buffer .= $chunk;
        }
        if (strlen($buffer) !== $length) {
            throw new RuntimeException('Blocked metadata container ended unexpectedly.');
        }
        return $buffer;
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written < 1) {
                throw new RuntimeException('Could not completely write blocked metadata container.');
            }
            $offset += $written;
        }
    }
}
