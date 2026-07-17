<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST is required.');
    if (!catalog_support_is_admin()) throw new RuntimeException('Administrator login is required.');
    catalog_check_csrf('unverified-database-import');

    $token = trim((string)($_POST['token'] ?? ''));
    if ($token === '') throw new RuntimeException('A queued file is required.');

    $config = catalog_config();
    $db = catalog_db($config);
    $item = uvf_resolve($db, $config, $token);
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $result = catalog_unverified_index_item($db, $config, $item, $userId, false);
    echo json_encode(['ok' => true, 'file' => (string)$item['original_name']] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('[UnrealDB unverified backfill] ' . $error->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
