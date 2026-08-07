<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job worker status.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Application\Jobs\CatalogWorkerStatusPolicy;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array{queued:int,ready:int,running:int,terminal:int,total:int} */
function job_worker_status_counts(PDO $db, string $queueName): array
{
    $counts = ['queued' => 0, 'ready' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];
    foreach (catalog_all(
        $db,
        'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE queue_name=? GROUP BY status',
        [$queueName]
    ) as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $count = (int)($row['c'] ?? 0);
        $counts['total'] += $count;
        if ($status === 'queued') {
            $counts['queued'] += $count;
        } elseif ($status === 'running') {
            $counts['running'] += $count;
        } elseif (in_array($status, ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
            $counts['terminal'] += $count;
        }
    }
    if ($counts['queued'] > 0) {
        $counts['ready'] = catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()',
            [$queueName]
        );
    }
    return $counts;
}

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $queueName = trim((string)($_GET['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    /*
     * Status polling must never start, stop, recover or otherwise mutate the
     * queue. A browser GET runs every two seconds; allowing it to auto-heal a
     * failed launch hid the real error behind an endless launching state and
     * disabled the explicit Start button.
     *
     * This hot polling path also deliberately excludes worker log tails. Reading
     * stdout/stderr for every worker slot every two seconds adds avoidable disk
     * I/O (and is particularly expensive on Windows when worker logs are open).
     * Explicit start/recovery/diagnostic operations can still request log tails.
     */
    $launcher = new CatalogDetachedWorker($application->config);
    $worker = $launcher->status($queueName, false);
    $counts = job_worker_status_counts($application->db, $queueName);
    $status = CatalogWorkerStatusPolicy::evaluate(
        $worker,
        $counts,
        $launcher->configuredWorkerCount()
    );

    $worker['authoritative_status'] = $status['authoritative_status'];
    $worker['authoritative_message'] = $status['authoritative_message'];
    $worker['queue_counts'] = $counts;
    $worker['restart_recommended'] = $status['restart_recommended'];
    $worker['status_read_only'] = true;
    $worker['auto_recovery'] = null;
    $worker['auto_start'] = null;
    $worker['auto_start_error'] = '';

    JsonResponse::send(['data' => ['worker' => $worker]]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] detached worker status failed: ' . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error(
        'unavailable',
        trim($exception->getMessage()) ?: 'Detached worker status is unavailable.',
        503,
        ['request_id' => $requestId]
    );
}
