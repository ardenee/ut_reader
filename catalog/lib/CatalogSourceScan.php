<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical local source-scan helper functions for compatibility callers.
 * Why: Active helper implementations now live under catalog/src/Infrastructure/Source.
 * Role: Thin compatibility facade; do not add source-scan implementation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogParser.php';
require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceScanPathPolicy;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceScanProgress;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceScanWorkFile;

function catalog_source_scan_relative_path(string $base, string $path): string
{
    return CatalogSourceScanPathPolicy::relativePath($base, $path);
}

/** @param array<string,mixed> $profile @param array<string,mixed> $config */
function catalog_source_scan_allowed_file(string $path, array $profile, array $config): bool
{
    return CatalogSourceScanPathPolicy::allowedFile($path, $profile, $config);
}

/** @return array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} */
function catalog_source_scan_work_file(string $path): array
{
    return CatalogSourceScanWorkFile::prepare($path);
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_cleanup_work_file(array $work): void
{
    CatalogSourceScanWorkFile::cleanup($work);
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_normalized_relative_path(string $relativePath, array $work): string
{
    return CatalogSourceScanPathPolicy::normalizedRelativePath($relativePath, $work);
}

/** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
function catalog_source_scan_sample(string $path, array $work, string $message): string
{
    return CatalogSourceScanPathPolicy::sample($path, $work, $message);
}

/** @param callable(array<string,mixed>):void|null $progress @param array<string,mixed> $state */
function catalog_source_scan_report(?callable $progress, array $state): void
{
    CatalogSourceScanProgress::report($progress, $state);
}
