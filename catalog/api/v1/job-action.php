<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $queue = new PdoJobQueue($application->db);

    if ($action === 'cancel') {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId < 1) {
            JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
        }
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $status = $queue->requestCancellation($jobId, $userId, (string)($payload['reason'] ?? 'Cancelled by administrator.'));
        if ($status === 'not_found') {
            JsonResponse::error('not_found', 'The requested job was not found.', 404);
        }
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => $status]]);
    }

    if ($action === 'retry') {
        $jobId = (int)($payload['job_id'] ?? 0);
        if ($jobId < 1) {
            JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
        }
        if (!$queue->retryDeadLetter($jobId)) {
            JsonResponse::error('not_retryable', 'The job is not in a retryable terminal state.', 409);
        }
        JsonResponse::send(['data' => ['job_id' => $jobId, 'status' => 'queued']]);
    }

    if ($action === 'recover') {
        $queueName = trim((string)($payload['queue'] ?? 'catalog'));
        if ($queueName === '' || strlen($queueName) > 80) {
            JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
        }
        $result = $queue->recoverExpiredLeases($queueName);
        JsonResponse::send(['data' => ['queue' => $queueName] + $result]);
    }

    JsonResponse::error('invalid_action', 'Supported actions are cancel, retry and recover.', 400);
} catch (Throwable $exception) {
    error_log('[UnrealDB job action API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
