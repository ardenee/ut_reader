<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns redirect-aware work-file preparation and temporary-file cleanup for local source scans.
 * Why: Redirect decompression creates temporary resources and should have one explicit lifecycle owner.
 * Role: Infrastructure filesystem collaborator; redirect codec behavior remains delegated to the existing archive implementation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

final class CatalogSourceScanWorkFile
{
    private static bool $booted = false;

    /** @return array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} */
    public static function prepare(string $path): array
    {
        self::boot();
        $name = \catalog_clean_unreal_filename(basename($path));
        if (!\catalog_redirect_archive_is_supported_filename($name)) {
            return [
                'path' => $path,
                'name' => $name,
                'temp' => false,
                'redirect' => false,
                'source_extension' => '',
            ];
        }

        $decoded = \catalog_redirect_archive_decompress_to_temp($path, $name);
        return [
            'path' => (string)$decoded['path'],
            'name' => \catalog_clean_unreal_filename((string)$decoded['filename']),
            'temp' => true,
            'redirect' => true,
            'source_extension' => (string)$decoded['source_extension'],
        ];
    }

    /** @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work */
    public static function cleanup(array $work): void
    {
        if ($work['temp'] && is_file($work['path'])) {
            @unlink($work['path']);
        }
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogRedirectArchive.php';
        self::$booted = true;
    }
}
