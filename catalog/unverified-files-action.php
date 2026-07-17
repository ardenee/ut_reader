<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';

function unverified_action_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    if (!catalog_support_is_admin()) {
        unverified_action_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    catalog_check_csrf('unverified-files');
    $config = catalog_config();
    $db = catalog_db($config);
    $action = trim((string)($_POST['action'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));
    $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    $targetGameId = $targetGameId === false || $targetGameId === null ? 0 : (int)$targetGameId;
    $allowOverride = (string)($_POST['allow_profile_override'] ?? '') === '1';
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($token === '') {
        throw new RuntimeException('A queued file is required.');
    }
    if (!in_array($action, ['move', 'import', 'delete'], true)) {
        throw new RuntimeException('Unknown unverified queue action.');
    }
    if (in_array($action, ['move', 'import'], true) && $targetGameId < 1) {
        throw new RuntimeException('Choose a target game first.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($action === 'move') {
        $result = uvf_move($db, $config, $token, $targetGameId);
        $message = 'Moved ' . $result['original_name'] . ' to ' . $result['target_game'] . '.';
    } elseif ($action === 'import') {
        $result = uvf_import($db, $config, $token, $targetGameId, $userId, $allowOverride);
        $message = 'Imported ' . $result['original_name'] . ' into ' . $result['target_game'] . '.';
        if (trim((string)($result['message'] ?? '')) !== '') {
            $message .= ' ' . trim((string)$result['message']);
        }
    } else {
        $result = uvf_discard($db, $config, $token);
        $message = 'Deleted ' . $result['original_name'] . ' from unverified storage.';
    }

    unverified_action_reply([
        'ok' => true,
        'action' => $action,
        'original_name' => (string)$result['original_name'],
        'message' => $message,
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB unverified action] ' . $error->getMessage());
    unverified_action_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
