<?php
/**
 * Compatibility boundary for remaining include-time HTTP behaviour.
 *
 * Legacy pages still include catalog/lib/CatalogSupport.php. All HTML response
 * compatibility rewrites now share one output buffer so a response is copied at
 * most once regardless of how many legacy presentation adjustments apply.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

use Throwable;

final class LegacySupportHooks
{
    public static function register(): void
    {
        self::registerResponseTransform();
        self::redirectStagedFileInformation();
        self::registerFederationInventoryEmergencyHandling();
    }

    private static function currentScript(): string
    {
        return basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    }

    private static function registerResponseTransform(): void
    {
        $script = self::currentScript();
        $requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $federation = str_contains($requestPath, '/catalog/federation/');
        $headAssets = [];

        $identityPath = dirname(__DIR__, 3) . '/assets/catalog-identities.js';
        $headAssets[$federation ? '../assets/catalog-identities.js' : 'assets/catalog-identities.js']
            = is_file($identityPath) ? (string)filemtime($identityPath) : '1';

        $layoutPath = dirname(__DIR__, 3) . '/assets/catalog-layout-fixes.js';
        $headAssets[$federation ? '../assets/catalog-layout-fixes.js' : 'assets/catalog-layout-fixes.js']
            = is_file($layoutPath) ? (string)filemtime($layoutPath) : '1';

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

        $rewriteMissingLinks = $script === 'missing.php';
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
            $rewriteMissingLinks,
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

            if ($rewriteMissingLinks) {
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

    private static function redirectStagedFileInformation(): void
    {
        if (self::currentScript() !== 'file-info.php') {
            return;
        }

        $fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $fileId = $fileId === false || $fileId === null ? 0 : (int)$fileId;
        if ($fileId < 1) {
            return;
        }

        try {
            $db = \catalog_db(\catalog_config());
            $row = \catalog_one(
                $db,
                'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1',
                [$fileId]
            );
            if ($row && (string)$row['scan_status'] === 'unverified') {
                header('Location: unverified-file-details.php?id=' . $fileId, true, 302);
                exit;
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB file info routing] ' . $error->getMessage());
        }
    }

    private static function registerFederationInventoryEmergencyHandling(): void
    {
        if (self::currentScript() !== 'inventories.php') {
            return;
        }

        set_exception_handler(static function (Throwable $error): void {
            $reference = self::federationInventoryErrorReference();
            self::federationInventoryEmergencyLog(
                $reference,
                get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine(),
                $error->getTraceAsString()
            );
            self::federationInventoryEmergencyRender($reference, $error->getMessage());
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
                return;
            }

            $reference = self::federationInventoryErrorReference();
            $summary = (string)($error['message'] ?? 'Unknown fatal error')
                . ' in ' . (string)($error['file'] ?? 'unknown file')
                . ':' . (int)($error['line'] ?? 0);
            self::federationInventoryEmergencyLog($reference, $summary);
            self::federationInventoryEmergencyRender(
                $reference,
                (string)($error['message'] ?? 'Unknown fatal error')
            );
        });
    }

    private static function federationInventoryErrorReference(): string
    {
        if (function_exists('catalog_request_id')) {
            try {
                $requestId = trim((string)\catalog_request_id());
                if ($requestId !== '') {
                    return $requestId;
                }
            } catch (Throwable) {
                // Fall through to an isolated reference.
            }
        }

        try {
            return bin2hex(random_bytes(12));
        } catch (Throwable) {
            return str_replace('.', '', uniqid('inventory', true));
        }
    }

    private static function federationInventoryEmergencyLog(
        string $reference,
        string $summary,
        string $details = ''
    ): void {
        $message = '[UnrealDB][' . $reference . '] federation inventory failure: ' . $summary;
        if ($details !== '') {
            $message .= "\n" . $details;
        }
        error_log($message);
    }

    private static function federationInventoryEmergencyRender(string $reference, string $message): void
    {
        if (!empty($GLOBALS['catalog_federation_inventory_emergency_rendered'])) {
            return;
        }
        $GLOBALS['catalog_federation_inventory_emergency_rendered'] = true;

        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
        }

        $cleanMessage = trim((string)(preg_replace('/\s+/u', ' ', $message) ?? $message));
        if (strlen($cleanMessage) > 1200) {
            $cleanMessage = substr($cleanMessage, 0, 1200) . '…';
        }
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Federation inventory error</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#111827;color:#e5e7eb;margin:0;padding:32px}'
            . '.error-card{max-width:920px;margin:0 auto;background:#1f2937;border:1px solid #7f1d1d;border-radius:10px;padding:24px}'
            . 'h1{margin-top:0;color:#fecaca}code{background:#111827;padding:2px 6px;border-radius:4px;overflow-wrap:anywhere}'
            . '.detail{white-space:pre-wrap;background:#111827;border:1px solid #374151;border-radius:6px;padding:12px;overflow-wrap:anywhere}'
            . 'a{color:#93c5fd}</style></head><body><div class="error-card">'
            . '<h1>Federation inventories could not be loaded</h1>'
            . '<p>The failure has been written to the PHP/Apache error log.</p>'
            . '<p><strong>Reference:</strong> <code>' . $escape($reference) . '</code></p>'
            . '<p class="detail"><strong>Error:</strong> ' . $escape($cleanMessage !== '' ? $cleanMessage : 'Unknown fatal error') . '</p>'
            . '<p>Search the server error log for <code>[UnrealDB][' . $escape($reference) . ']</code>.</p>'
            . '<p><a href="inventories.php">Retry Federation Inventories</a> · <a href="diagnostics.php?tab=logs">Federation Logs</a></p>'
            . '</div></body></html>';
    }
}
