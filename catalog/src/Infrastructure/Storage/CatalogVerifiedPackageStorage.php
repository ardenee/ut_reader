<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Places one verified package in its canonical game storage location.
 * Why: Filesystem creation, physical-upload dedupe and rollback metadata should not be embedded in package orchestration.
 * Role: Infrastructure storage collaborator for verified package import.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use RuntimeException;

final class CatalogVerifiedPackageStorage
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerPath.php';
    }

    /**
     * @return array{stored_name:string,destination:string,relative_path:string,created:bool,source_path:string}
     */
    public function store(
        string $temporaryPath,
        string $gameSlug,
        string $md5,
        string $extension
    ): array {
        $slug = \scanner_slug_text($gameSlug);
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . '/games/' . $slug . '/verified';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create storage folder: ' . $directory);
        }

        $storedName = $md5 . '.' . $extension;
        $destination = $directory . '/' . $storedName;
        $created = false;
        if (is_file($destination)) {
            if (is_file($temporaryPath) && !@unlink($temporaryPath)) {
                throw new RuntimeException('Could not discard duplicate physical upload');
            }
        } elseif (!rename($temporaryPath, $destination)) {
            throw new RuntimeException('Could not store upload');
        } else {
            $created = true;
        }

        return [
            'stored_name' => $storedName,
            'destination' => $destination,
            'relative_path' => 'storage/games/' . $slug . '/verified/' . $storedName,
            'created' => $created,
            'source_path' => $temporaryPath,
        ];
    }

    /** @param array{destination?:string,created?:bool,source_path?:string} $stored */
    public function rollbackCreated(array $stored): void
    {
        $destination = (string)($stored['destination'] ?? '');
        if (empty($stored['created']) || $destination === '' || !is_file($destination)) {
            return;
        }

        // Persistence happens after the physical move. Restore the caller-owned
        // source first so retry/unverified retention still has the original bytes.
        // Falling back to the historical delete keeps rollback bounded if the
        // source path unexpectedly became unavailable or could not be restored.
        $sourcePath = (string)($stored['source_path'] ?? '');
        if ($sourcePath !== '' && !is_file($sourcePath) && @rename($destination, $sourcePath)) {
            return;
        }
        @unlink($destination);
    }
}
