<?php
/** Queue selected or all-matching verified files to Unverified Files or another game. */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Games\PdoGameFileReassignmentSelectionQuery;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $sourceGameId = max(0, (int)($_GET['source_game_id'] ?? 0));
        $source = $sourceGameId > 0
            ? catalog_one(
                $application->db,
                'SELECT g.id,g.name,g.slug,COALESCE(p.engine_key,"") engine_key '
                . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'WHERE g.id=?',
                [$sourceGameId]
            )
            : null;
        if (!$source) {
            JsonResponse::error('invalid_source', 'Choose a valid source game.', 400);
        }
        $filters = [
            'file_filter' => (string)($_GET['file_filter'] ?? ''),
            'dep_filter' => (string)($_GET['dep_filter'] ?? ''),
            'type_filter' => (string)($_GET['type_filter'] ?? ''),
            'compression_filter' => (string)($_GET['compression_filter'] ?? ''),
        ];
        $snapshot = (new PdoGameFileReassignmentSelectionQuery($application->db))
            ->snapshot($sourceGameId, $filters);
        $games = catalog_all(
            $application->db,
            'SELECT g.id,g.name,g.slug,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id<>? ORDER BY g.name,g.id',
            [$sourceGameId]
        );
        JsonResponse::send(['data' => [
            'source_game' => $source,
            'movable_total' => $snapshot['total'],
            'filters' => $snapshot['filters'],
            'destinations' => array_merge([
                [
                    'id' => 0,
                    'name' => 'Unverified Files',
                    'slug' => 'upload-bucket',
                    'engine_key' => '',
                ],
            ], $games),
        ]]);
    }

    if ($method !== 'POST') {
        JsonResponse::error('method_not_allowed', 'Only GET and POST are supported.', 405);
    }
    catalog_api_require_csrf('catalog-maintenance');

    $body = catalog_api_json_body();
    $sourceGameId = max(0, (int)($body['source_game_id'] ?? 0));
    $targetGameId = (int)($body['target_game_id'] ?? -1);
    $scope = strtolower(trim((string)($body['scope'] ?? 'selected')));
    if ($sourceGameId < 1) {
        JsonResponse::error('invalid_source', 'Choose a valid source game.', 400);
    }
    if ($targetGameId < 0 || $targetGameId === $sourceGameId) {
        JsonResponse::error(
            'invalid_target',
            'Choose Unverified Files or a different destination game.',
            400
        );
    }
    if (!in_array($scope, ['selected', 'matching'], true)) {
        JsonResponse::error('invalid_scope', 'Choose selected files or all matching files.', 400);
    }

    $source = catalog_one($application->db, 'SELECT id,name FROM ue_games WHERE id=?', [$sourceGameId]);
    if (!$source) {
        JsonResponse::error('invalid_source', 'The source game no longer exists.', 404);
    }
    $targetName = 'Unverified Files';
    if ($targetGameId > 0) {
        $target = catalog_one($application->db, 'SELECT id,name FROM ue_games WHERE id=?', [$targetGameId]);
        if (!$target) {
            JsonResponse::error('invalid_target', 'The destination game no longer exists.', 404);
        }
        $targetName = (string)$target['name'];
    }

    $userId = max(0, (int)($_SESSION['user']['id'] ?? 0));
    $selector = new PdoGameFileReassignmentSelectionQuery($application->db);
    $payload = [
        'scope' => $scope,
        'source_game_id' => $sourceGameId,
        'target_game_id' => $targetGameId,
        'requested_by' => $userId,
    ];

    if ($scope === 'selected') {
        $rawIds = is_array($body['file_ids'] ?? null) ? $body['file_ids'] : [];
        $fileIds = $selector->selected($sourceGameId, $rawIds);
        if ($fileIds === []) {
            JsonResponse::error('no_files', 'Select at least one verified file from this game.', 409);
        }
        $payload['file_ids'] = $fileIds;
        $matchingTotal = count($fileIds);
        $dedupeMaterial = ['selected', $sourceGameId, $targetGameId, $fileIds];
    } else {
        $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
        $snapshot = $selector->snapshot($sourceGameId, $filters);
        if ($snapshot['total'] < 1) {
            JsonResponse::error('no_files', 'No verified files match the current filters.', 409);
        }
        $payload['filters'] = $snapshot['filters'];
        $payload['snapshot_total'] = $snapshot['total'];
        $payload['snapshot_max_id'] = $snapshot['max_id'];
        $matchingTotal = $snapshot['total'];
        $dedupeMaterial = [
            'matching',
            $sourceGameId,
            $targetGameId,
            $snapshot['filters'],
            $snapshot['total'],
            $snapshot['max_id'],
        ];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $queueName = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $dedupeKey = 'game-file-reassign:' . hash(
        'sha256',
        json_encode($dedupeMaterial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );
    $jobId = (new PdoJobQueue($application->db))->enqueue(
        $queueName,
        JobType::GAME_FILE_REASSIGN,
        $payload,
        10,
        null,
        $dedupeKey,
        $userId > 0 ? $userId : null,
        3
    );

    $workerState = (new CatalogQueueWorkerStarter($application->db, $application->config))
        ->start($queueName, true, $userId > 0 ? $userId : null);
    $message = 'Queued ' . number_format($matchingTotal) . ' file(s) from '
        . (string)$source['name'] . ' to ' . $targetName . ' as background job #' . $jobId . '.';
    if ((string)($workerState['worker_error'] ?? '') !== '') {
        $message .= ' Worker warning: ' . (string)$workerState['worker_error'];
    }

    JsonResponse::send(['data' => [
        'queued' => true,
        'job_id' => $jobId,
        'queue' => $queueName,
        'scope' => $scope,
        'matching_total' => $matchingTotal,
        'source_game_id' => $sourceGameId,
        'source_game' => (string)$source['name'],
        'target_game_id' => $targetGameId,
        'target_game' => $targetName,
        'worker' => $workerState['worker'] ?? null,
        'worker_error' => (string)($workerState['worker_error'] ?? ''),
        'message' => $message,
    ]]);
} catch (InvalidArgumentException $error) {
    JsonResponse::error('invalid_request', $error->getMessage(), 400);
} catch (Throwable $error) {
    error_log('[UnrealDB game file reassignment API] ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('unavailable', catalog_public_error_message(), 500);
}
