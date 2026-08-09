<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Registers bounded PHP error, exception and fatal-shutdown capture for web requests.
 * Why: Runtime handler installation is separate from error normalization and persistence.
 * Role: Infrastructure telemetry bootstrap preserving previous-handler chaining and fallback response behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use Throwable;
use UnrealDb\Catalog\Application\Telemetry\CatalogSystemErrorNormalizer;

final class CatalogSystemErrorHandlerRegistry
{
    public static function register(): void
    {
        if (PHP_SAPI === 'cli' || !empty($GLOBALS['catalog_system_error_registered'])) {
            return;
        }
        $GLOBALS['catalog_system_error_registered'] = true;

        $previousErrorHandler = null;
        $previousErrorHandler = set_error_handler(
            static function (
                int $type,
                string $message,
                string $file,
                int $line
            ) use (&$previousErrorHandler): bool {
                if ((error_reporting() & $type) !== 0) {
                    CatalogSystemErrorRecorder::record([
                        'source_kind' => 'php',
                        'severity' => CatalogSystemErrorNormalizer::phpSeverity($type),
                        'error_type' => CatalogSystemErrorNormalizer::phpType($type),
                        'message' => $message,
                        'source_file' => $file,
                        'source_line' => $line,
                    ]);
                }
                return is_callable($previousErrorHandler)
                    ? (bool)$previousErrorHandler($type, $message, $file, $line)
                    : false;
            }
        );

        $previousExceptionHandler = null;
        $previousExceptionHandler = set_exception_handler(
            static function (Throwable $error) use (&$previousExceptionHandler): void {
                CatalogSystemErrorRecorder::recordException($error, 'php_uncaught');
                if (is_callable($previousExceptionHandler)) {
                    $previousExceptionHandler($error);
                    return;
                }

                $reference = CatalogSystemErrorNormalizer::requestId();
                error_log(
                    '[UnrealDB][' . $reference . '] uncaught ' . get_class($error) . ': '
                    . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine()
                    . "\n" . $error->getTraceAsString()
                );
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=UTF-8');
                    header('Cache-Control: no-store');
                }
                echo 'The request could not be completed. Reference: ' . $reference;
            }
        );

        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if (!is_array($last)) {
                return;
            }
            $type = (int)($last['type'] ?? 0);
            if (!in_array(
                $type,
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR],
                true
            )) {
                return;
            }
            CatalogSystemErrorRecorder::record([
                'source_kind' => 'php_fatal',
                'severity' => 'critical',
                'error_type' => CatalogSystemErrorNormalizer::phpType($type),
                'message' => (string)($last['message'] ?? 'Unknown fatal error.'),
                'source_file' => (string)($last['file'] ?? ''),
                'source_line' => (int)($last['line'] ?? 0),
            ]);
        });
    }
}
