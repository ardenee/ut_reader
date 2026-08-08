<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Writes and validates Unreal Tournament 4 unencrypted version-3 PAK exports.
 * Why: PAK byte layout, index encoding and integrity validation are archive-format concerns, not general package policy.
 * Role: Downloads infrastructure writer preserving the existing version-3 uncompressed PAK format exactly.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use RuntimeException;
use Throwable;

final class CatalogUt4PakWriter
{
    private const VERSION = 3;
    private const MAGIC = 0x5A6F12E1;

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $options
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function write(
        string $outputPath,
        array $plan,
        array $options,
        array $settings
    ): array {
        $inferredPaths = array_filter(
            $plan['files'],
            static fn(array $file): bool => !empty($file['install_path_inferred'])
        );
        if ($inferredPaths) {
            throw new RuntimeException(
                'UT4 PAK export requires recorded game-relative source paths for every asset. '
                . 'Re-scan the source folders before exporting.'
            );
        }

        $mountPoint = CatalogPackageInstallPathResolver::normalizeMountPoint(
            (string)$settings['ut4_mount_point']
        );
        $handle = fopen($outputPath, 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Could not create the PAK file.');
        }

        $entries = [];
        try {
            foreach ($plan['files'] as $file) {
                $path = CatalogPackageInstallPathResolver::normalizeRelativePath(
                    (string)$file['install_path']
                );
                if (str_starts_with(strtolower($path), 'unrealtournament/content/')) {
                    $path = substr($path, strlen('UnrealTournament/Content/'));
                }
                if ($path === '') {
                    throw new RuntimeException(
                        'Could not determine a PAK path for ' . $file['original_name']
                    );
                }

                $entryOffset = ftell($handle);
                if ($entryOffset === false) {
                    throw new RuntimeException('Could not determine the PAK entry offset.');
                }
                $size = (int)$file['file_size'];
                $sha1Binary = hash_file('sha1', (string)$file['storage_path'], true);
                if ($sha1Binary === false) {
                    throw new RuntimeException(
                        'Could not hash ' . $file['original_name'] . ' for the PAK.'
                    );
                }
                $entryHeader = self::entryBytes(
                    (int)$entryOffset,
                    $size,
                    $sha1Binary,
                    self::VERSION
                );
                if (fwrite($handle, $entryHeader) !== strlen($entryHeader)) {
                    throw new RuntimeException('Could not write a PAK entry header.');
                }
                $input = fopen((string)$file['storage_path'], 'rb');
                if ($input === false) {
                    throw new RuntimeException(
                        'Could not open ' . $file['original_name'] . ' for packaging.'
                    );
                }
                try {
                    if (stream_copy_to_stream($input, $handle) === false) {
                        throw new RuntimeException(
                            'Could not copy ' . $file['original_name'] . ' into the PAK.'
                        );
                    }
                } finally {
                    fclose($input);
                }
                $entries[] = [
                    'filename' => $path,
                    'offset' => (int)$entryOffset,
                    'size' => $size,
                    'sha1' => $sha1Binary,
                ];
            }

            $manifest = CatalogGeneratedPackageDescriptor::json(
                CatalogGeneratedPackageDescriptor::metadata($plan, $options)
            );
            $manifestPath = 'UnrealDB/'
                . CatalogGeneratedPackageDescriptor::safeComponent((string)$options['name'])
                . '/UnrealDB-Mod.json';
            $manifestOffset = ftell($handle);
            if ($manifestOffset === false) {
                throw new RuntimeException('Could not determine the PAK manifest offset.');
            }
            $manifestHash = hash('sha1', $manifest, true);
            $manifestHeader = self::entryBytes(
                (int)$manifestOffset,
                strlen($manifest),
                $manifestHash,
                self::VERSION
            );
            if (fwrite($handle, $manifestHeader) !== strlen($manifestHeader)
                || fwrite($handle, $manifest) !== strlen($manifest)) {
                throw new RuntimeException('Could not write the PAK manifest.');
            }
            $entries[] = [
                'filename' => $manifestPath,
                'offset' => (int)$manifestOffset,
                'size' => strlen($manifest),
                'sha1' => $manifestHash,
            ];

            usort(
                $entries,
                static fn(array $left, array $right): int => strcmp(
                    (string)$left['filename'],
                    (string)$right['filename']
                )
            );
            $indexOffset = ftell($handle);
            if ($indexOffset === false) {
                throw new RuntimeException('Could not determine the PAK index offset.');
            }
            $index = self::ue4String($mountPoint) . pack('V', count($entries));
            foreach ($entries as $entry) {
                $index .= self::ue4String((string)$entry['filename']);
                $index .= self::entryBytes(
                    (int)$entry['offset'],
                    (int)$entry['size'],
                    (string)$entry['sha1'],
                    self::VERSION
                );
            }
            if (fwrite($handle, $index) !== strlen($index)) {
                throw new RuntimeException('Could not write the PAK index.');
            }
            $indexSize = strlen($index);
            $indexHash = hash('sha1', $index, true);
            $footer = pack('V', self::MAGIC)
                . pack('V', self::VERSION)
                . self::packI64((int)$indexOffset)
                . self::packI64($indexSize)
                . $indexHash;
            if (fwrite($handle, $footer) !== 44) {
                throw new RuntimeException('Could not write the PAK footer.');
            }
        } finally {
            fclose($handle);
        }

        $validation = $this->validate($outputPath);
        if (empty($validation['ok'])) {
            throw new RuntimeException(
                'Generated PAK validation failed: '
                . implode('; ', (array)$validation['errors'])
            );
        }
        return $validation;
    }

