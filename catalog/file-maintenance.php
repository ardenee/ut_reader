<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogFileMaintenance.php';

function catalog_maintenance_reply(array $payload, int $status = 200): void
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
        catalog_maintenance_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        catalog_maintenance_reply(['ok' => true, 'csrf' => catalog_csrf('catalog-maintenance')]);
    }

    catalog_check_csrf('catalog-maintenance');
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
    if ($fileId === false || $fileId === null || $fileId < 1) {
        throw new RuntimeException('A valid file ID is required.');
    }

    $file = catalog_one($db, 'SELECT id, game_id FROM ue_files WHERE id=?', [(int)$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog.');
    }

    if ((string)($_POST['operation'] ?? '') !== 'rebuild') {
        throw new RuntimeException('Unknown maintenance operation.');
    }

    $count = catalog_file_maintenance_rebuild_game($db, $config, (int)$file['game_id']);
    catalog_maintenance_reply(['ok' => true, 'message' => 'Rebuilt dependency links for ' . $count . ' verified package(s) in this game.']);
} catch (Throwable $e) {
    error_log('[UnrealDB][' . catalog_request_id() . '] catalog maintenance failed: ' . $e->getMessage());
    catalog_maintenance_reply(['ok' => false, 'error' => $e->getMessage()], 400);
}
