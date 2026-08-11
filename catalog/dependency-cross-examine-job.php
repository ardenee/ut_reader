<?php
/**
 * Poll one cross-game copy batch job for the admin progress dialog.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobLookupQuery;

/** @param array<string,mixed> $payload */
function cross_game_job_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        cross_game_job_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    $jobId = filter_input(INPUT_GET, 'job_id', FILTER_VALIDATE_INT);
    $jobId = $jobId === false || $jobId === null ? 0 : (int)$jobId;
    if ($jobId < 1) {
        cross_game_job_reply(['ok' => false, 'error' => 'A valid batch job is required.'], 400);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $job = (new PdoBackgroundJobLookupQuery($db))->findByIdAndType($jobId, JobType::CROSS_GAME_COPY_BATCH);
    if ($job === null) {
        cross_game_job_reply(['ok' => false, 'error' => 'Cross-game batch job was not found.'], 404);
    }

    $progress = [];
    if (trim((string)($job['progress_json'] ?? '')) !== '') {
        $decoded = json_decode((string)$job['progress_json'], true);
        $progress = is_array($decoded) ? $decoded : [];
    }
    $result = null;
    if (trim((string)($job['result_json'] ?? '')) !== '') {
        $decoded = json_decode((string)$job['result_json'], true);
        $result = is_array($decoded) ? $decoded : null;
    }

    cross_game_job_reply([
        'ok' => true,
        'job' => [
            'id' => (int)$job['id'],
            'status' => (string)$job['status'],
            'progress' => $progress,
            'result' => $result,
            'last_error' => trim((string)($job['last_error'] ?? '')),
            'created_at' => (string)($job['created_at'] ?? ''),
            'updated_at' => (string)($job['updated_at'] ?? ''),
            'completed_at' => (string)($job['completed_at'] ?? ''),
        ],
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB cross-game batch status] ' . get_class($error) . ': ' . $error->getMessage());
    cross_game_job_reply(['ok' => false, 'error' => 'Cross-game batch status is temporarily unavailable.'], 503);
}
