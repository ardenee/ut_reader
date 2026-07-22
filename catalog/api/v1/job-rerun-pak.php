<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array<string,mixed> */
function catalog_pak_rerun_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function catalog_pak_rerun_local_reference(string $path): string
{
    return 'local-pak:' . rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

/** @param array<string,mixed> $payload */
function catalog_pak_rerun_verify_identity(string $path, array $payload): void
{
    $expectedSize = (int)($payload['size'] ?? 0);
    $actualSize = filesize($path);
    if ($actualSize === false || $actualSize < 1 || ($expectedSize > 0 && (int)$actualSize !== $expectedSize)) {
        throw new RuntimeException('The retained PAK size no longer matches the completed job.');
    }

    $expectedSha256 = strtolower(trim((string)($payload['sha256'] ?? '')));
    if ($expectedSha256 === '') {
        return;
    }
    $actualSha256 = hash_file('sha256', $path);
    if (!is_string($actualSha256) || !hash_equals($expectedSha256, strtolower($actualSha256))) {
        throw new RuntimeException('The retained PAK identity no longer matches the completed job.');
    }
}

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $request = catalog_api_json_body();
    $jobId = (int)($request['job_id'] ?? 0);
    $queueName = trim((string)($request['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    if ($jobId < 1) {
        JsonResponse::error('invalid_job', 'A positive job_id is required.', 400);
    }
    if ($queueName === '' || strlen($queueName) > 80) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }

    $job = catalog_one(
        $application->db,
        'SELECT id,queue_name,job_type,status,priority,max_attempts,payload_json,result_json '
        . 'FROM ue_background_jobs WHERE id=? AND queue_name=? LIMIT 1',
        [$jobId, $queueName]
    );
    if (!$job) {
        JsonResponse::error('not_found', 'The requested PAK import job was not found.', 404);
    }
    if ((string)$job['job_type'] !== JobType::IMPORT_STAGED_PAK) {
        JsonResponse::error('not_pak_import', 'Only PAK import jobs can be re-run from retained storage.', 409);
    }
    if (!in_array((string)$job['status'], ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
        JsonResponse::error('not_terminal', 'Only terminal PAK import jobs can be re-run.', 409);
    }

    $sourcePayload = catalog_pak_rerun_decode((string)$job['payload_json']);
    $gameId = (int)($sourcePayload['game_id'] ?? 0);
    $originalName = trim((string)($sourcePayload['original_name'] ?? ''));
    $stagedPath = trim((string)($sourcePayload['staged_path'] ?? ''));
    if ($gameId < 1 || $originalName === '' || $stagedPath === '') {
        JsonResponse::error('invalid_source_job', 'The original PAK job payload is incomplete.', 409);
    }

    $sourceMode = 'durable_staging';
    $sourcePath = '';
    try {
        $sourcePath = (new CatalogIncomingFileStore($application->config))->resolve($stagedPath);
        catalog_pak_rerun_verify_identity($sourcePath, $sourcePayload);
    } catch (Throwable $stagingError) {
        if (!CatalogPakArchiveStore::schemaInstalled($application->db)) {
            throw $stagingError;
        }

        $result = catalog_pak_rerun_decode((string)($job['result_json'] ?? ''));
        $pakId = (int)($result['pak_id'] ?? 0);
        $pak = $pakId > 0
            ? catalog_one($application->db, 'SELECT * FROM ue_pak_archives WHERE id=? AND game_id=? LIMIT 1', [$pakId, $gameId])
            : null;

        $sha256 = strtolower(trim((string)($sourcePayload['sha256'] ?? '')));
        if (!$pak && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
            $pak = catalog_one(
                $application->db,
                'SELECT * FROM ue_pak_archives WHERE game_id=? AND sha256=? ORDER BY id DESC LIMIT 1',
                [$gameId, $sha256]
            );
        }
        if (!$pak) {
            JsonResponse::error(
                'pak_source_unavailable',
                'Neither the durable staging copy nor a retained managed PAK archive is available for this job.',
                409
            );
        }

        $archiveStore = new CatalogPakArchiveStore($application->config);
        $sourcePath = $archiveStore->resolve($pak);
        $sourcePayload['staged_path'] = catalog_pak_rerun_local_reference($sourcePath);
        $sourcePayload['original_name'] = (string)$pak['original_name'];
        $sourcePayload['source_relative_path'] = (string)($sourcePayload['source_relative_path'] ?? $pak['original_name']);
        $sourcePayload['size'] = (int)$pak['file_size'];
        $sourcePayload['sha256'] = strtolower((string)$pak['sha256']);
        catalog_pak_rerun_verify_identity($sourcePath, $sourcePayload);
        $sourceMode = 'retained_pak';
    }

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $sourcePayload['user_id'] = $userId;
    $sourcePayload['rerun_of_job_id'] = $jobId;
    $sourceIdentity = strtolower(trim((string)($sourcePayload['sha256'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/', $sourceIdentity) !== 1) {
        $sourceIdentity = hash(
            'sha256',
            $gameId . "\0" . (string)$sourcePayload['staged_path'] . "\0" . (string)$sourcePayload['original_name']
        );
    }
    $dedupeKey = 'rerun-pak:' . $gameId . ':' . $sourceIdentity;

    $newJobId = (new PdoJobQueue($application->db))->enqueue(
        $queueName,
        JobType::IMPORT_STAGED_PAK,
        $sourcePayload,
        max(0, min((int)$job['priority'], 1000)),
        null,
        $dedupeKey,
        $userId,
        max(1, min((int)$job['max_attempts'], 20))
    );

    JsonResponse::send([
        'data' => [
            'job_id' => $newJobId,
            'status' => 'queued',
            'type' => JobType::IMPORT_STAGED_PAK,
            'rerun_of_job_id' => $jobId,
            'source' => $sourceMode,
        ],
    ], 202);
} catch (Throwable $exception) {
    error_log('[UnrealDB PAK job rerun] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The PAK import could not be queued again.', 503);
}
