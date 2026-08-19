<?php
/**
 * Shared presentation transform for catalog-wide assets.
 *
 * A single response buffer owns cross-cutting asset injection so pages are copied
 * at most once. Page-specific routing and business logic remain at their source.
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
        $cspNonce = function_exists('catalog_security_csp_nonce')
            ? \catalog_security_csp_nonce()
            : '';

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
        if (in_array($script, ['unverified-files.php', 'system-errors.php', 'upload-issues.php'], true)) {
            $path = dirname(__DIR__, 3) . '/assets/admin-all-matching.js';
            $headAssets['assets/admin-all-matching.js'] = is_file($path) ? (string)filemtime($path) : '1';
        }

        $injectGameManagerCounts = $script === 'game-manager.php'
            && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
            && !isset($_GET['progress']);
        $gameManagerVersion = '1';
        if ($injectGameManagerCounts) {
            $corePath = dirname(__DIR__, 3) . '/assets/js/core/http.js';
            $headAssets['assets/js/core/http.js'] = is_file($corePath) ? (string)filemtime($corePath) : '1';
            $path = dirname(__DIR__, 3) . '/assets/game-manager-missing-counts.js';
            $gameManagerVersion = is_file($path) ? (string)filemtime($path) : '1';
        }

        ob_start(static function (string $html) use (
            $headAssets,
            $injectGameManagerCounts,
            $gameManagerVersion,
            $cspNonce
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

            if ($injectGameManagerCounts
                && str_contains($html, '</body>')
                && !str_contains($html, 'game-manager-missing-counts.js')) {
                $asset = '<script src="assets/game-manager-missing-counts.js?v='
                    . \catalog_h($gameManagerVersion) . '" defer></script>';
                $html = str_replace('</body>', $asset . '</body>', $html);
            }

            // The catalog still contains a number of server-rendered inline
            // script blocks. Rather than permit all inline script, attach the
            // per-request CSP nonce to every script element in one shared output
            // boundary. Public response-cache entries remain nonce-free at rest
            // and receive the current request nonce when they pass this transform.
            if ($cspNonce !== '' && stripos($html, '<script') !== false) {
                $escapedNonce = htmlspecialchars($cspNonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html = preg_replace_callback(
                    '/<script\b([^>]*)>/i',
                    static function (array $match) use ($escapedNonce): string {
                        $attributes = (string)($match[1] ?? '');
                        if (preg_match('/\bnonce\s*=/i', $attributes) === 1) {
                            return (string)$match[0];
                        }
                        return '<script nonce="' . $escapedNonce . '"' . $attributes . '>';
                    },
                    $html
                ) ?? $html;
            }

            return $html;
        });
    }
}
