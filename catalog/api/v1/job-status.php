<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job status.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobEventLog;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @param array<string,mixed> $result */
function catalog_job_status_names_match(
    string $jobType,
    string $expectedName,
    string $resultName,
    array $result
): bool {
    if ($expectedName === '' || $resultName === '' || strcasecmp($expectedName, $resultName) === 0) {
        return true;
    }

    // Upload Bucket redirect jobs legitimately store the decompressed package
    // name while their payload retains the original .uz/.uz2/.uz3 wrapper name.
    // Accept only that exact suffix removal and only when a decoder was used.
    if ($jobType !== JobType::PROCESS_BUCKET_UPLOAD
        || trim((string)($result['decoder'] ?? '')) === '') {
        return false;
    }

    $extension = strtolower((string)pathinfo($expectedName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['uz', 'uz2', 'uz3'], true)) {
        return false;
    }

    $packageName = substr($expectedName, 0, -strlen('.' . $extension));
    return is_string($packageName)
        && $packageName !== ''
        && strcasecmp($packageName, $resultName) === 0;
}

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
    $sql = 'SELECT id,queue_name,job_type,resource_class,resource_limit,concurrency_key,priority,status,'
        . $displayStatusSql . ' display_status,available_at,'
        . 'attempts,max_attempts,worker_id,leased_at,lease_expires_at,last_heartbeat_at,recovery_count,'
        . 'cancel_requested_at,cancel_requested_by,cancel_reason,payload_json,progress_json,progress_updated_at,'
        . 'result_json,last_error,created_by,created_at,updated_at,completed_at,dead_lettered_at '
        . 'FROM ue_background_jobs' . $whereSql
        . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $rows = catalog_all($application->db, $sql, $params);

    foreach ($rows as &$row) {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string)$row['payload_json'], true);
            if (is_array($decoded)) {
                foreach ([
                    'original_name',
                    'source_relative_path',
                    'queue_name',
                    'queue_game_id',
                    'game_id',
                    'file_id',
                    'expected_size',
                    'max_files',
                ] as $field) {
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

        $result = null;
        if (!empty($row['result_json'])) {
            $decoded = json_decode((string)$row['result_json'], true);
            $result = is_array($decoded) ? $decoded : null;
        }
        unset($row['result_json']);

        if (is_array($result)) {
            $expectedJobId = (int)$row['id'];
            $resultJobId = (int)($result['job_id'] ?? 0);
            $jobType = (string)($row['job_type'] ?? '');
            $expectedName = trim((string)($payload['original_name'] ?? ''));
            $resultName = trim((string)($result['original_name'] ?? $result['job_original_name'] ?? ''));
            $jobMismatch = $resultJobId > 0 && $resultJobId !== $expectedJobId;
            $nameMismatch = !catalog_job_status_names_match($jobType, $expectedName, $resultName, $result);

            if ($jobMismatch || $nameMismatch) {
                $details = [];
                if ($jobMismatch) {
                    $details[] = 'result belongs to job #' . $resultJobId;
                }
                if ($nameMismatch) {
                    $details[] = 'result names ' . $resultName . ' instead of ' . $expectedName;
                }
                $retryInstruction = $jobType === JobType::REPAIR_UNVERIFIED_METADATA
                    ? 'Re-run this metadata repair.'
                    : 'Restart this job.';
                $result = [
                    'status' => 'failed',
                    'message' => 'Stored result identity mismatch for job #' . $expectedJobId . ': '
                        . implode('; ', $details) . '. ' . $retryInstruction,
                    'integrity_mismatch' => true,
                    'job_id' => $expectedJobId,
                    'job_original_name' => $expectedName,
                ];
            } elseif ($jobType === JobType::REPAIR_UNVERIFIED_METADATA) {
                $displayName = $expectedName !== '' ? $expectedName : ($resultName !== '' ? $resultName : 'this file');
                $parseError = trim((string)($result['parse_error'] ?? ''));
                if ($parseError !== '') {
                    $result['message'] = 'Basic metadata was repaired for ' . $displayName
                        . ', but package tables remain unreadable: ' . $parseError;
                } elseif (array_key_exists('name_count', $result)
                    && array_key_exists('import_count', $result)
                    && array_key_exists('export_count', $result)) {
                    $result['message'] = 'Metadata repair completed for ' . $displayName . ': Header, '
                        . (int)$result['name_count'] . ' Names, '
                        . (int)$result['import_count'] . ' Imports and '
                        . (int)$result['export_count'] . ' Exports recorded.';
                }
            } elseif ($jobType === JobType::PROCESS_BUCKET_UPLOAD
                && strtolower(trim((string)($result['status'] ?? ''))) === 'bucketed') {
                $queueFile = basename((string)($result['queue_name'] ?? ''));
                $storageRoot = rtrim((string)($application->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
                if ($queueFile !== '' && $storageRoot !== '') {
                    $physicalPath = $storageRoot . DIRECTORY_SEPARATOR . 'upload-bucket' . DIRECTORY_SEPARATOR . $queueFile;
                    $physicalExists = is_file($physicalPath);
                    $result['physical_path'] = $physicalPath;
                    $result['physical_exists'] = $physicalExists;
                    if (!is_array($progress)) {
                        $progress = [];
                    }
                    $currentMessage = trim((string)($progress['message'] ?? $result['message'] ?? 'Upload Bucket processing completed.'));
                    $pathMessage = $physicalExists
                        ? 'Stored at: ' . $physicalPath
                        : 'Expected physical file is missing: ' . $physicalPath;
                    if (!str_contains($currentMessage, $physicalPath)) {
                        $progress['message'] = rtrim($currentMessage, " \t\n\r\0\x0B.") . '. ' . $pathMessage;
                    }
                }
            }

            $resultStatus = strtolower(trim((string)($result['status'] ?? '')));
            $successfulCompletion = (string)($row['status'] ?? '') === 'completed'
                && empty($result['integrity_mismatch'])
                && trim((string)($result['parse_error'] ?? '')) === ''
                && !in_array($resultStatus, ['failed', 'rejected', 'unverified', 'error'], true);
            if ($successfulCompletion) {
                $completionMessage = trim((string)($result['message'] ?? ''));
                if ($completionMessage !== '') {
                    if (!is_array($progress)) {
                        $progress = [];
                    }
                    if (trim((string)($progress['message'] ?? '')) === '') {
                        $progress['message'] = $completionMessage;
                    }
                }
            }

            // A completed warning/result may already be the full-width status
            // message. Do not render the same text again as Error/result. Real
            // failed jobs, cancellations and identity mismatches remain separate.
            if ((string)($row['status'] ?? '') === 'completed' && empty($result['integrity_mismatch'])) {
                $resultMessage = trim((string)($result['message'] ?? ''));
                $progressMessage = is_array($progress) ? trim((string)($progress['message'] ?? '')) : '';
                $normalizedResult = preg_replace('/\s+/', ' ', $resultMessage) ?? $resultMessage;
                $normalizedProgress = preg_replace('/\s+/', ' ', $progressMessage) ?? $progressMessage;
                if ($resultMessage !== '' && $progressMessage !== '' && $normalizedResult === $normalizedProgress) {
                    unset($result['message']);
                } elseif ($successfulCompletion) {
                    unset($result['message']);
                }
            }
        }

        $row['progress'] = $progress;
        $row['result'] = $result;
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
            'limit' => $perPage,
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
