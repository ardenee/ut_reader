<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `JsonResponse` for JSON response.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public static function send(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function data(array $data, int $status = 200): never
    {
        self::send(['data' => $data], $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): never
    {
        // Record the full internal diagnostic before applying the public 5xx
        // boundary. Client mistakes (4xx) retain their actionable validation
        // text; server failures never expose SQL, paths, exception strings or
        // other implementation details even if a call site passes them here.
        if (function_exists('catalog_system_error_record_http')) {
            \catalog_system_error_record_http($code, $message, $status, [
                'detail_keys' => array_values(array_map('strval', array_keys($details))),
            ]);
        }

        if ($status >= 500) {
            $requestId = function_exists('catalog_request_id')
                ? \catalog_request_id()
                : bin2hex(random_bytes(12));
            $message = 'The request could not be completed. Reference: ' . $requestId;
            $details = ['request_id' => $requestId];
        }

        self::send([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object)$details,
            ],
        ], $status);
    }
}
