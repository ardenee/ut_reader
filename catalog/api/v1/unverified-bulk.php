<?php
/** Queue one durable action over every unverified file matching the current page filters. */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedBulkSelectionQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('unverified-files');

    $body = catalog_api_json_body();
    $action = strtolower(trim((string)($body['action'] ?? '')));
    if (!in_array($action, ['import', 'move', 'delete'], true)) {
        JsonResponse::error('invalid_action', 'Choose import, move or delete.', 400);
    }
    $targetGameId = (int)($body['target_game_id'] ?? 0);
    if ($action === 'move' && $targetGameId < 1) {
        JsonResponse::error('invalid_target', 'Choose one target game before moving all matching files.', 400);
    }
    if ($action === 'import' && $targetGameId < 1 && $targetGameId !== -1) {
        JsonResponse::error('invalid_target', 'Choose a target game or All exact compatible games.', 400);
    }

    $userId = max(0, (int)($_SESSION['user']['id'] ?? 0));
    $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
    $selector = new PdoUnverifiedBulkSelectionQuery($application->db);
    $snapshot = $selector->snapshot($filters);
    if ($snapshot['total'] < 1) {
        JsonResponse::error('no_matches', 'No unverified files match the current filters.', 409);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $queueName = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $jobId = (new PdoJobQueue($application->db))->enqueue(
        $queueName,
        JobType::UNVERIFIED_BULK_ACTION,
        [
            'action' => $action,
            'target_game_id' => $targetGameId,
            'allow_profile_override' => !empty($body['allow_profile_override']),
            'requested_by' => $userId,
            'filters' => $snapshot['filters'],
            'snapshot_total' => $snapshot['total'],
            'snapshot_max_id' => $snapshot['max_id'],
        ],
        15,
        null,
        null,
        $userId > 0 ? $userId : null,
        3
    );

    $workerState = (new CatalogQueueWorkerStarter($application->db, $application->config))
        ->start($queueName, true, $userId > 0 ? $userId : null);

    JsonResponse::send(['data' => [
        'queued' => true,
        'job_id' => $jobId,
        'queue' => $queueName,
        'matching_total' => $snapshot['total'],
        'worker' => $workerState['worker'] ?? null,
        'worker_error' => (string)($workerState['worker_error'] ?? ''),
        'message' => ucfirst($action) . ' queued for all ' . number_format($snapshot['total'])
            . ' matching unverified file(s) as background job #' . $jobId . '.',
    ]]);
} catch (Throwable $error) {
    error_log('[UnrealDB unverified bulk API] ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
