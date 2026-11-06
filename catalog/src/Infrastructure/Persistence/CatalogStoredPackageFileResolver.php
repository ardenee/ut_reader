<?php
/**
 * Resolve a verified original package file under catalogue storage.
 *
 * ue_files.relative_path contains paths relative to the catalogue root
 * (usually "storage/games/..."), whereas config.storage_path points *inside*
 * catalogue storage. Do not concatenate these blindly as storage/storage.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

final class CatalogStoredPackageFileResolver
{
    /** @param array<string,mixed> $config @param array<string,mixed> $file */
    public static function resolve(array $config, array $file): ?string
    {
        $relative = str_replace('\\', '/', trim((string)($file['relative_path'] ?? '')));
        // Original package identity must be a catalogue-relative pathname,
        // never an absolute path or path traversal from database input.
        if ($relative === '' || str_contains($relative, "\0")
            || preg_match('~^(?:[A-Za-z]:|/|//)~', $relative) === 1
            || preg_match('~(?:^|/)\\.\\.?(?:/|$)~', $relative) === 1) {
            return null;
        }

        $storage = realpath((string)($config['storage_path'] ?? ''));
        if (!is_string($storage) || $storage === '') return null;

        $tail = str_starts_with(strtolower($relative), 'storage/')
            ? substr($relative, 8) : $relative;
        if ($tail === '') return null;

        $candidate = realpath($storage . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $tail));
        if ($candidate === false || !is_file($candidate)) return null;

        $root = rtrim(str_replace('\\', '/', $storage), '/') . '/';
        $path = str_replace('\\', '/', $candidate);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }
        return str_starts_with($path, $root) ? $candidate : null;
    }
}
