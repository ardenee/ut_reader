<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job status cursor.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobPageQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @param array<string,mixed> $result */
function catalog_job_cursor_names_match(
    string $jobType,
    string $expectedName,
    string $resultName,
    array $result
): bool {
    if ($expectedName === '' || $resultName === '' || strcasecmp($expectedName, $resultName) === 0) {
        return true;
    }
    if ($jobType !== JobType::PROCESS_BUCKET_UPLOAD || trim((string)($result['decoder'] ?? '')) === '') {
        return false;
    }
    $extension = strtolower((string)pathinfo($expectedName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['uz', 'uz2', 'uz3'], true)) {
        return false;
    }
    $packageName = substr($expectedName, 0, -strlen('.' . $extension));
    return is_string($packageName) && $packageName !== '' && strcasecmp($packageName, $resultName) === 0;
}

/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function catalog_job_cursor_hydrate_rows(array $rows, array $config): array
{
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
            $nameMismatch = !catalog_job_cursor_names_match($jobType, $expectedName, $resultName, $result);

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
                $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
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
    return $rows;
}

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $queue = trim((string)($_GET['queue'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $search = trim((string)($_GET['search'] ?? ''));
    $requestedPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min((int)($_GET['per_page'] ?? $_GET['limit'] ?? 100), 1000));
    $move = strtolower(trim((string)($_GET['move'] ?? 'first')));
    if ($move === 'prev') {
        $move = 'previous';
    }
    if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
        $move = 'first';
    }

    if ($queue !== '' && (strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1)) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $fromSql = 'ue_background_jobs j';
    $baseWhere = [];
    $baseParams = [];
    if ($queue !== '') {
        $baseWhere[] = 'j.queue_name=?';
        $baseParams[] = $queue;
    }
    if ($search !== '') {
        $projectionAvailable = catalog_performance_sync_job_search($application->db);
        $booleanSearch = catalog_performance_boolean_query($search);
        if ($projectionAvailable && $booleanSearch !== '') {
            $fromSql .= ' JOIN ue_background_job_search js ON js.job_id=j.id';
            if (ctype_digit($search)) {
                $baseWhere[] = '(j.id=? OR MATCH(js.search_text) AGAINST (? IN BOOLEAN MODE))';
                $baseParams[] = (int)$search;
                $baseParams[] = $booleanSearch;
            } else {
                $baseWhere[] = 'MATCH(js.search_text) AGAINST (? IN BOOLEAN MODE)';
                $baseParams[] = $booleanSearch;
            }
        } elseif ($projectionAvailable) {
            $fromSql .= ' JOIN ue_background_job_search js ON js.job_id=j.id';
            $baseWhere[] = 'js.search_text LIKE ?';
            $baseParams[] = '%' . $search . '%';
        } else {
            $baseWhere[] = '(CAST(j.id AS CHAR) LIKE ? OR j.job_type LIKE ? OR COALESCE(j.concurrency_key,"") LIKE ? '
                . 'OR COALESCE(j.payload_json,"") LIKE ? OR COALESCE(j.last_error,"") LIKE ? '
                . 'OR COALESCE(j.result_json,"") LIKE ?)';
            $like = '%' . $search . '%';
            array_push($baseParams, $like, $like, $like, $like, $like, $like);
        }
    }

    $where = $baseWhere;
    $params = $baseParams;
    if ($status !== '') {
        $condition = CatalogJobDisplayStatus::filterCondition($status);
        $where[] = $condition['sql'];
        array_push($params, ...$condition['params']);
    }
    $whereSql = implode(' AND ', $where);
    $baseWhereSql = implode(' AND ', $baseWhere);

    $totalSql = 'SELECT COUNT(*) c FROM ' . $fromSql . ($whereSql !== '' ? ' WHERE ' . $whereSql : '');
    $total = catalog_count($application->db, $totalSql, $params);
    $pages = max(1, (int)ceil($total / max(1, $perPage)));

    $counts = [
        'all' => 0,
        'queued' => 0,
        'running' => 0,
        'completed' => 0,
        'failed' => 0,
        'dead_letter' => 0,
        'cancelled' => 0,
    ];
    $countSql = 'SELECT j.status,JSON_UNQUOTE(JSON_EXTRACT(j.result_json,"$.status")) result_status,COUNT(*) total '
        . 'FROM ' . $fromSql . ($baseWhereSql !== '' ? ' WHERE ' . $baseWhereSql : '')
        . ' GROUP BY j.status,result_status';
    foreach (catalog_all($application->db, $countSql, $baseParams) as $countRow) {
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

    $context = json_encode([
        'page' => 'background-jobs',
        'queue' => $queue,
        'status' => $status,
        'search' => $search,
        'limit' => $perPage,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $cursorToken = trim((string)($_GET['cursor'] ?? ''));
    $cursor = $cursorToken !== '' ? CatalogKeysetPaginator::decode($application->config, $context, $cursorToken) : null;
    if ($cursorToken !== '' && $cursor === null) {
        $move = 'first';
        $requestedPage = 1;
    }
    if ($move === 'first') {
        $requestedPage = 1;
    } elseif ($move === 'last') {
        $requestedPage = $pages;
    } else {
        $requestedPage = max(1, min($pages, $requestedPage));
    }

    $displayStatusSql = CatalogJobDisplayStatus::sqlExpression();
    $selectSql = 'SELECT j.id,j.queue_name,j.job_type,j.resource_class,j.resource_limit,j.concurrency_key,j.priority,j.status,'
        . $displayStatusSql . ' display_status,j.available_at,'
        . 'j.attempts,j.max_attempts,j.worker_id,j.leased_at,j.lease_expires_at,j.last_heartbeat_at,j.recovery_count,'
        . 'j.cancel_requested_at,j.cancel_requested_by,j.cancel_reason,j.payload_json,j.progress_json,j.progress_updated_at,'
        . 'j.result_json,j.last_error,j.created_by,j.created_at,j.updated_at,j.completed_at,j.dead_lettered_at '
        . 'FROM ' . $fromSql;
    $jobPageQuery = new PdoBackgroundJobPageQuery($application->db);
    $pageResult = $jobPageQuery->fetch(
        $selectSql,
        $whereSql,
        $params,
        $perPage,
        $cursor,
        $move
    );
    if ($pageResult['rows'] === [] && $total > 0 && $move !== 'first') {
        $move = 'first';
        $requestedPage = 1;
        $pageResult = $jobPageQuery->fetch(
            $selectSql,
            $whereSql,
            $params,
            $perPage,
            null,
            'first'
        );
    }

    $rows = catalog_job_cursor_hydrate_rows($pageResult['rows'], $application->config);
    $previousCursor = is_array($pageResult['first_cursor'])
        ? CatalogKeysetPaginator::encode($application->config, $context, $pageResult['first_cursor'])
        : '';
    $nextCursor = is_array($pageResult['last_cursor'])
        ? CatalogKeysetPaginator::encode($application->config, $context, $pageResult['last_cursor'])
        : '';

    JsonResponse::send([
        'data' => ['jobs' => $rows, 'events' => []],
        'meta' => [
            'limit' => $perPage,
            'page' => $requestedPage,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'counts' => $counts,
            'queue' => $queue !== '' ? $queue : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'move' => $move,
            'has_previous' => $requestedPage > 1 && (bool)$pageResult['has_previous'],
            'has_next' => $requestedPage < $pages && (bool)$pageResult['has_next'],
            'previous_cursor' => $previousCursor,
            'next_cursor' => $nextCursor,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB cursor job status API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
