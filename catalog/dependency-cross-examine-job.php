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

/** @return list<int> */
function cross_game_source_ids(mixed $raw): array
{
    $ids = [];
    foreach (is_array($raw) ? $raw : [] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    ksort($ids, SORT_NUMERIC);
    return array_values($ids);
}

/** @return array<string,int|string|null> */
function cross_game_child_progress(PDO $db, int $parentJobId): array
{
    $statement = $db->prepare(
        'SELECT COUNT(*) total,'
        . 'SUM(status="completed") completed,'
        . 'SUM(status="running") running,'
        . 'SUM(status="queued") queued_children,'
        . 'SUM(status IN ("failed","dead_letter","cancelled")) failed,'
        . 'SUM(status="completed" AND JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.outcome"))="queued") queued_outcome,'
        . 'SUM(status="completed" AND JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.outcome"))="deduplicated") deduplicated,'
        . 'SUM(status="completed" AND JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.outcome"))="skipped") skipped,'
        . 'MAX(CASE WHEN status="running" THEN JSON_UNQUOTE(JSON_EXTRACT(progress_json,"$.current_file")) ELSE NULL END) current_file '
        . 'FROM ue_background_jobs WHERE parent_job_id=? AND workflow_unit_key LIKE "source:%"'
    );
    $statement->execute([$parentJobId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => max(0, (int)($row['total'] ?? 0)),
        'completed' => max(0, (int)($row['completed'] ?? 0)),
        'running' => max(0, (int)($row['running'] ?? 0)),
        'queued_children' => max(0, (int)($row['queued_children'] ?? 0)),
        'failed' => max(0, (int)($row['failed'] ?? 0)),
        'queued' => max(0, (int)($row['queued_outcome'] ?? 0)),
        'deduplicated' => max(0, (int)($row['deduplicated'] ?? 0)),
        'skipped' => max(0, (int)($row['skipped'] ?? 0)),
        'current_file' => trim((string)($row['current_file'] ?? '')),
    ];
}

function cross_game_utc_timestamp(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? 0 : $timestamp;
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

    $payload = [];
    if (trim((string)($job['payload_json'] ?? '')) !== '') {
        $decoded = json_decode((string)$job['payload_json'], true);
        $payload = is_array($decoded) ? $decoded : [];
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

    $sourceIds = cross_game_source_ids($payload['source_file_ids'] ?? []);
    $children = cross_game_child_progress($db, $jobId);
    $total = max(count($sourceIds), (int)$children['total']);
    $done = min($total, (int)$children['completed']);
    $createdAt = cross_game_utc_timestamp((string)($job['created_at'] ?? ''));
    $completedAt = cross_game_utc_timestamp((string)($job['completed_at'] ?? ''));
    $endAt = $completedAt > 0 ? $completedAt : time();
    $elapsed = $createdAt > 0 ? max(0, $endAt - $createdAt) : 0;
    $terminal = in_array((string)$job['status'], ['completed', 'failed', 'dead_letter', 'cancelled'], true);
    $eta = null;
    if ($terminal) {
        $eta = 0;
    } elseif ($done > 0 && $total > $done && $elapsed > 0) {
        $eta = (int)round(($elapsed / $done) * ($total - $done));
    }

    // The parent coordinator owns stage/percent while child rows own the actual
    // selected-package counts. Enrich the presentation projection here so the
    // popup reports real N/total, outcomes, elapsed time and ETA without moving
    // recovery responsibility back into the parent loop.
    $progress['done'] = $done;
    $progress['total'] = $total;
    $progress['queued'] = (int)$children['queued'];
    $progress['deduplicated'] = (int)$children['deduplicated'];
    $progress['skipped'] = (int)$children['skipped'];
    $progress['failed'] = (int)$children['failed'];
    $progress['elapsed_seconds'] = $elapsed;
    $progress['eta_seconds'] = $eta;
    if ((string)$children['current_file'] !== '') {
        $progress['current_file'] = (string)$children['current_file'];
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
