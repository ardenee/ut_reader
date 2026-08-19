<?php
/** Apply an administrator action to every System Error matching the current filters. */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogAdminMatchingBulkActionService;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('system_errors');

    $body = catalog_api_json_body();
    $userId = max(0, (int)($_SESSION['user']['id'] ?? 0));
    $action = strtolower(trim((string)($body['action'] ?? '')));
    $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
    $note = (string)($body['resolution_note'] ?? '');

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $result = (new CatalogAdminMatchingBulkActionService($application->db))->systemErrors(
        $action,
        $filters,
        $userId > 0 ? $userId : null,
        $note
    );
    $result['message'] = number_format($result['affected']) . ' of '
        . number_format($result['matched']) . ' matching System Error record(s) changed.';
    JsonResponse::send(['data' => $result]);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_request', $error->getMessage(), 400);
} catch (Throwable $error) {
    error_log('[UnrealDB system error bulk API] ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
