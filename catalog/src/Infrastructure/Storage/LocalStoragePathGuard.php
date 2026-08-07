<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `LocalStoragePathGuard` for local storage path guard.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

final class LocalStoragePathGuard
{
    public static function resolveFile(string $storageRoot, string $applicationRoot, string $relativePath): string
    {
        $root = realpath(rtrim($storageRoot, DIRECTORY_SEPARATOR));
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Storage root is unavailable.');
        }

        $relativePath = trim(str_replace(["\0", '\\'], ['', '/'], $relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:\//', $relativePath) === 1) {
            throw new \RuntimeException('Stored file path is invalid.');
        }

        $candidate = realpath(rtrim($applicationRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($candidate === false || !is_file($candidate)) {
            throw new \RuntimeException('Stored file is missing.');
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $rootPrefix)) {
            throw new \RuntimeException('Stored file is outside the configured storage root.');
        }

        return $candidate;
    }
}
