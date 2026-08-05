<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

/**
 * Loads the shared table sorter for legacy and modern catalog pages.
 */
final class CatalogTableSortAssets
{
    public static function register(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $source = str_contains($requestPath, '/catalog/federation/')
            ? '../assets/catalog-table-sort.js'
            : 'assets/catalog-table-sort.js';
        $path = dirname(__DIR__, 3) . '/assets/catalog-table-sort.js';
        $version = is_file($path) ? (string)filemtime($path) : '1';

        ob_start(static function (string $output) use ($source, $version): string {
            if (!str_contains($output, '</head>') || str_contains($output, 'catalog-table-sort.js')) {
                return $output;
            }

            $script = '<script src="'
                . \catalog_h($source . '?v=' . rawurlencode($version))
                . '" defer></script>';
            return preg_replace('/<\/head>/', $script . '</head>', $output, 1) ?? $output;
        });
    }
}
