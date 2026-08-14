<?php
/**
 * Emergency exception/fatal rendering for the Federation Inventories page.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

use Throwable;

final class CatalogFederationInventoryFailureHandler
{
    public static function register(): void
    {
        if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'inventories.php') {
            return;
        }

        set_exception_handler(static function (Throwable $error): void {
            $reference = self::errorReference();
            self::log(
                $reference,
                get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine(),
                $error->getTraceAsString()
            );
            self::render($reference, $error->getMessage());
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

            $reference = self::errorReference();
            $summary = (string)($error['message'] ?? 'Unknown fatal error')
                . ' in ' . (string)($error['file'] ?? 'unknown file')
                . ':' . (int)($error['line'] ?? 0);
            self::log($reference, $summary);
            self::render($reference, (string)($error['message'] ?? 'Unknown fatal error'));
        });
    }

    private static function errorReference(): string
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

    private static function log(string $reference, string $summary, string $details = ''): void
    {
        $message = '[UnrealDB][' . $reference . '] federation inventory failure: ' . $summary;
        if ($details !== '') {
            $message .= "\n" . $details;
        }
        error_log($message);
    }

    private static function render(string $reference, string $message): void
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
