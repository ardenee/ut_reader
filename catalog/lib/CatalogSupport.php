<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

\UnrealDb\Catalog\Presentation\Http\LegacySupportHooks::register();

/*
 * Upload Bucket advertises no UnrealDB total-file-size cap. Redirect archives
 * must therefore not inherit the ordinary profiled-upload output limit while
 * reconstructing their real package bytes. This changes only the size ceiling;
 * the extension-specific Epic UZ/UZ2/UZ3 decoders remain responsible for the
 * actual format validation.
 */
if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['upload-bucket.php', 'upload-bucket-chunk.php'], true)) {
    $redirectLimit = (int)(getenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES') ?: 0);
    if ($redirectLimit <= 0) {
        putenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES=' . (PHP_INT_SIZE >= 8 ? '2147483647' : (string)PHP_INT_MAX));
    }
}

/**
 * peer-inventory.php depends on several federation services before its own page
 * try/catch begins. Keep an emergency handler at the shared bootstrap layer so
 * bootstrap failures, secondary rendering failures, and fatal shutdowns produce
 * a useful request reference instead of a blank web-server 500 response.
 */
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'peer-inventory.php') {
    function catalog_peer_inventory_error_reference(): string
    {
        if (function_exists('catalog_request_id')) {
            try {
                $requestId = trim((string)catalog_request_id());
                if ($requestId !== '') {
                    return $requestId;
                }
            } catch (Throwable) {
                // Fall through to an isolated reference that needs no catalog state.
            }
        }

        try {
            return bin2hex(random_bytes(12));
        } catch (Throwable) {
            return str_replace('.', '', uniqid('peer', true));
        }
    }

    function catalog_peer_inventory_emergency_log(string $reference, string $summary, string $details = ''): void
    {
        $message = '[UnrealDB][' . $reference . '] peer-inventory failure: ' . $summary;
        if ($details !== '') {
            $message .= "\n" . $details;
        }
        error_log($message);
    }

    function catalog_peer_inventory_emergency_render(string $reference, string $message): void
    {
        if (!empty($GLOBALS['catalog_peer_inventory_emergency_rendered'])) {
            return;
        }
        $GLOBALS['catalog_peer_inventory_emergency_rendered'] = true;

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
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Child inventory error</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#111827;color:#e5e7eb;margin:0;padding:32px}'
            . '.error-card{max-width:920px;margin:0 auto;background:#1f2937;border:1px solid #7f1d1d;border-radius:10px;padding:24px}'
            . 'h1{margin-top:0;color:#fecaca}code{background:#111827;padding:2px 6px;border-radius:4px;overflow-wrap:anywhere}'
            . '.detail{white-space:pre-wrap;background:#111827;border:1px solid #374151;border-radius:6px;padding:12px;overflow-wrap:anywhere}'
            . 'a{color:#93c5fd}</style></head><body><div class="error-card">'
            . '<h1>Child inventory could not be loaded</h1>'
            . '<p>The failure has been written to the PHP/Apache error log.</p>'
            . '<p><strong>Reference:</strong> <code>' . $escape($reference) . '</code></p>'
            . '<p class="detail"><strong>Error:</strong> ' . $escape($cleanMessage !== '' ? $cleanMessage : 'Unknown fatal error') . '</p>'
            . '<p>Search the server error log for <code>[UnrealDB][' . $escape($reference) . ']</code>.</p>'
            . '<p><a href="peer-inventory.php">Retry Child Inventory</a> · <a href="logs.php">Federation Logs</a></p>'
            . '</div></body></html>';
    }

    set_exception_handler(static function (Throwable $error): void {
        $reference = catalog_peer_inventory_error_reference();
        catalog_peer_inventory_emergency_log(
            $reference,
            get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine(),
            $error->getTraceAsString()
        );
        catalog_peer_inventory_emergency_render($reference, $error->getMessage());
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

        $reference = catalog_peer_inventory_error_reference();
        $summary = (string)($error['message'] ?? 'Unknown fatal error')
            . ' in ' . (string)($error['file'] ?? 'unknown file')
            . ':' . (int)($error['line'] ?? 0);
        catalog_peer_inventory_emergency_log($reference, $summary);
        catalog_peer_inventory_emergency_render($reference, (string)($error['message'] ?? 'Unknown fatal error'));
    });
}

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