    /** @return array<string,mixed> */
    public function validate(string $path): array
    {
        $errors = [];
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 44) {
            return ['ok' => false, 'errors' => ['PAK is too small']];
        }
        $footerOffset = strlen($data) - 44;
        $magic = unpack('V', substr($data, $footerOffset, 4))[1];
        $version = unpack('V', substr($data, $footerOffset + 4, 4))[1];
        $indexOffset = self::unpackI64($data, $footerOffset + 8);
        $indexSize = self::unpackI64($data, $footerOffset + 16);
        $indexHash = substr($data, $footerOffset + 24, 20);
        if ($magic !== self::MAGIC) {
            $errors[] = 'Bad PAK magic';
        }
        if ($version !== self::VERSION) {
            $errors[] = 'Unsupported PAK version';
        }
        if ($indexOffset < 0
            || $indexSize < 0
            || $indexOffset + $indexSize !== $footerOffset) {
            $errors[] = 'Invalid PAK index bounds';
        }

        $entries = [];
        $mountPoint = '';
        if (!$errors) {
            $index = substr($data, $indexOffset, $indexSize);
            if (!hash_equals($indexHash, hash('sha1', $index, true))) {
                $errors[] = 'PAK index SHA1 mismatch';
            } else {
                try {
                    $offset = 0;
                    $mountPoint = self::readUe4String($index, $offset);
                    if ($offset + 4 > strlen($index)) {
                        throw new RuntimeException('Truncated PAK entry count.');
                    }
                    $count = unpack('V', substr($index, $offset, 4))[1];
                    $offset += 4;
                    if ($count > 1000000) {
                        throw new RuntimeException('Invalid PAK entry count.');
                    }
                    $entrySize = self::entrySize((int)$version);
                    for ($indexNumber = 0; $indexNumber < $count; $indexNumber++) {
                        $filename = self::readUe4String($index, $offset);
                        if ($offset + $entrySize > strlen($index)) {
                            throw new RuntimeException('Truncated PAK entry.');
                        }
                        $entryOffset = self::unpackI64($index, $offset);
                        $size = self::unpackI64($index, $offset + 8);
                        $uncompressed = self::unpackI64($index, $offset + 16);
                        $compression = unpack('V', substr($index, $offset + 24, 4))[1];
                        $sha1 = substr($index, $offset + 28, 20);
                        $offset += $entrySize;
                        if ($compression !== 0 || $size !== $uncompressed) {
                            throw new RuntimeException(
                                'Generated PAK contains an unexpected compressed entry.'
                            );
                        }
                        if ($entryOffset < 0
                            || $entryOffset + $entrySize + $size > $indexOffset) {
                            throw new RuntimeException(
                                'PAK entry points outside the data area: ' . $filename
                            );
                        }
                        $payload = substr($data, $entryOffset + $entrySize, $size);
                        if (!hash_equals($sha1, hash('sha1', $payload, true))) {
                            throw new RuntimeException('PAK entry SHA1 mismatch: ' . $filename);
                        }
                        $entries[] = [
                            'filename' => $filename,
                            'offset' => $entryOffset,
                            'size' => $size,
                        ];
                    }
                } catch (Throwable $error) {
                    $errors[] = $error->getMessage();
                }
            }
        }

