<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job worker status.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into HTML pages.
 * Role: Thin HTTP API entry point; persistence and state policy are delegated.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Application\Jobs\CatalogWorkerMonitoringSummary;
use UnrealDb\Catalog\Application\Jobs\CatalogWorkerStatusPolicy;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

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
     * This GET is deliberately read-only and excludes worker log tails. Browser
     * polling must never launch/recover workers or perform avoidable log I/O.
     *
     * Do not call queueCounts() here. Parent/child workflows can leave millions
     * of internal execution rows behind one small operator-visible job set, and
     * exact durable aggregation on every two-second poll can dominate MySQL.
     */
    $launcher = new CatalogDetachedWorker($application->config);
    $worker = $launcher->status($queueName, false);
    $operational = new PdoBackgroundJobOperationalQuery($application->db, $application->config);
    $presence = $operational->queuePresence($queueName);
    $working = $operational->runningWork($queueName);
    $operatorCounts = $operational->operatorActiveCounts($queueName);

    // Browser worker-health policy needs durable presence, not multi-million-row
    // totals. Treat queued presence as potentially claimable for UI health; the
    // worker claimer remains authoritative for exact available_at admission.
    $policyCounts = [
        'queued' => $presence['queued'] ? 1 : 0,
        'ready' => $presence['queued'] ? 1 : 0,
        'running' => $presence['running'] ? 1 : 0,
        'terminal' => 0,
        'total' => ($presence['queued'] ? 1 : 0) + ($presence['running'] ? 1 : 0),
    ];
    $status = CatalogWorkerStatusPolicy::evaluate(
        $worker,
        $policyCounts,
        $launcher->configuredWorkerCount()
    );

    /*
     * Slot state files survive normal worker exits for diagnostics. The legacy
     * aggregate in CatalogDetachedWorker therefore includes historical stopped
     * slots. Preserve the existing state.processed response field, but expose
     * only work completed by processes that currently own worker locks.
     */
    $activeProcessed = CatalogWorkerStatusPolicy::activeProcessed($worker);
    $workerState = is_array($worker['state'] ?? null) ? $worker['state'] : [];
    $workerState['processed'] = $activeProcessed;
    $worker['state'] = $workerState;
    $worker['active_processed'] = $activeProcessed;

    $monitoring = CatalogWorkerMonitoringSummary::fromRunningWork($working);

    $worker['authoritative_status'] = $status['authoritative_status'];
    $worker['authoritative_message'] = $status['authoritative_message'];
    // Keep this field job-centric for UI/backward compatibility. Exact durable
    // execution-row totals remain available from operational diagnostics only.
    $worker['queue_counts'] = $operatorCounts;
    $worker['queue_counts_scope'] = 'operator_jobs';
    $worker['durable_queue_presence'] = $presence;
    $worker['restart_recommended'] = $status['restart_recommended'];
    $worker['monitoring'] = $monitoring;
    $worker['status_read_only'] = true;
    $worker['auto_recovery'] = null;
    $worker['auto_start'] = null;
    $worker['auto_start_error'] = '';

    JsonResponse::send(['data' => ['worker' => $worker, 'working' => $working]]);
} catch (InvalidArgumentException $exception) {
    JsonResponse::error('invalid_worker_request', $exception->getMessage(), 400);
} catch (Throwable $exception) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] detached worker status failed: '
        . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error(
        'unavailable',
        trim($exception->getMessage()) ?: 'Detached worker status is unavailable.',
        503,
        ['request_id' => $requestId]
    );
}
