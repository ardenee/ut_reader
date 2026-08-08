<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides stateless path, redirect, sample, and progress primitives used by the active local source scanner.
 * Why: Stateful discovery, identity, fingerprint, import and staging responsibilities live under `catalog/src` while stable parser-adjacent helpers remain available during staged cleanup.
 * Role: Shared source-scan compatibility helper layer; orchestration lives in `CatalogSourceScanNoContainers.php`.
 * Audit: Obsolete all-in-one scanning and stateful persistence/import helpers have been retired from this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogParser.php';
require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/GameProfiles.php';

function catalog_source_scan_relative_path(string $base, string $path): string
{
    $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : basename($path);
}

/** @param array<string,mixed> $profile @param array<string,mixed> $config */
function catalog_source_scan_allowed_file(string $path, array $profile, array $config): bool
{
    if (catalog_redirect_archive_is_supported_filename($path)) {
        return true;
    }
    $cleanName = catalog_clean_unreal_filename(basename($path));
    $extension = catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
    return in_array($extension, scanner_profile_extensions($profile, $config), true);
}

/** @return array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} */
function catalog_source_scan_work_file(string $path): array
{
    $name = catalog_clean_unreal_filename(basename($path));
    if (!catalog_redirect_archive_is_supported_filename($name)) {
        return ['path' => $path, 'name' => $name, 'temp' => false, 'redirect' => false, 'source_extension' => ''];
    }
    $decoded = catalog_redirect_archive_decompress_to_temp($path, $name);
    return [
        'path' => (string)$decoded['path'],
        'name' => catalog_clean_unreal_filename((string)$decoded['filename']),
        'temp' => true,
        'redirect' => true,
        'source_extension' => (string)$decoded['source_extension'],
    ];
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_cleanup_work_file(array $work): void
{
    if ($work['temp'] && is_file($work['path'])) {
        @unlink($work['path']);
    }
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_normalized_relative_path(string $relativePath, array $work): string
{
    $relativePath = scanner_normalize_source_relative_path($relativePath);
    if ($relativePath === '' || !$work['redirect']) {
        return $relativePath;
    }
    $directory = trim(str_replace('\\', '/', dirname($relativePath)), '. /');
    return scanner_normalize_source_relative_path(($directory !== '' ? $directory . '/' : '') . $work['name']);
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_sample(string $path, array $work, string $message): string
{
    return ($work['redirect'] ? $path . ' → ' . $work['name'] : $path) . ' - ' . $message;
}

/** @param callable(array<string,mixed>):void|null $progress */
function catalog_source_scan_report(?callable $progress, array $state): void
{
    if ($progress !== null) {
        $progress($state);
    }
}
