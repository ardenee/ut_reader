<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

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
        if ($compressedBytes === false || $compressedBytes < 9) {
            throw new \RuntimeException('Could not read redirect compressed file: ' . basename($sourceName));
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
        $decoders = [];
        $md5Context = hash_init('md5');
        $sha1Context = hash_init('sha1');

        try {
            while ($readBytes < $compressedBytes) {
                $recordNumber = $chunks + 1;
                $recordOffset = $readBytes;
                $header = self::readExact($input, 8);
                $readBytes += 8;
                $sizes = unpack('Vcompressed/Vuncompressed', $header);
                $compressed = (int)($sizes['compressed'] ?? 0);
                $uncompressed = (int)($sizes['uncompressed'] ?? 0);

                if (
                    $compressed <= 0
                    || $compressed > \CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES
                    || $uncompressed <= 0
                    || $uncompressed > \CATALOG_EPIC_UZ2_BLOCK_BYTES
                    || $compressed > $compressedBytes - $readBytes
                    || $uncompressed > $limit - $writtenBytes
                ) {
                    throw new \RuntimeException(
                        'Invalid Epic UZ2 record ' . $recordNumber
                        . ' (compressed=' . $compressed
                        . ', uncompressed=' . $uncompressed
                        . ', offset=' . $recordOffset
                        . ', remaining=' . max(0, $compressedBytes - $readBytes)
                        . ') in ' . basename($sourceName) . '.'
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
                    throw new \RuntimeException(
                        'Epic UZ2 decompression failed at record ' . $recordNumber
                        . ' (compressed=' . $compressed
                        . ', uncompressed=' . $uncompressed
                        . ', offset=' . $recordOffset
                        . ', payload=' . bin2hex(substr($payload, 0, 8))
                        . ') in ' . basename($sourceName)
                        . '; available decoders: ' . self::availableDecoders() . '.'
                    );
                }

                $block = $decoded['data'];
                $decoders[$decoded['decoder']] = true;
                if ($chunks === 0) {
                    $isUnrealPackage = \catalog_redirect_archive_has_package_tag(substr($block, 0, 4));
                    if ($requirePackageTag && !$isUnrealPackage) {
                        throw new \RuntimeException(
                            'Epic UZ2 record stream decoded, but its output does not begin with an Unreal package tag: '
                            . basename($sourceName) . '.'
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
            'decoder' => 'epic-uz2-' . implode('+', array_keys($decoders)) . '-stream',
            'chunks' => $chunks,
            'expected_bytes' => $writtenBytes,
            'md5' => hash_final($md5Context),
            'sha1' => hash_final($sha1Context),
            'is_unreal_package' => $isUnrealPackage,
        ];
    }

    /**
     * Try the strict Epic zlib path first, then PHP's compatible zlib/gzip/raw
     * helpers. Every accepted fallback must still produce the exact record size
     * declared by the archive.
     *
     * @return array{data:string,decoder:string}|null
     */
    private static function decodeRecord(string $payload, int $limit, int $expectedBytes): ?array
    {
        $strict = \catalog_redirect_archive_inflate_epic_zlib($payload, $limit, $expectedBytes);
        if ($strict !== null) {
            return ['data' => (string)$strict['data'], 'decoder' => 'zlib-strict'];
        }

        foreach (['zlib_decode', 'gzuncompress', 'gzinflate', 'gzdecode'] as $function) {
            if (!function_exists($function)) {
                continue;
            }

            try {
                $decoded = match ($function) {
                    'zlib_decode', 'gzuncompress', 'gzinflate' => @$function($payload, $expectedBytes),
                    default => @$function($payload),
                };
            } catch (\Throwable) {
                $decoded = false;
            }

            if (
                is_string($decoded)
                && strlen($decoded) === $expectedBytes
                && strlen($decoded) <= $limit
            ) {
                return ['data' => $decoded, 'decoder' => $function];
            }
        }

        return null;
    }

    private static function availableDecoders(): string
    {
        $available = [];
        foreach (['inflate_init', 'inflate_add', 'zlib_decode', 'gzuncompress', 'gzinflate', 'gzdecode'] as $function) {
            if (function_exists($function)) {
                $available[] = $function;
            }
        }
        return $available === [] ? 'none' : implode(', ', $available);
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
