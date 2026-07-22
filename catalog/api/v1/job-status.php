<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobEventLog;
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
    $eventOffset = max(0, (int)($_GET['event_offset'] ?? 0));
    $eventLimit = max(1, min((int)($_GET['event_limit'] ?? 250), 1000));
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

    $resultColumns = $jobId > 0
        ? ',result_json'
        : ',JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")) result_status,'
            . 'JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.message")) result_message';
    $sql = 'SELECT id,queue_name,job_type,resource_class,resource_limit,concurrency_key,priority,status,available_at,'
        . 'attempts,max_attempts,worker_id,leased_at,lease_expires_at,last_heartbeat_at,recovery_count,'
        . 'cancel_requested_at,cancel_requested_by,cancel_reason,payload_json,progress_json,progress_updated_at'
        . $resultColumns
        . ',last_error,created_by,created_at,updated_at,completed_at,dead_lettered_at '
        . 'FROM ue_background_jobs'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY id DESC LIMIT ' . $limit;
    $rows = catalog_all($application->db, $sql, $params);
    foreach ($rows as &$row) {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string)$row['payload_json'], true);
            if (is_array($decoded)) {
                foreach (['original_name', 'source_relative_path', 'game_id', 'file_id', 'max_files'] as $field) {
                    if (array_key_exists($field, $decoded)) {
                        $payload[$field] = $decoded[$field];
                    }
                }
            }
        }
        unset($row['payload_json']);
        $row['payload'] = $payload;

        $progress = null;
        if (!empty($row['progress_json'])) {
            $decoded = json_decode((string)$row['progress_json'], true);
            $progress = is_array($decoded) ? $decoded : null;
        }
        unset($row['progress_json']);
        $row['progress'] = $progress;

        if ($jobId > 0) {
            $result = null;
            if (!empty($row['result_json'])) {
                $decoded = json_decode((string)$row['result_json'], true);
                $result = is_array($decoded) ? $decoded : null;
            }
            unset($row['result_json']);
            $row['result'] = $result;
        } else {
            $resultStatus = trim((string)($row['result_status'] ?? ''));
            $resultMessage = trim((string)($row['result_message'] ?? ''));
            unset($row['result_status'], $row['result_message']);
            $row['result'] = $resultStatus !== '' || $resultMessage !== ''
                ? ['status' => $resultStatus, 'message' => $resultMessage]
                : null;
        }
    }
    unset($row);

    $eventState = ['events' => [], 'offset' => $eventOffset, 'has_more' => false];
    if ($jobId > 0 && $rows !== []) {
        try {
            $eventState = (new CatalogJobEventLog($application->config))
                ->readFrom($jobId, $eventOffset, $eventLimit);
        } catch (Throwable $eventError) {
            error_log('[UnrealDB job events][' . catalog_request_id() . '] ' . $eventError->getMessage());
        }
    }

    JsonResponse::send([
        'data' => [
            'jobs' => $rows,
            'events' => $eventState['events'],
        ],
        'meta' => [
            'limit' => $limit,
            'job_id' => $jobId > 0 ? $jobId : null,
            'queue' => $queue !== '' ? $queue : null,
            'status' => $status !== '' ? $status : null,
            'event_offset' => (int)$eventState['offset'],
            'events_has_more' => (bool)$eventState['has_more'],
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB job status API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
