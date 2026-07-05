<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogFileMaintenance.php';
require_once __DIR__ . '/lib/UploadProgress.php';

function catalog_maintenance_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function catalog_maintenance_progress_callback(string $token): callable
{
    return static function (array $state) use ($token): void {
        upload_progress_write($token, $state);
    };
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        catalog_maintenance_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    $progressToken = upload_progress_token((string)($_GET['progress'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $progressToken !== '') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        catalog_maintenance_reply(upload_progress_read($progressToken));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        catalog_maintenance_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('catalog-maintenance');
    $postProgressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $progress = $postProgressToken !== '' ? catalog_maintenance_progress_callback($postProgressToken) : null;
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $operation = (string)($_POST['operation'] ?? '');

    if ($progress !== null) {
        $progress([
            'stage' => 'start',
            'done' => 0,
            'total' => 100,
            'percent' => 0,
            'message' => 'Preparing maintenance request.',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);

    if ($operation === 'sync_game') {
        $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
        if ($gameId === false || $gameId === null || $gameId < 1) {
            throw new RuntimeException('A valid game is required.');
        }

        $result = catalog_file_maintenance_sync_game($db, $config, (int)$gameId, $userId, $progress);
        $message = 'Full sync completed for ' . $result['game_name'] . ': ' . $result['synced'] . '/' . $result['total'] . ' files re-imported.';
        if ($result['failed'] > 0) {
            $message .= ' ' . $result['failed'] . ' file(s) failed.';
        }
        catalog_maintenance_reply([
            'ok' => true,
            'message' => $message,
            'synced' => $result['synced'],
            'total' => $result['total'],
            'failed' => $result['failed'],
            'failures' => $result['failures'],
            'return_url' => 'full-sync.php?' . http_build_query([
                'game_id' => $result['game_id'],
                'synced' => $result['synced'],
                'total' => $result['total'],
                'failed' => $result['failed'],
            ]),
        ]);
    }

    $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
    if ($fileId === false || $fileId === null || $fileId < 1) {
        throw new RuntimeException('A valid file ID is required.');
    }

    if ($operation === 'reimport' || $operation === 'rebuild') {
        $result = catalog_file_maintenance_reimport($db, $config, (int)$fileId, $userId, $progress);
        catalog_maintenance_reply([
            'ok' => true,
            'message' => 'Re-imported ' . $result['original_name'] . ' using the normal scanner.',
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ]);
    }

    if ($operation === 'remove') {
        $result = catalog_file_maintenance_remove($db, $config, (int)$fileId, $progress);
        catalog_maintenance_reply([
            'ok' => true,
            'message' => 'Removed ' . $result['original_name'] . ' from storage and the catalog.' . $result['warning'],
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ]);
    }

    throw new RuntimeException('Unknown maintenance operation.');
} catch (Throwable $e) {
    if (isset($postProgressToken) && $postProgressToken !== '') {
        upload_progress_write($postProgressToken, [
            'stage' => 'failed',
            'done' => 0,
            'total' => 100,
            'percent' => 0,
            'message' => $e->getMessage(),
        ]);
    }
    error_log('[UnrealDB][' . catalog_request_id() . '] catalog maintenance failed: ' . $e->getMessage());
    catalog_maintenance_reply(['ok' => false, 'error' => $e->getMessage()], 400);
}
