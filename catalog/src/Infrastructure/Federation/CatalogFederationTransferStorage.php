<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation incoming/local payload path resolution and containment.
 * Why: Federation transfer orchestration should not duplicate storage-root validation or incoming filename policy.
 * Role: Infrastructure filesystem boundary for federation transfers/imports.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use RuntimeException;

final class CatalogFederationTransferStorage
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function incomingDirectory(): string
    {
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'federation' . DIRECTORY_SEPARATOR . 'incoming';
        if (!is_dir($directory)
            && !@mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Could not create federation incoming folder: ' . $directory);
        }
        return $directory;
    }

    public static function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?? 'download.bin';
        return $name !== '' ? $name : 'download.bin';
    }

    /** @param array<string,mixed> $file */
    public function verifiedFilePath(array $file): string
    {
        return $this->resolveStorageRelative((string)$file['relative_path'], 'Stored local file');
    }

    public function incomingPath(string $relativePath): string
    {
        return $this->resolveStorageRelative($relativePath, 'Incoming file');
    }

    public function incomingRelative(string $absolutePath): string
    {
        return 'storage/federation/incoming/' . basename($absolutePath);
    }

    private function resolveStorageRelative(string $relativePath, string $label): string
    {
        $root = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Catalog storage folder is unavailable.');
        }

        $relative = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if (str_starts_with(strtolower($relative), 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '../')) {
            throw new RuntimeException($label . ' path is invalid: ' . $relativePath);
        }

        $candidate = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $path = realpath($candidate);
        $rootNormalized = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $pathNormalized = $path !== false ? str_replace('\\', '/', $path) : '';
        $inside = $path !== false && (DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($pathNormalized . '/'), strtolower($rootNormalized))
            : str_starts_with($pathNormalized . '/', $rootNormalized));
        if (!$inside || !is_file($path) || is_link($path)) {
            throw new RuntimeException($label . ' is missing or outside storage: ' . $relativePath);
        }
        return $path;
    }
}
