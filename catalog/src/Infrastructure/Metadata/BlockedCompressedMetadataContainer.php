<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use RuntimeException;

/** Builds the version-2 random-access, block-compressed metadata container. */
final class BlockedCompressedMetadataContainer
{
    public const FORMAT_VERSION = 2;
    public const CODEC_BLOCK_GZIP = 2;
    public const DEFAULT_BLOCK_SIZE = 500;

    private const MAGIC = "UEDBM2\0\0";
    private const HEADER_LENGTH = 20;

    /**
     * @param array<string,mixed> $snapshot
     * @return array{bytes:string,uncompressed_size:int,block_count:int,manifest:array<string,mixed>}
     */
    public static function build(array $snapshot, int $blockSize = self::DEFAULT_BLOCK_SIZE): array
    {
        if (!function_exists('gzencode') || !function_exists('gzdecode')) {
            throw new RuntimeException('The PHP zlib extension is required for block-compressed metadata.');
        }
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

        $payloadBlocks = [];
        $payloadOffset = 0;
        $uncompressedSize = 0;
        $paths = (array)($snapshot['paths'] ?? []);

        foreach (['names', 'imports', 'exports', 'dependencies'] as $section) {
            $rows = array_values((array)($snapshot[$section] ?? []));
            $rowStart = 0;
            foreach (array_chunk($rows, $blockSize) as $chunk) {
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

                $manifest['sections'][$section][] = [
                    'row_start' => $rowStart,
                    'row_count' => count($chunk),
                    'offset' => $payloadOffset,
                    'compressed_length' => strlen($compressed),
                    'uncompressed_length' => strlen($json),
                    'sha256' => hash('sha256', $compressed),
                ];
                $payloadBlocks[] = $compressed;
                $payloadOffset += strlen($compressed);
                $uncompressedSize += strlen($json);
                $rowStart += count($chunk);
            }
        }

        try {
            $manifestJson = json_encode(
                $manifest,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Could not encode metadata manifest: ' . $error->getMessage(), 0, $error);
        }
        if (!is_string($manifestJson)) {
            throw new RuntimeException('Could not encode metadata manifest.');
        }

        $header = pack(
            'a8vvVV',
            self::MAGIC,
            self::FORMAT_VERSION,
            self::CODEC_BLOCK_GZIP,
            strlen($manifestJson),
            0
        );
        $bytes = $header . $manifestJson . implode('', $payloadBlocks);
        self::verifyBytes($bytes, $fileId);

        return [
            'bytes' => $bytes,
            'uncompressed_size' => $uncompressedSize + strlen($manifestJson),
            'block_count' => count($payloadBlocks),
            'manifest' => $manifest,
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
                $verifiedBlocks++;
            }
        }

        return ['manifest' => $manifest, 'block_count' => $verifiedBlocks];
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
}
