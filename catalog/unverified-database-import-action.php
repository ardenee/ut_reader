<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Processes the state-changing browser action for unverified database import.
 * Why: It separates mutation/request handling from the corresponding display page.
 * Role: Thin web action endpoint; metadata repair queuing and worker lifecycle are delegated to shared services.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedMetadataRepair.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;

function unverified_metadata_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

try {
    catalog_start_session();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        unverified_metadata_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }
    if (!catalog_support_is_admin()) {
        unverified_metadata_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }
    catalog_check_csrf('unverified-database-import');

    $sourceGameId = filter_input(INPUT_POST, 'source_game_id', FILTER_VALIDATE_INT);
    $sourceGameId = $sourceGameId === false || $sourceGameId === null ? -1 : (int)$sourceGameId;
    if ($sourceGameId < -1) {
        throw new RuntimeException('The selected unverified source queue is invalid.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    catalog_unverified_schema_ensure($db);
    if ($sourceGameId > 0 && !catalog_one($db, 'SELECT id FROM ue_games WHERE id=?', [$sourceGameId])) {
        throw new RuntimeException('The selected source game no longer exists.');
    }

    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $result = catalog_queue_unverified_metadata_repairs($db, $config, $sourceGameId, $userId);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $workerState = (new CatalogQueueWorkerStarter($db, $config))->start(
        (string)$result['queue'],
        (int)$result['candidate_count'] > 0,
        $userId
    );
    $worker = $workerState['worker'];
    $workerError = (string)$workerState['worker_error'];
    $recovery = is_array($workerState['recovery'] ?? null) ? $workerState['recovery'] : [];
    if ($workerError !== '') {
        error_log('[UnrealDB unverified metadata worker] ' . $workerError);
    }

    unverified_metadata_reply([
        'ok' => true,
        'queue' => (string)$result['queue'],
        'scope_count' => (int)$result['scope_count'],
        'queued' => (int)$result['candidate_count'],
        'job_ids' => array_values((array)$result['job_ids']),
        'worker_started' => !empty($worker['started']),
        'worker' => $worker,
        'worker_error' => $workerError,
        'recovery' => $recovery,
    ], 202);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    $message = trim($error->getMessage()) ?: 'The metadata repair jobs could not be queued.';
    error_log('[UnrealDB][' . $requestId . '] unverified metadata repair failed: ' . get_class($error) . ': ' . $message);
    unverified_metadata_reply([
        'ok' => false,
        'error' => $message,
        'request_id' => $requestId,
    ], 400);
}
