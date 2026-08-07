<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for job retry.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
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
    if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $rawIds = $payload['job_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [];
    }
    if (isset($payload['job_id'])) {
        $rawIds[] = $payload['job_id'];
    }

    $jobIds = [];
    foreach ($rawIds as $rawId) {
        $jobId = (int)$rawId;
        if ($jobId > 0) {
            $jobIds[$jobId] = $jobId;
        }
    }
    $jobIds = array_values($jobIds);
    if ($jobIds === []) {
        JsonResponse::error('invalid_jobs', 'Select at least one cancelled, failed or dead-letter job to restart.', 400);
    }
    if (count($jobIds) > 1000) {
        JsonResponse::error('too_many_jobs', 'Restart no more than 1,000 jobs at a time.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $now = gmdate('Y-m-d H:i:s');
    $arguments = array_merge([$now, $now, $queueName], $jobIds);

    $statement = $application->db->prepare(
        'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,'
        . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
        . 'last_error=NULL,result_json=NULL,cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
        . 'progress_json=NULL,progress_updated_at=NULL,dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
        . 'WHERE queue_name=? AND id IN (' . $placeholders . ') '
        . 'AND status IN ("cancelled","failed","dead_letter")'
    );
    $statement->execute($arguments);
    $restarted = $statement->rowCount();

    $worker = null;
    $workerError = '';
    if ($restarted > 0) {
        try {
            $worker = (new CatalogDetachedWorker($application->config))->start($queueName, 10000);
        } catch (Throwable $error) {
            $workerError = trim($error->getMessage());
            error_log('[UnrealDB job restart worker] ' . $error->getMessage());
        }
    }

    JsonResponse::send([
        'data' => [
            'queue' => $queueName,
            'requested' => count($jobIds),
            'restarted' => $restarted,
            'skipped' => count($jobIds) - $restarted,
            'worker' => $worker,
            'worker_error' => $workerError,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB job retry API] ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
