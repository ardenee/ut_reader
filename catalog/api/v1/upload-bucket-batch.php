<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/CatalogRedirectArchive.php';
require_once dirname(__DIR__, 2) . '/lib/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogOrphanedJobRecovery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('upload_bucket_chunk');

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }

    $payload = catalog_api_json_body();
    $rawIds = $payload['upload_ids'] ?? [];
    if (!is_array($rawIds)) {
        JsonResponse::error('invalid_uploads', 'Upload Bucket source identifiers must be an array.', 400);
    }
    if (count($rawIds) > 10000) {
        JsonResponse::error('too_many_uploads', 'Finalize no more than 10,000 uploaded files at once.', 400);
    }

    $uploadIds = [];
    foreach ($rawIds as $rawId) {
        $uploadId = strtolower(trim((string)$rawId));
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            JsonResponse::error('invalid_upload', 'A completed Upload Bucket source identifier is invalid.', 400);
        }
        $uploadIds[$uploadId] = $uploadId;
    }
    $uploadIds = array_values($uploadIds);

    $queue = new CatalogBucketBatchQueue($application->db, $application->config);
    $launcher = new CatalogDetachedWorker($application->config);
    $orphanRecovery = [];
    $activeQueues = [];
    foreach ([$queue->queueName(), $queue->legacyQueueName()] as $queueName) {
        $workerStatus = $launcher->status($queueName, false);
        if (empty($workerStatus['active'])) {
            $recovery = (new CatalogOrphanedJobRecovery($application->db, $application->config))
                ->recoverInactiveQueue($queueName);
            if (!empty($recovery['recovered'])) {
                $orphanRecovery[$queueName] = $recovery;
            }
            $workerStatus = $launcher->status($queueName, false);
        }
        if (!empty($workerStatus['active'])) {
            $activeQueues[] = $queueName;
        }
    }
    if ($activeQueues !== []) {
        JsonResponse::error(
            'bucket_processing_not_paused',
            'Upload Bucket processing is still active in ' . implode(', ', $activeQueues)
                . '. Wait for the current job to finish or stop that job, then retry batch finalisation.',
            409,
            ['active_queues' => $activeQueues]
        );
    }

    $legacyMigrated = $queue->migrateLegacyQueuedJobs();

    $messages = [];
    $jobIds = [];
    $queued = 0;
    $duplicates = 0;
    $failed = 0;

    foreach ($uploadIds as $uploadId) {
        try {
            $result = $queue->enqueueCompletedUpload($uploadId, $userId);
            $jobId = (int)($result['job_id'] ?? 0);
            if ($jobId > 0) {
                $jobIds[$jobId] = $jobId;
            }

            $md5 = trim((string)($result['md5'] ?? ''));
            $sha1 = trim((string)($result['sha1'] ?? ''));
            $identityText = $md5 !== '' && $sha1 !== ''
                ? ' MD5: ' . $md5 . ' | SHA-1: ' . $sha1
                : '';

            if (!empty($result['deduplicated'])) {
                $duplicates++;
                $kind = (string)($result['duplicate_kind'] ?? '');
                $duplicateFileId = (int)($result['duplicate_file_id'] ?? 0);
                if (in_array($kind, ['upload_bucket', 'catalog_storage'], true)) {
                    $message = 'An identical physical file already exists in '
                        . ($kind === 'upload_bucket' ? 'the Upload Bucket' : 'catalog storage')
                        . ' as file #' . $duplicateFileId . '.';
                } else {
                    $message = 'Exact uploaded source content already belongs to active processing job #' . $jobId . '.';
                }
                if (!empty($result['duplicate_source_removed'])) {
                    $message .= ' The repeated staged upload was deleted before package processing.';
                }
                $messages[] = [
                    'status' => 'duplicate',
                    'file' => (string)$result['source_relative_path'],
                    'file_id' => $duplicateFileId,
                    'message' => $message . $identityText,
                    'file_size' => (int)$result['size'],
                    'file_size_text' => catalog_bytes((int)$result['size']),
                    'job_id' => $jobId,
                ];
                continue;
            }

            $queued++;
            $messages[] = [
                'status' => 'queued',
                'file' => (string)$result['source_relative_path'],
                'message' => $identityText !== ''
                    ? 'Upload completed, retained its pre-calculated package MD5/SHA-1 and passed the final physical duplicate check. Processing job #' . $jobId . ' was created.'
                    : 'Redirect wrapper upload completed. Processing job #' . $jobId . ' will decompress it, calculate the real package MD5/SHA-1 and then run the physical duplicate check.',
                'file_size' => (int)$result['size'],
                'file_size_text' => catalog_bytes((int)$result['size']),
                'job_id' => $jobId,
            ];
        } catch (Throwable $error) {
            $failed++;
            $requestId = catalog_request_id();
            $errorMessage = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
            error_log('[UnrealDB][' . $requestId . '] bucket batch source ' . $uploadId . ' failed: ' . get_class($error) . ': ' . $errorMessage);
            $messages[] = [
                'status' => 'failed',
                'file' => $uploadId,
                'message' => $errorMessage . ' | reference: ' . $requestId,
            ];
        }
    }

    $pendingJobs = catalog_count(
        $application->db,
        'SELECT COUNT(*) c FROM ue_background_jobs WHERE queue_name=? AND status="queued"',
        [$queue->queueName()]
    );
    $worker = null;
    $workerError = '';
    if ($pendingJobs > 0) {
        try {
            // A second recovery closes the small race between the initial queue
            // inspection and the worker launch at the end of batch finalisation.
            (new CatalogOrphanedJobRecovery($application->db, $application->config))
                ->recoverInactiveQueue($queue->queueName());
            $worker = $launcher->start($queue->queueName(), 10000);
        } catch (Throwable $error) {
            $workerError = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
            error_log('[UnrealDB bucket batch worker] ' . get_class($error) . ': ' . $workerError);
        }
    }

    JsonResponse::send([
        'ok' => true,
        'data' => [
            'queue' => $queue->queueName(),
            'requested' => count($uploadIds),
            'queued' => $queued,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'legacy_migrated' => $legacyMigrated,
            'pending_jobs' => $pendingJobs,
            'job_ids' => array_values($jobIds),
            'worker' => $worker,
            'worker_error' => $workerError,
            'orphan_recovery' => $orphanRecovery,
            'messages' => $messages,
        ],
    ], $failed > 0 && $queued === 0 && $duplicates === 0 && $legacyMigrated === 0 ? 500 : 200);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    $errorMessage = trim($error->getMessage()) ?: get_class($error) . ' was thrown without an error message.';
    error_log('[UnrealDB][' . $requestId . '] bucket batch finalization failed: ' . get_class($error) . ': ' . $errorMessage);
    JsonResponse::error(
        'bucket_batch_failed',
        $errorMessage,
        500,
        ['request_id' => $requestId]
    );
}
