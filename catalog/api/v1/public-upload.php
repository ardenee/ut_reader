<?php
/**
 * Sequential public contribution chunk transport and background hand-off.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadTransferStoreFactory;
use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicUploadTransferStore;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_csrf('public_upload');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if (in_array($action, ['chunk', 'complete'], true)) {
        (new CatalogPublicAccessGuard($application->config))->transferAllowedOrThrow($application->db, 'Public upload');
    }
    $token = strtolower(trim((string)($_POST['upload_token'] ?? '')));
    $ip = catalog_client_ip();
    $store = new CatalogPublicUploadTransferStore($application->db, $application->config);

    if ($action === 'chunk') {
        $file = $_FILES['chunk'] ?? null;
        if (!is_array($file)) {
            JsonResponse::error('chunk_missing', 'The public upload chunk is missing.', 400);
        }
        $temporaryPath = (string)($file['tmp_name'] ?? '');
        $chunkSize = is_file($temporaryPath) ? (int)(filesize($temporaryPath) ?: 0) : 0;
        $maximumChunk = CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes($application->config);
        if ($chunkSize < 1 || $chunkSize > $maximumChunk) {
            JsonResponse::error(
                'chunk_size_invalid',
                'Public upload chunk must be between 1 byte and ' . $maximumChunk . ' bytes.',
                400
            );
        }
        $state = $store->writeChunk(
            $token,
            $ip,
            (int)($_POST['chunk_index'] ?? -1),
            $temporaryPath,
            (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)
        );
        JsonResponse::send(['ok' => true, 'data' => $state], 200);
    }

    if ($action === 'complete') {
        $row = $store->complete($token, $ip);
        $publicUploadId = max(0, (int)($row['id'] ?? 0));
        if ($publicUploadId < 1) {
            throw new RuntimeException('Completed public upload has no ledger identity.');
        }

        $configuredQueue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queueName = $configuredQueue . ':public-upload';
        $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
        $existingJobId = max(0, (int)($row['background_job_id'] ?? 0));
        $existingFileId = max(0, (int)($row['unverified_file_id'] ?? 0));

        if (in_array($rowStatus, ['unverified', 'duplicate'], true) && $existingFileId > 0) {
            JsonResponse::send([
                'ok' => true,
                'data' => [
                    'public_upload_id' => $publicUploadId,
                    'job_id' => $existingJobId,
                    'queue' => $queueName,
                    'status' => $rowStatus,
                    'file_id' => $existingFileId,
                    'message' => 'This contribution was already processed as file #' . $existingFileId . '.',
                ],
            ], 200);
        }

        if ($existingJobId > 0) {
            JsonResponse::send([
                'ok' => true,
                'data' => [
                    'public_upload_id' => $publicUploadId,
                    'job_id' => $existingJobId,
                    'queue' => $queueName,
                    'status' => $rowStatus !== '' ? $rowStatus : 'queued',
                    'message' => 'This contribution is already queued for background validation.',
                ],
            ], 202);
        }

        $jobId = (new PdoJobQueue($application->db))->enqueue(
            $queueName,
            JobType::PROCESS_PUBLIC_UPLOAD,
            [
                'public_upload_id' => $publicUploadId,
                'upload_token' => $token,
                'original_name' => (string)($row['original_name'] ?? ''),
                'source_relative_path' => (string)($row['relative_path'] ?? ''),
                'source_size' => max(0, (int)($row['file_size'] ?? 0)),
            ],
            40,
            null,
            'public-upload:' . $publicUploadId,
            null,
            3
        );

        $update = $application->db->prepare(
            'UPDATE ue_public_uploads SET background_job_id=?,result_message=?,updated_at=UTC_TIMESTAMP(6) '
            . 'WHERE id=?'
        );
        $update->execute([
            $jobId,
            'Upload complete. Server validation and unverified staging queued as background job #' . $jobId . '.',
            $publicUploadId,
        ]);

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'public_upload_id' => $publicUploadId,
                'job_id' => $jobId,
                'queue' => $queueName,
                'status' => 'queued',
                'message' => 'Upload complete. Validation is queued in the background.',
            ],
        ], 202);
    }

    if ($action === 'status') {
        JsonResponse::send([
            'ok' => true,
            'data' => $store->statusForContributor($token, $ip),
        ], 200);
    }

    if ($action === 'wake') {
        $configuredQueue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $queueName = $configuredQueue . ':public-upload';
        $worker = (new CatalogQueueWorkerStarter($application->db, $application->config))
            ->start($queueName, true, null);
        JsonResponse::send([
            'ok' => true,
            'data' => [
                'queue' => $queueName,
                'worker' => $worker['worker'],
                'worker_error' => (string)$worker['worker_error'],
                'message' => (string)$worker['worker_error'] !== ''
                    ? 'Uploads are queued, but the public-upload worker pool could not be fully started.'
                    : 'Public-upload background validation workers are running.',
            ],
        ], 200);
    }

    if ($action === 'cancel') {
        $store->cancel($token, $ip);
        JsonResponse::send(['ok' => true, 'data' => ['status' => 'cancelled']], 200);
    }

    JsonResponse::error('invalid_action', 'Public upload action must be chunk, complete, status, wake or cancel.', 400);
} catch (\InvalidArgumentException $error) {
    JsonResponse::error('invalid_public_upload', $error->getMessage(), 400);
} catch (\RuntimeException $error) {
    $message = trim($error->getMessage()) ?: 'Public upload transfer failed.';
    $status = str_contains(strtolower($message), 'blocked for this ip address')
        ? 403
        : (preg_match('/limit|paused|only one|expired/i', $message) === 1 ? 429 : 409);
    JsonResponse::error('public_upload_transfer_failed', $message, $status);
} catch (\Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] public upload transfer failed: '
        . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('public_upload_transfer_failed', 'Public upload transfer failed.', 500, [
        'request_id' => catalog_request_id(),
    ]);
}
