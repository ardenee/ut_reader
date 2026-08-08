<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns path normalization, package-file filtering and sample-label formatting for local source scans.
 * Why: These pure policies should not live as procedural scanner helpers under catalog/lib.
 * Role: Stateless source-scan policy shared by discovery, import and orchestration compatibility wrappers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

final class CatalogSourceScanPathPolicy
{
    private static bool $booted = false;

    public static function relativePath(string $base, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : basename($path);
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $config */
    public static function allowedFile(string $path, array $profile, array $config): bool
    {
        self::boot();
        if (\catalog_redirect_archive_is_supported_filename($path)) {
            return true;
        }
        $cleanName = \catalog_clean_unreal_filename(basename($path));
        $extension = \catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
        return in_array($extension, \scanner_profile_extensions($profile, $config), true);
    }

    /** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
    public static function normalizedRelativePath(string $relativePath, array $work): string
    {
        self::boot();
        $relativePath = \scanner_normalize_source_relative_path($relativePath);
        if ($relativePath === '' || !$work['redirect']) {
            return $relativePath;
        }
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '. /');
        return \scanner_normalize_source_relative_path(
            ($directory !== '' ? $directory . '/' : '') . $work['name']
        );
    }

    /** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
    public static function sample(string $path, array $work, string $message): string
    {
        return ($work['redirect'] ? $path . ' → ' . $work['name'] : $path) . ' - ' . $message;
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogRedirectArchive.php';
        require_once $root . '/lib/CatalogScanner.php';
        self::$booted = true;
    }
}
