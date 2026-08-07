<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for bootstrap.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/CatalogMfa.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

function catalog_api_application()
{
    return catalog_bootstrap();
}

/**
 * Authenticated GET requests only need the session long enough to verify the
 * administrator. Release PHP's session-file lock immediately afterwards so a
 * slow status/report query cannot block navigation or another AJAX request in
 * the same browser session.
 */
function catalog_api_release_read_session(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function catalog_api_require_admin(bool $requireRecentAuthentication = true): void
{
    if (!catalog_support_is_admin()) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($requireRecentAuthentication
        && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
        && !catalog_has_recent_admin_auth()) {
        JsonResponse::error(
            'reauthentication_required',
            'Recent administrator authentication is required. Confirm your password and MFA code on admin-security.php.',
            401,
            ['reauthentication_url' => '../../admin-security.php']
        );
    }

    catalog_api_release_read_session();
}

function catalog_api_require_csrf(string $scope): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = catalog_csrf($scope);
    if ($provided === '' || !hash_equals($expected, $provided)) {
        JsonResponse::error('csrf_invalid', 'A valid X-CSRF-Token header is required.', 403);
    }
}

function catalog_api_max_json_bytes(): int
{
    $configured = (int)(getenv('UNREALDB_API_MAX_JSON_BYTES') ?: 0);
    return max(1024, min($configured > 0 ? $configured : 1024 * 1024, 16 * 1024 * 1024));
}

function catalog_api_raw_body(): string
{
    $limit = catalog_api_max_json_bytes();
    $declaredLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($declaredLength !== false && $declaredLength !== null && (int)$declaredLength > $limit) {
        JsonResponse::error('payload_too_large', 'Request body exceeds the allowed size.', 413);
    }
    $stream = fopen('php://input', 'rb');
    if (!is_resource($stream)) {
        JsonResponse::error('body_unavailable', 'Request body could not be read.', 400);
    }
    try {
        $raw = stream_get_contents($stream, $limit + 1);
    } finally {
        fclose($stream);
    }
    if (!is_string($raw)) {
        JsonResponse::error('body_unavailable', 'Request body could not be read.', 400);
    }
    if (strlen($raw) > $limit) {
        JsonResponse::error('payload_too_large', 'Request body exceeds the allowed size.', 413);
    }
    return $raw;
}

/** @return array<string,mixed> */
function catalog_api_json_body(): array
{
    $raw = catalog_api_raw_body();
    if (trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        JsonResponse::error('invalid_json', 'Request body must be a JSON object.', 400);
    }
    if (!is_array($decoded)) {
        JsonResponse::error('invalid_json', 'Request body must be a JSON object.', 400);
    }
    return $decoded;
}
