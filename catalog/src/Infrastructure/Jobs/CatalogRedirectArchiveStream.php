<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

/**
 * Memory-bounded decoder for Epic's UE2 .uz2 format. Each record contains a
 * little-endian compressed size, uncompressed size, and then either an exact
 * zlib stream or a verbatim block when both sizes are equal. The latter is
 * used for data that does not benefit from compression, such as some already
 * compressed texture/audio blocks.
 */
final class CatalogRedirectArchiveStream
{
    /**
     * @param callable(array<string,int|string|bool>):void|null $progress
     * @return array{path:string,filename:string,bytes:int,compressed_bytes:int,source_extension:string,decoder:string,chunks:int,expected_bytes:int,is_unreal_package:bool}
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
            throw new \RuntimeException('Epic UZ2 file is too small to contain a complete record: ' . basename($sourceName));
        }
        $limit = \catalog_redirect_archive_output_limit($maxOutputBytes);
        $input = fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException('Could not open redirect compressed file: ' . basename($sourceName));
        }

        // Keep the decoded working file beside durable staging. Synology DSM can
        // prohibit rename() from /volume1/@tmp into a web shared folder even when
        // both paths are on the same volume. A storage-local temporary file can be
        // atomically moved into the verified catalog without crossing that boundary.
        $temporary = tempnam(dirname($sourcePath), '.ue_redirect_');
        if ($temporary === false) {
            fclose($input);
            throw new \RuntimeException('Could not allocate decompressed redirect package in catalog staging.');
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
        $encodings = [];

        try {
            while ($readBytes < $compressedBytes) {
                $recordOffset = $readBytes;
                $recordNumber = $chunks + 1;
                if ($compressedBytes - $readBytes < 8) {
                    throw new \RuntimeException(
                        'Epic UZ2 record ' . $recordNumber . ' has a truncated 8-byte header at offset ' . $recordOffset
                        . ' in ' . basename($sourceName) . '.'
                    );
                }

                $header = self::readExact($input, 8);
                $readBytes += 8;
                $sizes = unpack('Vcompressed/Vuncompressed', $header);
                $compressed = (int)($sizes['compressed'] ?? 0);
                $uncompressed = (int)($sizes['uncompressed'] ?? 0);

                if ($compressed <= 0 || $compressed > \CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES) {
                    throw new \RuntimeException(
                        'Epic UZ2 record ' . $recordNumber . ' has invalid compressed size ' . $compressed
                        . ' at offset ' . $recordOffset . ' (maximum ' . \CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES . ') in '
                        . basename($sourceName) . '.'
                    );
                }
                if ($uncompressed <= 0 || $uncompressed > \CATALOG_EPIC_UZ2_BLOCK_BYTES) {
                    throw new \RuntimeException(
                        'Epic UZ2 record ' . $recordNumber . ' has invalid uncompressed size ' . $uncompressed
                        . ' at offset ' . $recordOffset . ' (maximum ' . \CATALOG_EPIC_UZ2_BLOCK_BYTES . ') in '
                        . basename($sourceName) . '.'
                    );
                }
                if ($compressed > $compressedBytes - $readBytes) {
                    throw new \RuntimeException(
                        'Epic UZ2 record ' . $recordNumber . ' declares ' . $compressed
                        . ' compressed bytes but only ' . ($compressedBytes - $readBytes) . ' remain in '
                        . basename($sourceName) . '.'
                    );
                }
                if ($uncompressed > $limit - $writtenBytes) {
                    throw new \RuntimeException(
                        'Epic UZ2 output exceeds the configured redirect limit at record ' . $recordNumber
                        . ' after ' . $writtenBytes . ' bytes in ' . basename($sourceName) . '.'
                    );
                }

                $payload = self::readExact($input, $compressed);
                $readBytes += $compressed;

                if ($compressed === $uncompressed) {
                    // Epic may store a block verbatim when compression would not
                    // reduce it. Equal record sizes identify this case exactly.
                    $block = $payload;
                    $encoding = 'stored';
                } else {
                    $decoded = \catalog_redirect_archive_inflate_epic_zlib(
                        $payload,
                        $limit - $writtenBytes,
                        $uncompressed
                    );
                    if ($decoded === null) {
                        throw new \RuntimeException(
                            'Epic UZ2 zlib data failed exact validation at record ' . $recordNumber
                            . ' (compressed=' . $compressed . ', uncompressed=' . $uncompressed
                            . ', offset=' . $recordOffset . ') in ' . basename($sourceName) . '.'
                        );
                    }
                    $block = (string)$decoded['data'];
                    $encoding = 'zlib';
                }

                if (strlen($block) !== $uncompressed) {
                    throw new \RuntimeException(
                        'Epic UZ2 record ' . $recordNumber . ' produced ' . strlen($block)
                        . ' bytes instead of ' . $uncompressed . ' in ' . basename($sourceName) . '.'
                    );
                }
                $encodings[$encoding] = true;

                if ($chunks === 0) {
                    $isUnrealPackage = \catalog_redirect_archive_has_package_tag(substr($block, 0, 4));
                    if ($requirePackageTag && !$isUnrealPackage) {
                        throw new \RuntimeException(
                            'Epic UZ2 decoded correctly but the output does not begin with Unreal package magic: '
                            . basename($sourceName) . '.'
                        );
                    }
                }
                if (self::writeAll($output, $block) !== strlen($block)) {
                    throw new \RuntimeException('Could not write decompressed redirect package.');
                }
                $writtenBytes += strlen($block);
                $chunks++;

                if ($progress !== null && ($chunks === 1 || ($chunks % 32) === 0 || $readBytes >= $compressedBytes)) {
                    $progress([
                        'compressed_done' => $readBytes,
                        'compressed_total' => (int)$compressedBytes,
                        'output_bytes' => $writtenBytes,
                        'chunks' => $chunks,
                        'percent' => (int)floor(($readBytes * 100) / max(1, (int)$compressedBytes)),
                        'is_unreal_package' => $isUnrealPackage,
                        'message' => 'Decompressing ' . basename($sourceName) . ': block ' . $chunks,
                    ]);
                }
            }

            if ($chunks < 1) {
                throw new \RuntimeException('Epic UZ2 archive contains no records: ' . basename($sourceName) . '.');
            }
            if ($readBytes !== $compressedBytes) {
                throw new \RuntimeException(
                    'Epic UZ2 decoder stopped at byte ' . $readBytes . ' of ' . $compressedBytes . ' in '
                    . basename($sourceName) . '.'
                );
            }
            if ($writtenBytes < 1 || $writtenBytes > $limit) {
                throw new \RuntimeException(
                    'Epic UZ2 produced invalid output size ' . $writtenBytes . ' in ' . basename($sourceName) . '.'
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
            'decoder' => 'epic-uz2-' . implode('+', array_keys($encodings)) . '-stream',
            'chunks' => $chunks,
            'expected_bytes' => $writtenBytes,
            'is_unreal_package' => $isUnrealPackage,
        ];
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
