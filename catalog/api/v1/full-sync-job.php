<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues one durable Full Sync job for an administrator-selected game.
 * Why: Full Sync can run for many hours and must not depend on a browser request or tab staying alive.
 * Role: Thin HTTP adapter over the durable background-job queue.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only POST is supported.', 405);
    }
    catalog_api_require_csrf('job_action');

    $payload = catalog_api_json_body();
    $gameId = (int)($payload['game_id'] ?? 0);
    $game = $gameId > 0
        ? catalog_one(
            $application->db,
            'SELECT g.id,g.name,COUNT(f.id) verified_files '
            . 'FROM ue_games g LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified" '
            . 'WHERE g.id=? GROUP BY g.id,g.name',
            [$gameId]
        )
        : null;
    if (!$game) {
        JsonResponse::error('invalid_game', 'A valid game_id is required.', 400);
    }
    if ((int)$game['verified_files'] < 1) {
        JsonResponse::error('empty_game', 'The selected game has no verified packages to Full Sync.', 409);
    }

    $queueName = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $jobId = (new PdoJobQueue($application->db))->enqueue(
        $queueName,
        JobType::FULL_SYNC_GAME,
        [
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'original_name' => 'Full Sync: ' . (string)$game['name'],
            'requested_by' => $userId,
            'initial_verified_files' => (int)$game['verified_files'],
        ],
        10,
        null,
        'full-sync-game:' . $gameId,
        $userId,
        1
    );

    JsonResponse::send([
        'data' => [
            'job_id' => $jobId,
            'status' => 'queued',
            'type' => JobType::FULL_SYNC_GAME,
            'queue' => $queueName,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'verified_files' => (int)$game['verified_files'],
        ],
    ], 202);
} catch (Throwable $error) {
    error_log('[UnrealDB Full Sync queue API] ' . $error->getMessage());
    JsonResponse::error('unavailable', 'Full Sync could not be queued.', 503);
}
