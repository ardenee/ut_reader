<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the shared package-scan primitives used by the active local source scanner.
 * Why: Common path filtering, redirect preparation, import, staging, and progress helpers are reused by the
 *      durable no-container scan path while stateful persistence lives under `catalog/src`.
 * Role: Shared source-scan compatibility helper layer; orchestration lives in `CatalogSourceScanNoContainers.php`.
 * Audit: The obsolete all-in-one `catalog_source_scan_run()` path and source-location persistence helpers were retired.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogParser.php';
require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;

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

function catalog_source_scan_temp_copy(string $path): string
{
    $temporary = tempnam(sys_get_temp_dir(), 'ue_src_scan_');
    if ($temporary === false) {
        throw new RuntimeException('Could not create temporary file for profiled source import.');
    }
    if (!copy($path, $temporary)) {
        @unlink($temporary);
        throw new RuntimeException('Could not copy source file to temporary scan file.');
    }
    return $temporary;
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

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $source
 * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
 * @return array<int,mixed>
 */
function catalog_source_scan_import_work_file(PDO $db, array $config, array $source, array $work, string $relativePath, bool $strictProfile, ?int $userId): array
{
    return scanner_scan_uploaded_file(
        $db,
        $config,
        (int)$source['game_id'],
        catalog_source_scan_temp_copy($work['path']),
        $work['name'],
        $userId,
        $strictProfile,
        null,
        false,
        ['source_relative_path' => catalog_source_scan_normalized_relative_path($relativePath, $work)]
    );
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_sample(string $path, array $work, string $message): string
{
    return ($work['redirect'] ? $path . ' → ' . $work['name'] : $path) . ' - ' . $message;
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $source
 * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
 * @return array{queue_name:string,file_id:int}|null
 */
function catalog_source_scan_stage_failed(PDO $db, array $config, array $source, array $work, string $relativePath, Throwable $error, ?int $userId): ?array
{
    if (!is_file($work['path'])) {
        return null;
    }
    $sourceRelativePath = catalog_source_scan_normalized_relative_path($relativePath, $work);
    $reason = 'Local Source Scan import failed for ' . $sourceRelativePath . ': ' . $error->getMessage();
    $stager = new LegacyUnverifiedFileStager($db, $config);
    $result = $stager->stageFailedCopy(
        (int)$source['game_id'],
        $work['path'],
        $work['name'],
        $reason,
        $userId,
        $sourceRelativePath
    );
    return $result === null ? null : ['queue_name' => (string)$result['queue_name'], 'file_id' => (int)$result['file_id']];
}

/** @param callable(array<string,mixed>):void|null $progress */
function catalog_source_scan_report(?callable $progress, array $state): void
{
    if ($progress !== null) {
        $progress($state);
    }
}
