<?php
/**
 * Local-disk implementation of the package-storage boundary.
 *
 * The production deployment remains single-host/local-filesystem. This class
 * centralizes path validation, canonical verified placement, compensation and
 * capacity checks so import/download code does not duplicate filesystem policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use RuntimeException;
use UnrealDb\Catalog\Application\Storage\Contract\PackageStoragePort;

final class LocalFilesystemPackageStorage implements PackageStoragePort
{
    public function __construct(
        private readonly string $storageRoot,
        private readonly string $applicationRoot
    ) {
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerPath.php';
    }

    public function resolveExisting(string $relativePath): string
    {
        return LocalStoragePathGuard::resolveFile($this->storageRoot, $this->applicationRoot, $relativePath);
    }

    public function storeVerified(
        string $sourcePath,
        string $gameSlug,
        string $md5,
        string $extension,
        bool $discardDuplicateSource = true
    ): array {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Verified package source is unavailable.');
        }

        $slug = \scanner_slug_text($gameSlug);
        $directory = rtrim($this->storageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'games'
            . DIRECTORY_SEPARATOR . $slug
            . DIRECTORY_SEPARATOR . 'verified';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create storage folder: ' . $directory);
        }

        $extension = strtolower(trim($extension));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'bin';
        $storedName = strtolower(trim($md5)) . '.' . $extension;
        $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
        $created = false;

        if (is_file($destination)) {
            if ($discardDuplicateSource && is_file($sourcePath) && !@unlink($sourcePath)) {
                throw new RuntimeException('Could not discard duplicate physical upload');
            }
        } elseif (!@rename($sourcePath, $destination)) {
            throw new RuntimeException('Could not store upload');
        } else {
            $created = true;
        }

        return [
            'stored_name' => $storedName,
            'destination' => $destination,
            'relative_path' => 'storage/games/' . $slug . '/verified/' . $storedName,
            'created' => $created,
            'source_path' => $sourcePath,
        ];
    }

    public function rollbackVerified(array $stored): void
    {
        $destination = (string)($stored['destination'] ?? '');
        if (empty($stored['created']) || $destination === '' || !is_file($destination)) {
            return;
        }

        $sourcePath = (string)($stored['source_path'] ?? '');
        if ($sourcePath !== '' && !is_file($sourcePath) && @rename($destination, $sourcePath)) {
            return;
        }
        @unlink($destination);
    }

    public function health(): array
    {
        $path = rtrim($this->storageRoot, DIRECTORY_SEPARATOR);
        $available = is_dir($path);
        $total = $available ? @disk_total_space($path) : false;
        $free = $available ? @disk_free_space($path) : false;

        return [
            'path' => $path,
            'available' => $available,
            'readable' => $available && is_readable($path),
            'writable' => $available && is_writable($path),
            'total_bytes' => is_int($total) || is_float($total) ? max(0, (int)$total) : null,
            'free_bytes' => is_int($free) || is_float($free) ? max(0, (int)$free) : null,
        ];
    }
}
