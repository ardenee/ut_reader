<?php
/**
 * Production-capable UEDB5 file writer kept outside the live UEDB4 publication path.
 * It writes and verifies the canonical .uedb5 file but deliberately performs no SQL registration.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use Throwable;

final class Uedb5MetadataSnapshotWriter
{
    private const REPLACE_RETRY_ATTEMPTS = 120;
    private const REPLACE_RETRY_DELAY_US = 50000;

    public function __construct(private readonly string $storageRoot)
    {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for UEDB5 writing.');
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function write(array $snapshot, int $blockSize = Uedb5MetadataContainer::DEFAULT_BLOCK_SIZE): array
    {
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UEDB5 snapshot writing requires positive file and game IDs.');
        }

        $paths = Uedb5StagingIsolationContract::assertContainerPathIsolation($this->storageRoot, $gameId, $fileId);
        $path = $paths['v5'];
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create UEDB5 metadata directory: ' . $directory);
        }
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        try {
            $built = Uedb5MetadataContainer::buildToFile($snapshot, $temporaryPath, $blockSize);
            $manifest = (array)$built['manifest'];
            if ((int)($manifest['file']['id'] ?? 0) !== $fileId
                || (int)($manifest['file']['game_id'] ?? 0) !== $gameId) {
                throw new RuntimeException('Built UEDB5 manifest identity does not match the requested target.');
            }

            // Keep the old target intact unless the platform can replace it with one rename.
            // Windows can transiently reject replacement while another Pass-2 worker has
            // the existing provider open for reading. Retry the same atomic operation;
            // there is intentionally no unlink-before-rename fallback.
            $this->replaceAtomically($temporaryPath, $path);

            clearstatcache(true, $path);
            $verified = Uedb5MetadataContainer::verifyFile(
                $path,
                $fileId,
                (string)$built['payload_sha256']
            );
            $verifiedManifest = (array)$verified['manifest'];
            if ((int)($verifiedManifest['file']['game_id'] ?? 0) !== $gameId) {
                throw new RuntimeException('Published UEDB5 manifest game identity mismatch.');
            }

            return [
                'path' => $path,
                'format_version' => Uedb5MetadataContainer::FORMAT_VERSION,
                'codec' => Uedb5MetadataContainer::CODEC_BLOCK_GZIP,
                'compressed_size' => (int)$verified['compressed_size'],
                'uncompressed_size' => (int)$built['uncompressed_size'],
                'block_count' => (int)$verified['block_count'],
                'payload_sha256' => (string)$verified['payload_sha256'],
                'payload_sha256_hex' => strtoupper(bin2hex((string)$verified['payload_sha256'])),
                'manifest' => $verifiedManifest,
            ];
        } catch (Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function replaceAtomically(string $temporaryPath, string $path): void
    {
        for ($attempt = 1; $attempt <= self::REPLACE_RETRY_ATTEMPTS; $attempt++) {
            if (@rename($temporaryPath, $path)) {
                return;
            }
            if ($attempt < self::REPLACE_RETRY_ATTEMPTS) {
                clearstatcache(true, $path);
                usleep(self::REPLACE_RETRY_DELAY_US);
            }
        }
        throw new RuntimeException('Could not atomically replace UEDB5 metadata file after sharing-lock retry: ' . $path);
    }

    public function path(int $gameId, int $fileId): string
    {
        return Uedb5MetadataContainer::path($this->storageRoot, $gameId, $fileId);
    }
}
