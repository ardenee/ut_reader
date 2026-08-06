<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCleanup;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $scope = strtolower(trim((string)($payload['scope'] ?? 'selected')));
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    $status = strtolower(trim((string)($payload['status'] ?? '')));
    $search = trim((string)($payload['search'] ?? ''));
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (!in_array($action, ['restart', 'cancel', 'delete'], true)) {
        JsonResponse::error('invalid_action', 'Choose restart, cancel or delete.', 400);
    }
    if (!in_array($scope, ['selected', 'matching'], true)) {
        JsonResponse::error('invalid_scope', 'Choose selected jobs or all matching jobs.', 400);
    }
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $jobIds = [];
    if ($scope === 'selected') {
        $rawIds = $payload['job_ids'] ?? [];
        if (!is_array($rawIds)) {
            JsonResponse::error('invalid_jobs', 'Select at least one job.', 400);
        }
        foreach ($rawIds as $rawId) {
            $jobId = (int)$rawId;
            if ($jobId > 0) {
                $jobIds[$jobId] = $jobId;
            }
        }
        $jobIds = array_values($jobIds);
        if ($jobIds === []) {
            JsonResponse::error('invalid_jobs', 'Select at least one job.', 400);
        }
        if (count($jobIds) > 10000) {
            JsonResponse::error('too_many_jobs', 'No more than 10,000 selected jobs can be changed at once.', 400);
        }
    }

    // Release the administrator session before any database work. A slow or
    // blocked bulk action must not freeze every other request from this browser.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Fail a lock wait promptly instead of leaving the page spinning forever.
    try {
        $application->db->exec('SET SESSION innodb_lock_wait_timeout=5');
    } catch (Throwable) {
        // Some compatible servers may not expose this session setting.
    }

    $where = ['queue_name=?'];
    $params = [$queueName];
    if ($scope === 'selected') {
        $where[] = 'id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')';
        array_push($params, ...$jobIds);
    }
    if ($status !== '') {
        $condition = CatalogJobDisplayStatus::filterCondition($status);
        $where[] = $condition['sql'];
        array_push($params, ...$condition['params']);
    }
    if ($search !== '') {
        $where[] = '(CAST(id AS CHAR) LIKE ? OR job_type LIKE ? OR COALESCE(concurrency_key,"") LIKE ? '
            . 'OR COALESCE(payload_json,"") LIKE ? OR COALESCE(last_error,"") LIKE ? '
            . 'OR COALESCE(result_json,"") LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $displayStatus = CatalogJobDisplayStatus::sqlExpression();
    $actionCondition = match ($action) {
        // Older handlers returned a failed result normally, which left the queue
        // row as completed. Treat those visible failed outcomes as retryable too.
        'restart' => '(status IN ("cancelled","failed","dead_letter") '
            . 'OR (status="completed" AND ' . $displayStatus . ' IN ("failed","rejected","unverified")))',
        'cancel' => 'status="queued"',
        'delete' => 'status IN ("completed","failed","dead_letter","cancelled")',
    };
    $where[] = $actionCondition;
    $whereSql = implode(' AND ', $where);

    $requested = catalog_count(
        $application->db,
        'SELECT COUNT(*) c FROM ue_background_jobs WHERE ' . $whereSql,
        $params
    );

    // Never perform an unbounded UPDATE from an HTTP request. Large matching
    // sets are handled in repeatable 10,000-row batches and the UI already tells
    // the administrator to apply again for any remainder.
    $limit = 10000;
    $select = $application->db->prepare(
        'SELECT id FROM ue_background_jobs WHERE ' . $whereSql . ' ORDER BY id ASC LIMIT ' . $limit
    );
    $select->execute($params);
    $eligibleIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));

    $affected = 0;
    $deletedStagedFiles = 0;
    $limited = $requested > count($eligibleIds);
    $now = gmdate('Y-m-d H:i:s');

    if ($action === 'restart' && $eligibleIds !== []) {
        $idSql = implode(',', array_fill(0, count($eligibleIds), '?'));
        $statement = $application->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=NULL,result_json=NULL,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,progress_json=NULL,'
            . 'progress_updated_at=NULL,dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND ' . $actionCondition
        );
        $statement->execute(array_merge([$now, $now, $queueName], $eligibleIds));
        $affected = $statement->rowCount();
    } elseif ($action === 'cancel' && $eligibleIds !== []) {
        $reason = 'Cancelled in bulk from Background Jobs.';
        $idSql = implode(',', array_fill(0, count($eligibleIds), '?'));
        $statement = $application->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,cancel_requested_at=?,'
            . 'cancel_requested_by=?,cancel_reason=?,completed_at=?,updated_at=? '
            . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND status="queued"'
        );
        $statement->execute(array_merge([$now, $userId, $reason, $now, $now, $queueName], $eligibleIds));
        $affected = $statement->rowCount();
    } elseif ($action === 'delete' && $eligibleIds !== []) {
        $result = (new CatalogBackgroundJobCleanup($application->db, $application->config))
            ->deleteTerminalJobs($eligibleIds, $queueName);
        $affected = (int)($result['deleted_jobs'] ?? 0);
        $deletedStagedFiles = (int)($result['deleted_staged_files'] ?? 0);
    }

    JsonResponse::send([
        'data' => [
            'action' => $action,
            'scope' => $scope,
            'queue' => $queueName,
            'requested' => $requested,
            'affected' => $affected,
            'skipped' => max(0, min($requested, count($eligibleIds)) - $affected),
            'deleted_staged_files' => $deletedStagedFiles,
            'limited' => $limited,
            'batch_limit' => $limit,
            'worker' => null,
            'worker_error' => '',
            'worker_start_required' => $action === 'restart' && $affected > 0,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB job bulk API] ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