        return [
            'ok' => !$errors,
            'errors' => $errors,
            'version' => $version,
            'mount_point' => $mountPoint,
            'entries' => $entries,
            'file_count' => count($entries),
        ];
    }

    private static function packI64(int $value): string
    {
        $low = $value & 0xFFFFFFFF;
        $high = ($value >> 32) & 0xFFFFFFFF;
        return pack('V2', $low, $high);
    }

    private static function unpackI64(string $data, int $offset): int
    {
        $parts = unpack('Vlow/Vhigh', substr($data, $offset, 8));
        return ((int)$parts['high'] << 32) | (int)$parts['low'];
    }

    private static function ue4String(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new RuntimeException('PAK strings may not contain NUL bytes.');
        }
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            $utf16 = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($value . "\0", 'UTF-16LE', 'UTF-8')
                : false;
            if ($utf16 === false) {
                throw new RuntimeException(
                    'PAK paths must be ASCII when mbstring is unavailable.'
                );
            }
            $characters = intdiv(strlen($utf16), 2);
            return pack('V', (-$characters) & 0xFFFFFFFF) . $utf16;
        }
        $bytes = $value . "\0";
        return pack('V', strlen($bytes)) . $bytes;
    }

    private static function readUe4String(string $data, int &$offset): string
    {
        if ($offset + 4 > strlen($data)) {
            throw new RuntimeException('Truncated PAK string length.');
        }
        $rawLength = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        $length = $rawLength >= 0x80000000 ? $rawLength - 0x100000000 : $rawLength;
        if ($length < 0) {
            $bytes = -$length * 2;
            if ($offset + $bytes > strlen($data)) {
                throw new RuntimeException('Truncated PAK Unicode string.');
            }
            $raw = substr($data, $offset, $bytes);
            $offset += $bytes;
            $decoded = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                : $raw;
            return rtrim($decoded, "\0");
        }
        if ($offset + $length > strlen($data)) {
            throw new RuntimeException('Truncated PAK string.');
        }
        $raw = substr($data, $offset, $length);
        $offset += $length;
        return rtrim($raw, "\0");
    }

    private static function entryBytes(
        int $offset,
        int $size,
        string $sha1,
        int $version
    ): string {
        if (strlen($sha1) !== 20) {
            throw new RuntimeException('PAK entry SHA1 must be 20 bytes.');
        }
        $entry = self::packI64($offset)
            . self::packI64($size)
            . self::packI64($size)
            . pack('V', 0)
            . $sha1;
        if ($version >= 3) {
            $entry .= chr(0);
            $entry .= pack('V', 0);
        }
        return $entry;
    }

    private static function entrySize(int $version): int
    {
        return $version >= 3 ? 53 : 48;
    }
}
