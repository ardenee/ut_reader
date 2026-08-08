<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for starting or reconciling the detached job worker pool.
 * Why: HTTP validation/serialization stays here while process lifecycle and durable queue policy are delegated.
 * Role: Thin HTTP API entry point.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogWorkerPoolReconciler;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogWorkerPoolStaleRestartFailed;
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
    $mode = strtolower(trim((string)($payload['mode'] ?? 'drain')));
    if (!in_array($mode, ['next', 'drain'], true)) {
        JsonResponse::error('invalid_mode', 'Worker mode must be next or drain.', 400);
    }
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $requestedWorkers = isset($payload['workers']) ? (int)$payload['workers'] : null;
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $result = (new CatalogWorkerPoolReconciler($application->db, $application->config))
        ->run($queueName, $mode, $requestedWorkers, $userId);

    if (empty($result['pool_satisfied'])) {
        $worker = is_array($result['worker'] ?? null) ? $result['worker'] : [];
        $workerCount = max(1, (int)($result['workers'] ?? 1));
        $active = max(0, (int)($worker['active_count'] ?? 0));
        $summary = trim((string)($result['slot_summary'] ?? ''));
        $launchErrors = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($result['launch_errors'] ?? [])
        )));
        $lastLaunchError = $launchErrors !== [] ? (string)end($launchErrors) : '';
        JsonResponse::error(
            'worker_pool_incomplete',
            'Requested ' . $workerCount . ' detached workers, but only ' . $active
                . ' acquired stable worker locks after reconciliation.'
                . ($summary !== '' ? ' ' . $summary : '')
                . ($lastLaunchError !== ''
                    ? ' Last launch error: ' . mb_substr($lastLaunchError, 0, 500, 'UTF-8')
                    : ''),
            409,
            ['worker' => $worker, 'reconciliation' => $result]
        );
    }

    JsonResponse::send(['data' => $result], 202);
} catch (CatalogWorkerPoolStaleRestartFailed $exception) {
    JsonResponse::error(
        'stale_worker_restart_failed',
        $exception->getMessage(),
        409,
        ['worker' => $exception->worker, 'restart' => $exception->restart]
    );
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] detached job launcher failed: '
        . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error(
        'launch_failed',
        trim($exception->getMessage()) ?: 'The detached queue worker pool could not be launched.',
        500,
        ['request_id' => $requestId]
    );
}
