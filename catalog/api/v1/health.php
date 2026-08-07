<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for health.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    $application->db->query('SELECT 1')->fetchColumn();

    JsonResponse::data([
        'status' => 'ok',
        'service' => 'unrealdb-catalog',
        'time' => gmdate('c'),
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB health] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'Catalog service is temporarily unavailable.', 503);
}
