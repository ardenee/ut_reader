<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation JSON request-body limits, parsing, request-path normalization and JSON response emission.
 * Why: HTTP request/response mechanics are transport concerns independent of authentication, identity and persistence.
 * Role: Federation HTTP boundary preserving historical status codes, messages, JSON flags and response headers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use JsonException;

final class CatalogFederationJsonApi
{
    public static function bodyLimitBytes(int $default = 1048576): int
    {
        $configured = (int)(getenv('UNREALDB_FEDERATION_MAX_JSON_BYTES') ?: 0);
        return max(1024, min($configured > 0 ? $configured : $default, 64 * 1024 * 1024));
    }

    /** @param array<string,mixed>|null $server */
    public static function readRequestBody(?int $maxBytes = null, ?array $server = null): string
    {
        $server ??= $_SERVER;
        $limit = $maxBytes ?? self::bodyLimitBytes();
        $limit = max(1024, min($limit, 64 * 1024 * 1024));
        $declaredLength = filter_var($server['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if ($declaredLength !== false && $declaredLength !== null && (int)$declaredLength > $limit) {
            self::respond(['ok' => false, 'error' => 'Request body exceeds the allowed size.'], 413);
        }

        $stream = fopen('php://input', 'rb');
        if (!is_resource($stream)) {
            self::respond(['ok' => false, 'error' => 'Request body could not be read.'], 400);
        }
        try {
            $body = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($body)) {
            self::respond(['ok' => false, 'error' => 'Request body could not be read.'], 400);
        }
        if (strlen($body) > $limit) {
            self::respond(['ok' => false, 'error' => 'Request body exceeds the allowed size.'], 413);
        }
        return $body;
    }

    /** @return array<string,mixed> */
    public static function decodeObject(string $body): array
    {
        try {
            $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::respond(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
        }
        if (!is_array($payload)) {
            self::respond(['ok' => false, 'error' => 'JSON payload must be an object.'], 400);
        }
        return $payload;
    }

    /** @param array<string,mixed>|null $server */
    public static function requestPath(?array $server = null): string
    {
        $server ??= $_SERVER;
        $uri = (string)($server['REQUEST_URI'] ?? '/');
        $position = strpos($uri, '?');
        return $position === false ? $uri : substr($uri, 0, $position);
    }

    /** @param array<string,mixed> $data */
    public static function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
