<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

\UnrealDb\Catalog\Presentation\Http\LegacySupportHooks::register();

/*
 * Game Admin is a large lifecycle page. Attach its lightweight dependency-count
 * enhancement only for the normal HTML GET response, without affecting AJAX
 * reset/delete actions or progress JSON responses.
 */
if (
    basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'game-manager.php'
    && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && !isset($_GET['progress'])
) {
    ob_start(static function (string $html): string {
        if (!str_contains($html, '</body>')) {
            return $html;
        }
        $assetPath = __DIR__ . '/../assets/game-manager-missing-counts.js';
        $version = is_file($assetPath) ? (string)filemtime($assetPath) : '1';
        $script = '<script src="assets/game-manager-missing-counts.js?v=' . catalog_h($version) . '" defer></script>';
        return str_replace('</body>', $script . '</body>', $html);
    });
}
