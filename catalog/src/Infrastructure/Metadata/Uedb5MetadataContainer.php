<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the isolated UEDB5 block-container framing used by offline/staging writers.
 * Why: UEDB5 needs a new magic/version/extension without teaching the UEDB4 production runtime to read it.
 * Role: Low-level format-5 file transport only; source-specific schemas and SQL publication live above this class.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use RuntimeException;
use Throwable;

final class Uedb5MetadataContainer
{
    public const FORMAT_VERSION = 5;
    public const CODEC_BLOCK_GZIP = 2;
    public const DEFAULT_BLOCK_SIZE = 500;
    public const HEADER_LENGTH = 20;
    public const MAGIC = "UEDBM5\0\0";
    public const MANIFEST_FORMAT = 'unrealdb.uedb5-metadata';
    public const PAYLOAD_ENCODING = 'json-rows-v1';

    private const COPY_BUFFER_BYTES = 1024 * 1024;

    /**
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
                $payload .= $compressed;
            }
        );
        $manifestJson = self::encodeManifest((array)$built['manifest']);
        $bytes = self::header($manifestJson) . $manifestJson . $payload;
        self::verifyBytes($bytes, (int)$built['file_id']);

        return [
            'bytes' => $bytes,
            'uncompressed_size' => (int)$built['uncompressed_size'] + strlen($manifestJson),
            'block_count' => (int)$built['block_count'],
            'manifest' => (array)$built['manifest'],
        ];
    }

    /**
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
            throw new RuntimeException('A UEDB5 output path is required.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create UEDB5 output directory: ' . $directory);
        }

        $payloadPath = $path . '.payload.' . bin2hex(random_bytes(8));
        $payload = @fopen($payloadPath, 'w+b');
        if (!is_resource($payload)) {
            throw new RuntimeException('Could not create UEDB5 payload staging file.');
        }

        try {
            $built = self::buildPayload($snapshot, $blockSize, static function (string $compressed) use ($payload): void {
                self::writeAll($payload, $compressed);
            });
            $manifestJson = self::encodeManifest((array)$built['manifest']);
            $header = self::header($manifestJson);
            if (!@rewind($payload)) {
                throw new RuntimeException('Could not rewind UEDB5 payload staging file.');
            }

            $target = @fopen($path, 'wb');
            if (!is_resource($target)) {
                throw new RuntimeException('Could not create UEDB5 container: ' . $path);
            }
            try {
                self::writeAll($target, $header);
                self::writeAll($target, $manifestJson);
                while (!feof($payload)) {
                    $chunk = fread($payload, self::COPY_BUFFER_BYTES);
                    if ($chunk === false) {
                        throw new RuntimeException('Could not read UEDB5 payload staging file.');
                    }
                    if ($chunk !== '') {
                        self::writeAll($target, $chunk);
                    }
                }
                if (!fflush($target)) {
                    throw new RuntimeException('Could not flush UEDB5 container.');
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

    public static function path(string $storageRoot, int $gameId, int $fileId): string
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required for a UEDB5 path.');
        }
        $root = rtrim($storageRoot, "\\/");
        if ($root === '') {
            throw new RuntimeException('A storage root is required for a UEDB5 path.');
        }
        $shard = str_pad((string)intdiv($fileId, 1000), 6, '0', STR_PAD_LEFT);
        return $root . DIRECTORY_SEPARATOR . 'metadata'
            . DIRECTORY_SEPARATOR . $gameId
            . DIRECTORY_SEPARATOR . $shard
            . DIRECTORY_SEPARATOR . $fileId . '.uedb5';
    }

    /** @return array{manifest:array<string,mixed>,block_count:int,payload_start:int} */
    public static function verifyBytes(string $bytes, int $expectedFileId): array
    {
        if (strlen($bytes) < self::HEADER_LENGTH) {
            throw new RuntimeException('UEDB5 container is too small.');
        }
        $header = self::decodeHeader(substr($bytes, 0, self::HEADER_LENGTH));
        $manifestLength = (int)$header['manifest_length'];
        if ($manifestLength < 2 || self::HEADER_LENGTH + $manifestLength > strlen($bytes)) {
            throw new RuntimeException('UEDB5 manifest length is invalid.');
        }
        $manifest = self::decodeManifest(substr($bytes, self::HEADER_LENGTH, $manifestLength));
        self::assertManifestContract($manifest, $expectedFileId);

        $payloadStart = self::HEADER_LENGTH + $manifestLength;
        $expectedOffset = 0;
        $verifiedBlocks = 0;
        foreach ((array)$manifest['sections'] as $section => $blocks) {
            foreach ((array)$blocks as $block) {
                self::assertBlockDescriptor($section, $block, $expectedOffset);
                $length = (int)$block['compressed_length'];
                if ($payloadStart + $expectedOffset + $length > strlen($bytes)) {
                    throw new RuntimeException('UEDB5 block bounds are invalid.');
                }
                self::verifyCompressedBlock(substr($bytes, $payloadStart + $expectedOffset, $length), $block, $section);
                $expectedOffset += $length;
                $verifiedBlocks++;
            }
        }
        $expectedSize = $payloadStart + $expectedOffset;
        if ($expectedSize !== strlen($bytes)) {
            throw new RuntimeException('UEDB5 container has unexpected trailing or missing bytes.');
        }
        return [
            'manifest' => $manifest,
            'block_count' => $verifiedBlocks,
            'payload_start' => $payloadStart,
        ];
    }

