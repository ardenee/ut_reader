<?php
/**
 * Queue one lightweight parent job for selected cross-game dependency providers.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

function cross_game_batch_wants_json(): bool
{
    return strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || (string)($_POST['response'] ?? '') === 'json';
}

/** @param array<string,mixed> $payload */
function cross_game_batch_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        if (cross_game_batch_wants_json()) {
            cross_game_batch_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
        }
        http_response_code(403);
        exit('Administrator login is required.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    catalog_check_csrf('dependency-cross-examine');

    $rawIds = $_POST['source_file_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    if ($rawIds === [] && isset($_POST['source_file_id'])) {
        $rawIds = [$_POST['source_file_id']];
    }
    $sourceFileIds = array_values(array_unique(array_filter(
        array_map(static fn(mixed $value): int => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : 0, $rawIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($sourceFileIds === []) {
        throw new RuntimeException('Select at least one source package.');
    }
    if (count($sourceFileIds) > 500) {
        throw new RuntimeException('No more than 500 source packages may be queued at once.');
    }

    $destinationGameId = filter_input(INPUT_POST, 'destination_game_id', FILTER_VALIDATE_INT);
    if ($destinationGameId === false || $destinationGameId === null) {
        $destinationGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    }
    $destinationGameId = $destinationGameId === false || $destinationGameId === null ? 0 : (int)$destinationGameId;
    if ($destinationGameId < 1) {
        throw new RuntimeException('Choose a destination game.');
    }

    $reportTargetGameId = filter_input(INPUT_POST, 'report_target_game_id', FILTER_VALIDATE_INT);
    $reportTargetGameId = $reportTargetGameId === false || $reportTargetGameId === null
        ? $destinationGameId
        : (int)$reportTargetGameId;
    $sourceGameId = filter_input(INPUT_POST, 'source_game_id', FILTER_VALIDATE_INT);
    $sourceGameId = $sourceGameId === false || $sourceGameId === null ? 0 : (int)$sourceGameId;
    $limit = filter_input(INPUT_POST, 'limit', FILTER_VALIDATE_INT);
    $limit = $limit === false || $limit === null ? 100 : max(10, min(500, (int)$limit));
    $userId = isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] > 0
        ? (int)$_SESSION['user']['id']
        : null;

    $config = catalog_config();
    $db = catalog_db($config);
    $destination = catalog_one($db, 'SELECT id,name FROM ue_games WHERE id=? LIMIT 1', [$destinationGameId]);
    if (!$destination) {
        throw new RuntimeException('Destination game no longer exists.');
    }

    sort($sourceFileIds, SORT_NUMERIC);
    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $dedupeKey = 'cross-game-copy-batch:' . hash(
        'sha256',
        $destinationGameId . "\0" . implode(',', $sourceFileIds)
    );
    $jobId = (new PdoJobQueue($db))->enqueue(
        $queueName,
        JobType::CROSS_GAME_COPY_BATCH,
        [
            'source_file_ids' => $sourceFileIds,
            'destination_game_id' => $destinationGameId,
            'user_id' => $userId,
            'report_target_game_id' => max(0, $reportTargetGameId),
            'report_source_game_id' => max(0, $sourceGameId),
            'report_limit' => $limit,
        ],
        3,
        null,
        $dedupeKey,
        $userId,
        3
    );

    // Worker start is non-blocking. All package revalidation and import queue
    // preparation occurs inside CROSS_GAME_COPY_BATCH, never in this request.
    try {
        (new CatalogQueueWorkerStarter($db, $config))->start($queueName, true, $userId);
    } catch (Throwable $workerError) {
        error_log('[UnrealDB cross-game batch] worker start: ' . $workerError->getMessage());
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (cross_game_batch_wants_json()) {
        cross_game_batch_reply([
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'queued',
            'selected' => count($sourceFileIds),
            'destination_game_id' => $destinationGameId,
            'destination_game' => (string)$destination['name'],
        ], 202);
    }

    $query = [
        'target_game_id' => max(0, $reportTargetGameId),
        'source_game_id' => max(0, $sourceGameId),
        'limit' => $limit,
        'batch_job_id' => $jobId,
        'notice' => 'Cross-game queue preparation started as background job #' . $jobId . '.',
    ];
    header('Location: dependency-cross-examine.php?' . http_build_query($query), true, 303);
    exit;
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $message = trim($error->getMessage()) ?: 'Cross-game package batch could not be queued.';
    if (cross_game_batch_wants_json()) {
        cross_game_batch_reply(['ok' => false, 'error' => $message], 400);
    }

    $reportTargetGameId = isset($reportTargetGameId) ? (int)$reportTargetGameId : 0;
    $sourceGameId = isset($sourceGameId) ? (int)$sourceGameId : 0;
    $limit = isset($limit) ? (int)$limit : 100;
    $query = [
        'target_game_id' => max(0, $reportTargetGameId),
        'source_game_id' => max(0, $sourceGameId),
        'limit' => max(10, min(500, $limit)),
        'error' => $message,
    ];
    header('Location: dependency-cross-examine.php?' . http_build_query($query), true, 303);
    exit;
}
