<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';

function unverified_action_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('POST is required.');
    if (!catalog_support_is_admin()) unverified_action_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);

    catalog_check_csrf('unverified-files');
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_unverified_schema_ensure($db);

    $action = trim((string)($_POST['action'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));
    $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    $targetGameId = $targetGameId === false || $targetGameId === null ? 0 : (int)$targetGameId;
    $allowOverride = (string)($_POST['allow_profile_override'] ?? '') === '1';
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($token === '') throw new RuntimeException('A queued file is required.');
    if (!in_array($action, ['move', 'import', 'delete'], true)) throw new RuntimeException('Unknown unverified queue action.');
    if (in_array($action, ['move', 'import'], true) && $targetGameId < 1) throw new RuntimeException('Choose a target game first.');

    $source = uvf_resolve($db, $config, $token);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if ($action === 'move') {
        $result = catalog_unverified_move_item($db, $config, $source, $targetGameId);
        $message = 'Moved ' . $result['original_name'] . ' to ' . $result['target_game'] . '.';
    } elseif ($action === 'import') {
        // This is a trusted administrator promotion from controlled unverified
        // storage. The browser upload limit has already done its job and must not
        // reject a valid large package while the scanner promotes it.
        $trustedImportConfig = $config;
        $trustedImportConfig['max_upload_bytes'] = PHP_INT_MAX;
        $result = catalog_unverified_promote_item($db, $trustedImportConfig, $source, $targetGameId, $userId, $allowOverride);
        $message = ucfirst((string)$result['status']) . ' ' . $result['original_name'] . ' for ' . $result['target_game'] . '. ' . trim((string)$result['message']);
    } else {
        $result = catalog_unverified_discard_item($db, $config, $source);
        $message = 'Deleted ' . $result['original_name'] . ' from unverified storage and the staging database.';
    }

    unverified_action_reply([
        'ok' => true,
        'action' => $action,
        'original_name' => (string)$result['original_name'],
        'file_id' => isset($result['file_id']) ? (int)$result['file_id'] : null,
        'message' => $message,
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB unverified action] ' . $error->getMessage());
    unverified_action_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
