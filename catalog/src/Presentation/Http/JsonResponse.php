<?php
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
        if (function_exists('catalog_system_error_record_http')) {
            \catalog_system_error_record_http($code, $message, $status, [
                'detail_keys' => array_values(array_map('strval', array_keys($details))),
            ]);
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
