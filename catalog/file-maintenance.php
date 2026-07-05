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
        catalog_maintenance_reply(['ok' => true, 'progress' => upload_progress_read($progressToken)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        catalog_maintenance_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('catalog-maintenance');
    $postProgressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $progress = $postProgressToken !== '' ? catalog_maintenance_progress_callback($postProgressToken) : null;
    if ($progress !== null) {
        $progress([
            'stage' => 'starting',
            'done' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => 'Starting maintenance request.',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
    if ($fileId === false || $fileId === null || $fileId < 1) {
        throw new RuntimeException('A valid file ID is required.');
    }

    $file = catalog_one($db, 'SELECT id, game_id, original_name FROM ue_files WHERE id=?', [(int)$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }

    $operation = (string)($_POST['operation'] ?? '');
    if ($operation === 'rebuild') {
        $db->beginTransaction();
        try {
            catalog_import_rebuild_game($db, $config, (int)$file['game_id'], $progress);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($progress !== null) {
            $progress([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'message' => 'Dependency rebuild complete.',
            ]);
        }
        catalog_maintenance_reply([
            'ok' => true,
            'message' => 'Dependency rebuild complete.',
            'return_url' => 'game-files.php?id=' . (int)$file['game_id'],
        ]);
    }

    if ($operation === 'remove') {
        $result = catalog_file_maintenance_remove($db, $config, (int)$fileId);
        if ($progress !== null) {
            $progress([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'message' => 'Package removal complete.',
            ]);
        }
        catalog_maintenance_reply([
            'ok' => true,
            'message' => 'Package removal complete.' . $result['warning'],
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ]);
    }

    throw new RuntimeException('Unknown maintenance operation.');
} catch (Throwable $e) {
    if (isset($postProgressToken) && $postProgressToken !== '') {
        upload_progress_write($postProgressToken, [
            'stage' => 'failed',
            'done' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => $e->getMessage(),
        ]);
    }
    error_log('[UnrealDB][' . catalog_request_id() . '] catalog maintenance failed: ' . $e->getMessage());
    catalog_maintenance_reply(['ok' => false, 'error' => $e->getMessage()], 400);
}
