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

function catalog_maintenance_file_id(): int
{
    $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
    if ($fileId === false || $fileId === null || $fileId < 1) {
        throw new RuntimeException('A valid file ID is required.');
    }
    return (int)$fileId;
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

    /*
     * Full Sync deliberately calls these short operations one package at a
     * time. This prevents a shared-host PHP request timeout from aborting a
     * long game-wide maintenance run.
     */
    if ($operation === 'sync_reimport') {
        $result = catalog_file_maintenance_reimport($db, $config, catalog_maintenance_file_id(), $userId, $progress);
        catalog_maintenance_reply([
            'ok' => true,
            'file_id' => $result['file_id'],
            'game_id' => $result['game_id'],
            'original_name' => $result['original_name'],
            'message' => 'Re-imported ' . $result['original_name'] . ' using the normal scanner.',
        ]);
    }

    if ($operation === 'sync_refresh_dependencies') {
        $fileId = catalog_maintenance_file_id();
        $file = catalog_one($db, 'SELECT id, game_id, original_name FROM ue_files WHERE id=?', [$fileId]);
        if (!$file) {
            throw new RuntimeException('The re-imported package is no longer present in the catalog.');
        }
        scanner_rebuild_dependencies($db, $config, $fileId, $progress, 0, 100, 'Final dependency refresh for ' . $file['original_name']);
        catalog_maintenance_reply([
            'ok' => true,
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'original_name' => (string)$file['original_name'],
            'message' => 'Refreshed dependencies for ' . $file['original_name'] . '.',
        ]);
    }

    if ($operation === 'sync_game') {
        throw new RuntimeException('Full Sync now runs in short package-by-package requests. Refresh the Full Sync page and start it again.');
    }

    $fileId = catalog_maintenance_file_id();

    if ($operation === 'reimport' || $operation === 'rebuild') {
        $result = catalog_file_maintenance_reimport($db, $config, $fileId, $userId, $progress);
        catalog_maintenance_reply([
            'ok' => true,
            'message' => 'Re-imported ' . $result['original_name'] . ' using the normal scanner.',
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ]);
    }

    if ($operation === 'remove') {
        $result = catalog_file_maintenance_remove($db, $config, $fileId, $progress);
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
