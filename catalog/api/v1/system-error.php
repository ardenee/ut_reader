<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    if ((string)($_SERVER['HTTP_X_UNREALDB_ERROR_REPORT'] ?? '') !== '1') {
        JsonResponse::error('error_report_header_required', 'The browser error-report header is required.', 403);
    }

    $payload = catalog_api_json_body();
    $message = trim((string)($payload['message'] ?? ''));
    if ($message === '') {
        JsonResponse::error('error_message_required', 'A browser error message is required.', 400);
    }

    catalog_system_error_record([
        'source_kind' => 'browser',
        'severity' => (string)($payload['severity'] ?? 'error'),
        'error_type' => (string)($payload['error_type'] ?? 'javascript_error'),
        'message' => $message,
        'route' => (string)($payload['route'] ?? ''),
        'http_status' => max(0, min(599, (int)($payload['http_status'] ?? 0))),
        'source_file' => (string)($payload['source_file'] ?? ''),
        'source_line' => (int)($payload['source_line'] ?? 0),
        'trace_text' => (string)($payload['trace_text'] ?? ''),
        'context' => [
            'column' => max(0, (int)($payload['source_column'] ?? 0)),
            'page_title' => (string)($payload['page_title'] ?? ''),
        ],
    ]);

    JsonResponse::send(['ok' => true], 200);
} catch (Throwable $error) {
    $reference = catalog_request_id();
    error_log('[UnrealDB][' . $reference . '] browser error persistence failed: '
        . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('browser_error_persistence_failed', 'The browser error could not be stored.', 500, [
        'request_id' => $reference,
    ]);
}
