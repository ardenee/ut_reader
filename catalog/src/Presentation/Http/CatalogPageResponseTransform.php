<?php
/**
 * Shared presentation transform for catalog-wide assets and remaining page-link normalization.
 *
 * A single response buffer owns these cross-cutting HTML adjustments so pages are
 * copied at most once. Page-specific business logic remains outside this class.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

final class CatalogPageResponseTransform
{
    public static function register(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $federation = str_contains($requestPath, '/catalog/federation/');
        $headAssets = [];

        foreach ([
            'catalog-identities.js',
            'catalog-layout-fixes.js',
            'catalog-table-sort.js',
        ] as $asset) {
            $path = dirname(__DIR__, 3) . '/assets/' . $asset;
            $source = ($federation ? '../assets/' : 'assets/') . $asset;
            $headAssets[$source] = is_file($path) ? (string)filemtime($path) : '1';
        }

        if ($script === 'duplicates.php') {
            $path = dirname(__DIR__, 3) . '/assets/duplicates-keep.js';
            $headAssets['assets/duplicates-keep.js'] = is_file($path) ? (string)filemtime($path) : '1';
        }
        if ($script === 'unverified-files.php') {
            foreach ([
                'assets/unverified-files-layout.js',
                'assets/unverified-duplicate-cleanup.js',
            ] as $source) {
                $path = dirname(__DIR__, 3) . '/' . $source;
                $headAssets[$source] = is_file($path) ? (string)filemtime($path) : '1';
            }
        }

        $normalizeMissingLinks = $script === 'missing.php';
        $injectGameManagerCounts = $script === 'game-manager.php'
            && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
            && !isset($_GET['progress']);
        $gameManagerVersion = '1';
        if ($injectGameManagerCounts) {
            $path = dirname(__DIR__, 3) . '/assets/game-manager-missing-counts.js';
            $gameManagerVersion = is_file($path) ? (string)filemtime($path) : '1';
        }

        ob_start(static function (string $html) use (
            $headAssets,
            $normalizeMissingLinks,
            $injectGameManagerCounts,
            $gameManagerVersion
        ): string {
            if ($headAssets !== [] && str_contains($html, '</head>')) {
                $injection = '';
                foreach ($headAssets as $source => $version) {
                    if (str_contains($html, $source)) {
                        continue;
                    }
                    $injection .= '<script src="'
                        . \catalog_h($source . '?v=' . rawurlencode($version))
                        . '" defer></script>';
                }
                if ($injection !== '') {
                    $html = preg_replace('/<\/head>/', $injection . '</head>', $html, 1) ?? $html;
                }
            }

            if ($normalizeMissingLinks) {
                $html = strtr($html, [
                    'federation/request-generate.php' => 'federation/inventories.php',
                    'federation/request-status.php' => 'federation/requests.php',
                    'federation/approved-downloads.php' => 'federation/requests.php',
                    'federation/peer-inventory.php' => 'federation/inventories.php',
                    'federation/conflicts.php' => 'federation/diagnostics.php?tab=conflicts',
                ]);
            }

            if ($injectGameManagerCounts
                && str_contains($html, '</body>')
                && !str_contains($html, 'game-manager-missing-counts.js')) {
                $asset = '<script src="assets/game-manager-missing-counts.js?v='
                    . \catalog_h($gameManagerVersion) . '" defer></script>';
                $html = str_replace('</body>', $asset . '</body>', $html);
            }

            return $html;
        });
    }
}
