<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogRedirectArchiveStream` for catalog redirect archive stream.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveValidationException;

/**
 * Memory-bounded decoder for Epic's UE2 .uz2 redirect format. The legacy helper
 * decodes into one PHP string, which is unsuitable for very large texture and
 * map packages. This implementation reads and writes one 32 KiB record at a
 * time and exposes progress/cancellation checkpoints to the job worker.
 */
final class CatalogRedirectArchiveStream
{
    /**
     * @param callable(array<string,int|string|bool>):void|null $progress
     * @return array{path:string,filename:string,bytes:int,compressed_bytes:int,source_extension:string,decoder:string,chunks:int,expected_bytes:int,md5:string,sha1:string,is_unreal_package:bool}
     */
    public static function decompressUz2(
        string $sourcePath,
        string $sourceName,
        int $maxOutputBytes = 0,
        ?callable $progress = null,
        bool $requirePackageTag = true
    ): array {
        if (\catalog_redirect_archive_extension($sourceName) !== 'uz2') {
            throw new \RuntimeException('Streaming redirect decoder requires a .uz2 file.');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('Redirect compressed source file is missing.');
        }

        $compressedBytes = filesize($sourcePath);
        if ($compressedBytes === false) {
            throw new \RuntimeException('Could not read redirect compressed file: ' . basename($sourceName));
        }
        if ($compressedBytes < 9) {
            $missingBytes = 9 - (int)$compressedBytes;
            throw new CatalogRedirectArchiveValidationException(
                'UZ2 file is incomplete/cut by ' . $missingBytes . ' bytes: ' . basename($sourceName)
                . ' (actual_file_size=' . (int)$compressedBytes
                . ', minimum_file_size=9).',
                'uz2.incomplete_file',
                [
                    'missing_bytes' => $missingBytes,
                    'actual_file_size' => (int)$compressedBytes,
                    'minimum_file_size' => 9,
                ]
            );
        }
        $limit = \catalog_redirect_archive_output_limit($maxOutputBytes);
        $startedAt = microtime(true);
        $lastProgressAt = $startedAt;

        $input = fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Could not open redirect compressed file: ' . basename($sourceName));
        }

        $temporary = tempnam(sys_get_temp_dir(), 'ue_redirect_');
        if ($temporary === false) {
            fclose($input);
            throw new \RuntimeException('Could not allocate decompressed redirect package.');
        }
        $output = fopen($temporary, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            @unlink($temporary);
            throw new \RuntimeException('Could not create decompressed redirect package.');
        }

        $readBytes = 0;
        $writtenBytes = 0;
        $chunks = 0;
        $isUnrealPackage = false;
        $decoder = '';
        $md5Context = hash_init('md5');
        $sha1Context = hash_init('sha1');

        try {
            while ($readBytes < $compressedBytes) {
                $recordNumber = $chunks + 1;
                $recordOffset = $readBytes;
                $availableHeaderBytes = $compressedBytes - $readBytes;
                if ($availableHeaderBytes < 8) {
                    $missingBytes = 8 - $availableHeaderBytes;
                    throw new CatalogRedirectArchiveValidationException(
                        'UZ2 file is incomplete/cut by ' . $missingBytes . ' bytes: ' . basename($sourceName)
                        . ' (record=' . $recordNumber
                        . ', record_offset=' . $recordOffset
                        . ', required_header_bytes=8'
                        . ', available_header_bytes=' . $availableHeaderBytes
                        . ', actual_file_size=' . $compressedBytes . ').',
                        'uz2.incomplete_record_header',
                        [
                            'record' => $recordNumber,
                            'record_offset' => $recordOffset,
                            'missing_bytes' => $missingBytes,
                            'required_header_bytes' => 8,
                            'available_header_bytes' => $availableHeaderBytes,
                            'actual_file_size' => (int)$compressedBytes,
                        ]
                    );
                }
                $header = self::readExact($input, 8);
                $readBytes += 8;
                $sizes = unpack('Vcompressed/Vuncompressed', $header);
                $compressed = (int)($sizes['compressed'] ?? 0);
                $uncompressed = (int)($sizes['uncompressed'] ?? 0);
                $availablePayloadBytes = $compressedBytes - $readBytes;

                if (
                    $compressed <= 0
                    || $compressed > \CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES
                    || $uncompressed <= 0
                    || $uncompressed > \CATALOG_EPIC_UZ2_BLOCK_BYTES
                ) {
                    throw new CatalogRedirectArchiveValidationException(
                        'Invalid UZ2 format: ' . basename($sourceName)
                        . ' (record=' . $recordNumber
                        . ', record_offset=' . $recordOffset
                        . ', compressed_size=' . $compressed
                        . ', uncompressed_size=' . $uncompressed
                        . ', max_compressed_size=' . \CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES
                        . ', max_uncompressed_size=' . \CATALOG_EPIC_UZ2_BLOCK_BYTES . ').',
                        'uz2.invalid_record_sizes',
                        [
                            'record' => $recordNumber,
                            'record_offset' => $recordOffset,
                            'compressed_size' => $compressed,
                            'uncompressed_size' => $uncompressed,
                            'max_compressed_size' => \CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES,
                            'max_uncompressed_size' => \CATALOG_EPIC_UZ2_BLOCK_BYTES,
                        ]
                    );
                }
                if ($compressed > $availablePayloadBytes) {
                    $missingBytes = $compressed - $availablePayloadBytes;
                    $requiredFileSize = $readBytes + $compressed;
                    throw new CatalogRedirectArchiveValidationException(
                        'UZ2 file is incomplete/cut by ' . $missingBytes . ' bytes: ' . basename($sourceName)
                        . ' (record=' . $recordNumber
                        . ', record_offset=' . $recordOffset
                        . ', payload_offset=' . $readBytes
                        . ', compressed_size=' . $compressed
                        . ', uncompressed_size=' . $uncompressed
                        . ', available_bytes=' . $availablePayloadBytes
                        . ', actual_file_size=' . $compressedBytes
                        . ', required_file_size=' . $requiredFileSize . ').',
                        'uz2.incomplete_record_payload',
                        [
                            'record' => $recordNumber,
                            'record_offset' => $recordOffset,
                            'payload_offset' => $readBytes,
                            'compressed_size' => $compressed,
                            'uncompressed_size' => $uncompressed,
                            'available_bytes' => $availablePayloadBytes,
                            'missing_bytes' => $missingBytes,
                            'actual_file_size' => (int)$compressedBytes,
                            'required_file_size' => $requiredFileSize,
                        ]
                    );
                }
                if ($uncompressed > $limit - $writtenBytes) {
                    $requiredOutputBytes = $writtenBytes + $uncompressed;
                    throw new CatalogRedirectArchiveValidationException(
                        'Cannot decompress UZ2 because output exceeds the configured limit: ' . basename($sourceName)
                        . ' (record=' . $recordNumber
                        . ', output_bytes=' . $writtenBytes
                        . ', uncompressed_size=' . $uncompressed
                        . ', required_output_size=' . $requiredOutputBytes
                        . ', output_limit=' . $limit . ').',
                        'uz2.output_limit_exceeded',
                        [
                            'record' => $recordNumber,
                            'output_bytes' => $writtenBytes,
                            'uncompressed_size' => $uncompressed,
                            'required_output_size' => $requiredOutputBytes,
                            'output_limit' => $limit,
                        ]
                    );
                }

                $payload = self::readExact($input, $compressed);
                $readBytes += $compressed;

                $decoded = self::decodeRecord(
                    $payload,
                    $limit - $writtenBytes,
                    $uncompressed
                );
                if ($decoded === null) {
                    $payloadHeadHex = bin2hex(substr($payload, 0, 8));
                    throw new CatalogRedirectArchiveValidationException(
                        'Cannot decompress UZ2 record ' . $recordNumber . ': ' . basename($sourceName)
                        . ' (record_offset=' . $recordOffset
                        . ', payload_offset=' . ($recordOffset + 8)
                        . ', compressed_size=' . $compressed
                        . ', uncompressed_size=' . $uncompressed
                        . ', payload_head_hex=' . $payloadHeadHex . ').',
                        'uz2.decompression_failed',
                        [
                            'record' => $recordNumber,
                            'record_offset' => $recordOffset,
                            'payload_offset' => $recordOffset + 8,
                            'compressed_size' => $compressed,
                            'uncompressed_size' => $uncompressed,
                            'payload_head_hex' => $payloadHeadHex,
                        ]
                    );
                }

                $block = $decoded['data'];
                $decoder = (string)$decoded['decoder'];
                if ($chunks === 0) {
                    $isUnrealPackage = \catalog_redirect_archive_has_package_tag(substr($block, 0, 4));
                    if ($requirePackageTag && !$isUnrealPackage) {
                        $magicBytes = substr($block, 0, 4);
                        $actualMagicHex = strtoupper(bin2hex($magicBytes));
                        $actualMagicText = self::printableBytes($magicBytes);
                        throw new CatalogRedirectArchiveValidationException(
                            'Magic not found: ' . basename($sourceName)
                            . ' (record=1'
                            . ', redirect_format=UZ2'
                            . ', actual_magic_hex=' . ($actualMagicHex !== '' ? $actualMagicHex : 'empty')
                            . ', actual_magic_text=' . ($actualMagicText !== '' ? $actualMagicText : 'empty')
                            . ', expected_magic_hex=C1832A9E|9E2A83C1|C2832A9E).',
                            'uz2.magic_not_found',
                            [
                                'record' => 1,
                                'redirect_format' => 'UZ2',
                                'actual_magic_hex' => $actualMagicHex !== '' ? $actualMagicHex : 'empty',
                                'actual_magic_text' => $actualMagicText !== '' ? $actualMagicText : 'empty',
                                'expected_magic_hex' => 'C1832A9E|9E2A83C1|C2832A9E',
                            ]
                        );
                    }
                }
                hash_update($md5Context, $block);
                hash_update($sha1Context, $block);
                if (self::writeAll($output, $block) !== strlen($block)) {
                    throw new \RuntimeException('Could not write decompressed redirect package.');
                }
                $writtenBytes += strlen($block);
                $chunks++;

                $now = microtime(true);
                if ($progress !== null && ($chunks === 1 || ($chunks % 32) === 0 || $readBytes >= $compressedBytes || ($now - $lastProgressAt) >= 2.0)) {
                    $progress([
                        'compressed_done' => $readBytes,
                        'compressed_total' => (int)$compressedBytes,
                        'output_bytes' => $writtenBytes,
                        'chunks' => $chunks,
                        'percent' => (int)floor(($readBytes * 100) / max(1, (int)$compressedBytes)),
                        'is_unreal_package' => $isUnrealPackage,
                        'elapsed_seconds' => (int)floor($now - $startedAt),
                        'message' => 'Decompressing and hashing ' . basename($sourceName) . ': block ' . $chunks,
                    ]);
                    $lastProgressAt = $now;
                }
            }

            if ($chunks < 1 || $readBytes !== $compressedBytes || $writtenBytes < 1 || $writtenBytes > $limit) {
                throw new \RuntimeException(
                    'Incomplete Epic UZ2 record stream in ' . basename($sourceName)
                    . ' (records=' . $chunks
                    . ', compressed_read=' . $readBytes . '/' . $compressedBytes
                    . ', output=' . $writtenBytes . ').'
                );
            }
        } catch (\Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($temporary);
            throw $error;
        }

        fclose($input);
        if (!fflush($output)) {
            fclose($output);
            @unlink($temporary);
            throw new \RuntimeException('Could not finish decompressed redirect package.');
        }
        fclose($output);

        return [
            'path' => $temporary,
            'filename' => \catalog_redirect_archive_output_name($sourceName),
            'bytes' => $writtenBytes,
            'compressed_bytes' => (int)$compressedBytes,
            'source_extension' => 'uz2',
            'decoder' => 'epic-uz2-' . $decoder . '-stream',
            'chunks' => $chunks,
            'expected_bytes' => $writtenBytes,
            'md5' => hash_final($md5Context),
            'sha1' => hash_final($sha1Context),
            'is_unreal_package' => $isUnrealPackage,
        ];
    }

    /**
     * Epic UE2 calls zlib uncompress() for every record. PHP's gzuncompress()
     * is the direct zlib-wrapper equivalent. The inflate API is retained only
     * as a zlib-wrapper implementation fallback; raw deflate and gzip are not
     * valid UZ2 record formats.
     *
     * @return array{data:string,decoder:string}|null
     */
    private static function decodeRecord(string $payload, int $limit, int $expectedBytes): ?array
    {
        if ($payload === '' || $expectedBytes <= 0 || $expectedBytes > $limit) {
            return null;
        }

        if (function_exists('gzuncompress')) {
            try {
                $decoded = @gzuncompress($payload, $expectedBytes);
            } catch (\Throwable) {
                $decoded = false;
            }
            if (is_string($decoded) && strlen($decoded) === $expectedBytes) {
                return ['data' => $decoded, 'decoder' => 'zlib-uncompress'];
            }
        }

        $strict = \catalog_redirect_archive_inflate_epic_zlib($payload, $limit, $expectedBytes);
        if ($strict !== null) {
            return ['data' => (string)$strict['data'], 'decoder' => 'zlib-inflate'];
        }

        return null;
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $part = fread($stream, $length - strlen($data));
            if (!is_string($part) || $part === '') {
                break;
            }
            $data .= $part;
        }
        if (strlen($data) !== $length) {
            throw new \RuntimeException('Unexpected end of redirect compressed file.');
        }
        return $data;
    }

    private static function printableBytes(string $bytes): string
    {
        return preg_replace('/[^\x20-\x7E]/', '.', $bytes) ?? '';
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $data): int
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $count = fwrite($stream, substr($data, $written));
            if ($count === false || $count < 1) {
                break;
            }
            $written += $count;
        }
        return $written;
    }
}
