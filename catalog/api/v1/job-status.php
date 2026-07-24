<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobEventLog;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    $queue = trim((string)($_GET['queue'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $search = trim((string)($_GET['search'] ?? ''));
    $page = $jobId > 0 ? 1 : max(1, (int)($_GET['page'] ?? 1));
    $perPage = $jobId > 0
        ? 1
        : max(1, min((int)($_GET['per_page'] ?? $_GET['limit'] ?? 100), 1000));
    $eventOffset = max(0, (int)($_GET['event_offset'] ?? 0));
    $eventLimit = max(1, min((int)($_GET['event_limit'] ?? 250), 1000));

    if ($queue !== '' && (strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1)) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $baseWhere = [];
    $baseParams = [];
    if ($jobId > 0) {
        $baseWhere[] = 'id=?';
        $baseParams[] = $jobId;
    }
    if ($queue !== '') {
        $baseWhere[] = 'queue_name=?';
        $baseParams[] = $queue;
    }
    if ($search !== '') {
        $baseWhere[] = '(CAST(id AS CHAR) LIKE ? OR job_type LIKE ? OR COALESCE(concurrency_key,"") LIKE ? '
            . 'OR COALESCE(payload_json,"") LIKE ? OR COALESCE(last_error,"") LIKE ? '
            . 'OR COALESCE(result_json,"") LIKE ?)';
        $like = '%' . $search . '%';
        array_push($baseParams, $like, $like, $like, $like, $like, $like);
    }

    $where = $baseWhere;
    $params = $baseParams;
    if ($status !== '') {
        $condition = CatalogJobDisplayStatus::filterCondition($status);
        $where[] = $condition['sql'];
        array_push($params, ...$condition['params']);
    }

    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $baseWhereSql = $baseWhere !== [] ? ' WHERE ' . implode(' AND ', $baseWhere) : '';

    $total = catalog_count(
        $application->db,
        'SELECT COUNT(*) c FROM ue_background_jobs' . $whereSql,
        $params
    );
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $counts = [
        'all' => 0,
        'queued' => 0,
        'running' => 0,
        'completed' => 0,
        'failed' => 0,
        'dead_letter' => 0,
        'cancelled' => 0,
    ];
    foreach (catalog_all(
        $application->db,
        'SELECT status,JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")) result_status,COUNT(*) total '
            . 'FROM ue_background_jobs' . $baseWhereSql . ' GROUP BY status,result_status',
        $baseParams
    ) as $countRow) {
        $amount = (int)($countRow['total'] ?? 0);
        $counts['all'] += $amount;
        $group = CatalogJobDisplayStatus::group(
            (string)($countRow['status'] ?? ''),
            isset($countRow['result_status']) ? (string)$countRow['result_status'] : null
        );
        if (array_key_exists($group, $counts)) {
            $counts[$group] += $amount;
        }
    }

    $displayStatusSql = CatalogJobDisplayStatus::sqlExpression();
    $resultColumns = $jobId > 0
        ? ',result_json'
        : ',JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")) result_status,'
            . 'JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.message")) result_message';
    $sql = 'SELECT id,queue_name,job_type,resource_class,resource_limit,concurrency_key,priority,status,'
        . $displayStatusSql . ' display_status,available_at,'
        . 'attempts,max_attempts,worker_id,leased_at,lease_expires_at,last_heartbeat_at,recovery_count,'
        . 'cancel_requested_at,cancel_requested_by,cancel_reason,payload_json,progress_json,progress_updated_at'
        . $resultColumns
        . ',last_error,created_by,created_at,updated_at,completed_at,dead_lettered_at '
        . 'FROM ue_background_jobs' . $whereSql
        . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
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
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'counts' => $counts,
            'job_id' => $jobId > 0 ? $jobId : null,
            'queue' => $queue !== '' ? $queue : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'event_offset' => (int)$eventState['offset'],
            'events_has_more' => (bool)$eventState['has_more'],
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB job status API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
