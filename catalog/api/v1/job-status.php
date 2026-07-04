<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $limit = max(1, min((int)($_GET['limit'] ?? 50), 200));
    $rows = catalog_all(
        $application->db,
        'SELECT id, queue_name, job_type, priority, status, available_at, attempts, max_attempts, worker_id, lease_expires_at, last_error, created_by, created_at, updated_at, completed_at FROM ue_background_jobs ORDER BY id DESC LIMIT ' . $limit
    );

    JsonResponse::send(['data' => ['jobs' => $rows], 'meta' => ['limit' => $limit]]);
} catch (Throwable $exception) {
    error_log('[UnrealDB job status API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
