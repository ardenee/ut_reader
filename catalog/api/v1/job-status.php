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

    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    $limit = $jobId > 0 ? 1 : max(1, min((int)($_GET['limit'] ?? 50), 200));
    $queue = trim((string)($_GET['queue'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $allowedStatuses = ['queued', 'running', 'completed', 'failed', 'dead_letter', 'cancelled'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }

    $where = [];
    $params = [];
    if ($jobId > 0) {
        $where[] = 'id=?';
        $params[] = $jobId;
    }
    if ($queue !== '') {
        if (strlen($queue) > 80) {
            JsonResponse::error('invalid_queue', 'Queue name is too long.', 400);
        }
        $where[] = 'queue_name=?';
        $params[] = $queue;
    }
    if ($status !== '') {
        $where[] = 'status=?';
        $params[] = $status;
    }

    $sql = 'SELECT id,queue_name,job_type,resource_class,resource_limit,concurrency_key,priority,status,available_at,'
        . 'attempts,max_attempts,worker_id,leased_at,lease_expires_at,last_heartbeat_at,recovery_count,'
        . 'cancel_requested_at,cancel_requested_by,cancel_reason,progress_json,progress_updated_at,result_json,last_error,'
        . 'created_by,created_at,updated_at,completed_at,dead_lettered_at '
        . 'FROM ue_background_jobs'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY id DESC LIMIT ' . $limit;
    $rows = catalog_all($application->db, $sql, $params);
    foreach ($rows as &$row) {
        foreach (['progress_json' => 'progress', 'result_json' => 'result'] as $jsonField => $outputField) {
            $decodedValue = null;
            if (!empty($row[$jsonField])) {
                $decoded = json_decode((string)$row[$jsonField], true);
                $decodedValue = is_array($decoded) ? $decoded : null;
            }
            unset($row[$jsonField]);
            $row[$outputField] = $decodedValue;
        }
    }
    unset($row);

    JsonResponse::send([
        'data' => ['jobs' => $rows],
        'meta' => [
            'limit' => $limit,
            'job_id' => $jobId > 0 ? $jobId : null,
            'queue' => $queue !== '' ? $queue : null,
            'status' => $status !== '' ? $status : null,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB job status API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