    /**
     * @return array{manifest:array<string,mixed>,block_count:int,payload_start:int,payload_sha256:string,compressed_size:int}
     */
    public static function verifyFile(
        string $path,
        int $expectedFileId,
        ?string $expectedPayloadSha256 = null
    ): array {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size < self::HEADER_LENGTH) {
            throw new RuntimeException('UEDB5 container is missing or too small: ' . $path);
        }
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Could not open UEDB5 container: ' . $path);
        }
        $hash = hash_init('sha256');
        try {
            $headerBytes = self::readExactly($stream, self::HEADER_LENGTH);
            hash_update($hash, $headerBytes);
            $header = self::decodeHeader($headerBytes);
            $manifestLength = (int)$header['manifest_length'];
            if ($manifestLength < 2 || self::HEADER_LENGTH + $manifestLength > (int)$size) {
                throw new RuntimeException('UEDB5 manifest length is invalid.');
            }
            $manifestBytes = self::readExactly($stream, $manifestLength);
            hash_update($hash, $manifestBytes);
            $manifest = self::decodeManifest($manifestBytes);
            self::assertManifestContract($manifest, $expectedFileId);

            $payloadStart = self::HEADER_LENGTH + $manifestLength;
            $expectedOffset = 0;
            $verifiedBlocks = 0;
            foreach ((array)$manifest['sections'] as $section => $blocks) {
                foreach ((array)$blocks as $block) {
                    self::assertBlockDescriptor($section, $block, $expectedOffset);
                    $compressed = self::readExactly($stream, (int)$block['compressed_length']);
                    hash_update($hash, $compressed);
                    self::verifyCompressedBlock($compressed, $block, $section);
                    $expectedOffset += (int)$block['compressed_length'];
                    $verifiedBlocks++;
                }
            }
            if ($payloadStart + $expectedOffset !== (int)$size) {
                throw new RuntimeException('UEDB5 container has unexpected trailing or missing bytes.');
            }

            $payloadSha256 = hash_final($hash, true);
            if ($expectedPayloadSha256 !== null && !hash_equals($expectedPayloadSha256, $payloadSha256)) {
                throw new RuntimeException('UEDB5 container SHA-256 mismatch.');
            }
            return [
                'manifest' => $manifest,
                'block_count' => $verifiedBlocks,
                'payload_start' => $payloadStart,
                'payload_sha256' => $payloadSha256,
                'compressed_size' => (int)$size,
            ];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param callable(string):void $consumeCompressed
     * @return array{file_id:int,manifest:array<string,mixed>,uncompressed_size:int,block_count:int}
     */
    private static function buildPayload(array $snapshot, int $blockSize, callable $consumeCompressed): array
    {
        $blockSize = max(1, min(2000, $blockSize));
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('The UEDB5 snapshot has no valid file ID.');
        }
        $packageFamily = trim((string)($snapshot['package_family'] ?? ''));
        $sourcePolicy = trim((string)($snapshot['source_policy'] ?? ''));
        if ($packageFamily === '' || $sourcePolicy === '') {
            throw new RuntimeException('UEDB5 requires package_family and source_policy.');
        }
        $sections = self::normalizeSections((array)($snapshot['sections'] ?? []));
        $sectionSchemas = self::normalizeSectionSchemas((array)($snapshot['section_schemas'] ?? []), $sections);

        $manifest = [
            'format' => self::MANIFEST_FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'codec' => 'gzip-blocks',
            'payload_encoding' => self::PAYLOAD_ENCODING,
            'block_size' => $blockSize,
            'package_family' => $packageFamily,
            'source_policy' => $sourcePolicy,
            'file' => [
                'id' => $fileId,
                'game_id' => (int)($file['game_id'] ?? 0),
                'package_name' => (string)($file['package_name'] ?? ''),
                'original_name' => (string)($file['original_name'] ?? ''),
            ],
            'counts' => [],
            'section_schemas' => $sectionSchemas,
            'sections' => [],
        ];
        foreach ($sections as $section => $rows) {
            $manifest['counts'][$section] = count($rows);
            $manifest['sections'][$section] = [];
        }

        $payloadOffset = 0;
        $uncompressedSize = 0;
        $blockCount = 0;
        foreach ($sections as $section => $rows) {
            $rowCount = count($rows);
            for ($rowStart = 0; $rowStart < $rowCount; $rowStart += $blockSize) {
                $chunk = array_slice($rows, $rowStart, $blockSize);
                $json = self::encodeBlock($section, $chunk);
                $compressed = gzencode($json, 6, ZLIB_ENCODING_GZIP);
                if (!is_string($compressed) || $compressed === '') {
                    throw new RuntimeException('Could not compress UEDB5 section ' . $section . '.');
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
            }
        }

        return [
            'file_id' => $fileId,
            'manifest' => $manifest,
            'uncompressed_size' => $uncompressedSize,
            'block_count' => $blockCount,
        ];
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<string,list<array<string,mixed>>>
     */
    private static function normalizeSections(array $sections): array
    {
        $normalized = [];
        foreach ($sections as $section => $rows) {
            $section = trim((string)$section);
            if ($section === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $section) !== 1) {
                throw new RuntimeException('Invalid UEDB5 section name: ' . $section);
            }
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new RuntimeException('UEDB5 section ' . $section . ' must be a row list.');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('UEDB5 section ' . $section . ' contains a non-array row.');
                }
            }
            $normalized[$section] = array_values($rows);
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $schemas
     * @param array<string,list<array<string,mixed>>> $sections
     * @return array<string,string>
     */
    private static function normalizeSectionSchemas(array $schemas, array $sections): array
    {
        $normalized = [];
        foreach ($schemas as $section => $schema) {
            $section = (string)$section;
            if (!array_key_exists($section, $sections)) {
                throw new RuntimeException('UEDB5 schema references unknown section ' . $section . '.');
            }
            $schema = trim((string)$schema);
            if ($schema === '') {
                throw new RuntimeException('UEDB5 section schema names cannot be empty.');
            }
            $normalized[$section] = $schema;
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function encodeBlock(string $section, array $rows): string
    {
        try {
            $json = json_encode(
                ['rows' => $rows],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Could not encode UEDB5 section ' . $section . ': ' . $error->getMessage(), 0, $error);
        }
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode UEDB5 section ' . $section . '.');
        }
        return $json;
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
            throw new RuntimeException('Could not encode UEDB5 manifest: ' . $error->getMessage(), 0, $error);
        }
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode UEDB5 manifest.');
        }
        return $json;
    }

    /** @return array<string,mixed> */
    private static function decodeManifest(string $json): array
    {
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('UEDB5 manifest is invalid JSON.', 0, $error);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('UEDB5 manifest is invalid.');
        }
        return $manifest;
    }

    /** @return array{magic:string,version:int,codec:int,manifest_length:int,reserved:int} */
    private static function decodeHeader(string $bytes): array
    {
        $header = unpack('a8magic/vversion/vcodec/Vmanifest_length/Vreserved', $bytes);
        if (!is_array($header) || (string)($header['magic'] ?? '') !== self::MAGIC) {
            throw new RuntimeException('UEDB5 container magic is invalid.');
        }
        if ((int)$header['version'] !== self::FORMAT_VERSION || (int)$header['codec'] !== self::CODEC_BLOCK_GZIP) {
            throw new RuntimeException('UEDB5 container version or codec is unsupported.');
        }
        if ((int)($header['reserved'] ?? -1) !== 0) {
            throw new RuntimeException('UEDB5 container reserved header field is invalid.');
        }
        return [
            'magic' => (string)$header['magic'],
            'version' => (int)$header['version'],
            'codec' => (int)$header['codec'],
            'manifest_length' => (int)$header['manifest_length'],
            'reserved' => (int)$header['reserved'],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private static function assertManifestContract(array $manifest, int $expectedFileId): void
    {
        if ((string)($manifest['format'] ?? '') !== self::MANIFEST_FORMAT
            || (int)($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION
            || (string)($manifest['codec'] ?? '') !== 'gzip-blocks'
            || (string)($manifest['payload_encoding'] ?? '') !== self::PAYLOAD_ENCODING) {
            throw new RuntimeException('UEDB5 manifest format contract is invalid.');
        }
        if ((int)($manifest['file']['id'] ?? 0) !== $expectedFileId) {
            throw new RuntimeException('UEDB5 manifest identity mismatch.');
        }
        if (trim((string)($manifest['package_family'] ?? '')) === ''
            || trim((string)($manifest['source_policy'] ?? '')) === '') {
            throw new RuntimeException('UEDB5 manifest package family/source policy is missing.');
        }
        $sections = $manifest['sections'] ?? null;
        $counts = $manifest['counts'] ?? null;
        if (!is_array($sections) || !is_array($counts)) {
            throw new RuntimeException('UEDB5 manifest sections/counts are invalid.');
        }
        foreach ($sections as $section => $blocks) {
            if (!is_string($section) || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $section) !== 1 || !is_array($blocks)) {
                throw new RuntimeException('UEDB5 manifest contains an invalid section.');
            }
            $rowCount = 0;
            $expectedRowStart = 0;
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    throw new RuntimeException('UEDB5 manifest contains an invalid block descriptor.');
                }
                $blockRowStart = (int)($block['row_start'] ?? -1);
                $blockRowCount = (int)($block['row_count'] ?? -1);
                if ($blockRowStart !== $expectedRowStart || $blockRowCount < 0) {
                    throw new RuntimeException('UEDB5 manifest row ordering is invalid for ' . $section . '.');
                }
                $expectedRowStart += $blockRowCount;
                $rowCount += $blockRowCount;
            }
            if (!array_key_exists($section, $counts) || (int)$counts[$section] !== $rowCount) {
                throw new RuntimeException('UEDB5 manifest section count mismatch for ' . $section . '.');
            }
        }
        if (count($counts) !== count($sections)) {
            throw new RuntimeException('UEDB5 manifest contains counts for unknown sections.');
        }
        $schemas = $manifest['section_schemas'] ?? [];
        if (!is_array($schemas)) {
            throw new RuntimeException('UEDB5 manifest section schemas are invalid.');
        }
        foreach ($schemas as $section => $schema) {
            if (!array_key_exists((string)$section, $sections) || trim((string)$schema) === '') {
                throw new RuntimeException('UEDB5 manifest section schema reference is invalid.');
            }
        }
    }

    /** @param array<string,mixed> $block */
    private static function assertBlockDescriptor(string $section, array $block, int $expectedOffset): void
    {
        $offset = (int)($block['offset'] ?? -1);
        $length = (int)($block['compressed_length'] ?? 0);
        $rowStart = (int)($block['row_start'] ?? -1);
        $rowCount = (int)($block['row_count'] ?? -1);
        $uncompressedLength = (int)($block['uncompressed_length'] ?? 0);
        $sha256 = (string)($block['sha256'] ?? '');
        if ($offset !== $expectedOffset || $length < 1 || $rowStart < 0 || $rowCount < 0
            || $uncompressedLength < 1 || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('UEDB5 block descriptor is invalid for section ' . $section . '.');
        }
    }

    /** @param array<string,mixed> $block */
    private static function verifyCompressedBlock(string $compressed, array $block, string $section): void
    {
        if (!hash_equals((string)$block['sha256'], hash('sha256', $compressed))) {
            throw new RuntimeException('UEDB5 block checksum mismatch for section ' . $section . '.');
        }
        $json = gzdecode($compressed);
        if (!is_string($json) || strlen($json) !== (int)$block['uncompressed_length']) {
            throw new RuntimeException('UEDB5 block decompression failed for section ' . $section . '.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('UEDB5 block is invalid JSON for section ' . $section . '.', 0, $error);
        }
        if (!is_array($decoded) || !is_array($decoded['rows'] ?? null)
            || count($decoded['rows']) !== (int)$block['row_count']) {
            throw new RuntimeException('UEDB5 block row count mismatch for section ' . $section . '.');
        }
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
            throw new RuntimeException('The PHP zlib extension is required for UEDB5 metadata.');
        }
    }

    /** @param resource $stream */
    private static function readExactly($stream, int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Invalid UEDB5 read length.');
        }
        $buffer = '';
        while (strlen($buffer) < $length && !feof($stream)) {
            $chunk = fread($stream, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Could not read UEDB5 container.');
            }
            $buffer .= $chunk;
        }
        if (strlen($buffer) !== $length) {
            throw new RuntimeException('UEDB5 container ended unexpectedly.');
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
                throw new RuntimeException('Could not completely write UEDB5 container.');
            }
            $offset += $written;
        }
    }
}
