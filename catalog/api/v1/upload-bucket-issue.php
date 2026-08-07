<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for upload bucket issue.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketIssueStore;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('upload_bucket_chunk');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? 'record')));
    if ($action !== 'record') {
        JsonResponse::error('invalid_action', 'Only Upload Issue record actions are accepted here.', 400);
    }

    $store = new CatalogUploadBucketIssueStore($application->db);
    $issueId = $store->record($payload, $userId);
    JsonResponse::send([
        'ok' => true,
        'data' => [
            'issue_id' => $issueId,
            'status' => 'open',
        ],
    ], 200);
} catch (Throwable $error) {
    $reference = catalog_request_id();
    $message = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
    error_log('[UnrealDB][' . $reference . '] upload issue persistence failed: ' . get_class($error) . ': ' . $message);
    JsonResponse::error(
        'upload_issue_persistence_failed',
        $message,
        500,
        ['request_id' => $reference]
    );
}
