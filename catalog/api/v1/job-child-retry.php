<?php
/**
 * Retry one terminal affected-dependency child from the file-centric Background Jobs tree.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoAffectedDependencyChildRetry;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $queueName = trim((string)($payload['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    $jobId = (int)($payload['job_id'] ?? 0);
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($jobId < 1) {
        JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $result = (new PdoAffectedDependencyChildRetry($application->db))
        ->restart($queueName, $jobId, gmdate('Y-m-d H:i:s'));
    $reason = trim((string)($result['reason'] ?? ''));

    if (empty($result['handled'])) {
        JsonResponse::error(
            'not_supported',
            $reason !== '' ? $reason : 'This child job is not an affected-dependency recovery unit.',
            409
        );
    }
    if ((int)($result['retry_blocked'] ?? 0) > 0) {
        JsonResponse::error(
            'not_retryable',
            $reason !== '' ? $reason : 'This child failure cannot succeed by replaying the same work.',
            409
        );
    }
    if ((int)($result['affected'] ?? 0) < 1) {
        JsonResponse::error(
            'not_retryable',
            $reason !== '' ? $reason : 'This child job is no longer in a stopped or failed state.',
            409
        );
    }

    $worker = (new CatalogQueueWorkerStarter($application->db, $application->config))
        ->start($queueName, true, $userId);
    $result['worker'] = $worker['worker'];
    $result['worker_error'] = (string)$worker['worker_error'];

    JsonResponse::send(['data' => $result]);
} catch (Throwable $error) {
    error_log('[UnrealDB child job retry] ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
