<?php
declare(strict_types=1);

ini_set('display_errors', '0');
@set_time_limit(0);
ob_start();
$GLOBALS['unverified_action_replied'] = false;

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';

function unverified_action_json(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (is_string($json)) {
        return $json;
    }

    return '{"ok":false,"error":"The server could not encode the action response."}';
}

function unverified_action_reply(array $payload, int $status = 200): never
{
    $GLOBALS['unverified_action_replied'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo unverified_action_json($payload);
    exit;
}

function unverified_action_error_text(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $message) ?? $message;
    $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    return $message !== '' ? $message : 'Unknown server error';
}

function unverified_action_dependency_collision_import_id(Throwable $error): int
{
    $message = $error->getMessage();
    if (!str_contains($message, 'uq_ue_deps_import')) {
        return 0;
    }
    if (preg_match("/Duplicate entry '([0-9]+)'/i", $message, $match) !== 1) {
        return 0;
    }
    return max(0, (int)$match[1]);
}

function unverified_action_clear_file_dependencies(PDO $db, int $fileId): int
{
    if ($fileId < 1) {
        return 0;
    }

    $removed = 0;
    $stmt = $db->prepare(
        'DELETE d FROM ue_dependencies d INNER JOIN ue_imports i ON i.id=d.import_id WHERE i.file_id=?'
    );
    $stmt->execute([$fileId]);
    $removed += $stmt->rowCount();

    $stmt = $db->prepare('DELETE FROM ue_dependencies WHERE file_id=?');
    $stmt->execute([$fileId]);
    $removed += $stmt->rowCount();
    return $removed;
}

function unverified_action_recover_verified_dependencies(
    PDO $db,
    array $config,
    int $fileId,
    Throwable $initialError
): array {
    $error = $initialError;
    $removed = 0;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $importId = unverified_action_dependency_collision_import_id($error);
        if ($importId < 1) {
            break;
        }

        $stmt = $db->prepare('DELETE FROM ue_dependencies WHERE import_id=?');
        $stmt->execute([$importId]);
        $removed += $stmt->rowCount();
        $removed += unverified_action_clear_file_dependencies($db, $fileId);

        try {
            scanner_rebuild_dependencies($db, $config, $fileId);
            scanner_rebuild_affected_dependencies($db, $config, $fileId);
            return [
                'recovered' => true,
                'removed' => $removed,
                'message' => 'Removed a stale duplicate dependency link and rebuilt dependency data successfully.',
            ];
        } catch (Throwable $retryError) {
            $error = $retryError;
        }
    }

    return [
        'recovered' => false,
        'removed' => $removed,
        'message' => unverified_action_error_text($error),
    ];
}

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['unverified_action_replied'])) {
        return;
    }
    $last = error_get_last();
    if (!is_array($last) || !in_array((int)($last['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    $requestId = function_exists('catalog_request_id') ? catalog_request_id() : bin2hex(random_bytes(8));
    error_log('[UnrealDB][' . $requestId . '] fatal unverified action error: ' . (string)($last['message'] ?? 'unknown fatal error'));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo unverified_action_json([
        'ok' => false,
        'error' => 'The server stopped unexpectedly while processing this file. Refresh the page before retrying because the import may have completed.',
        'request_id' => $requestId,
    ]);
});

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
    $importDetails = null;
    $warning = '';
    $recovery = null;

    if ($token === '') throw new RuntimeException('A queued file is required.');
    if (!in_array($action, ['move', 'import', 'delete'], true)) throw new RuntimeException('Unknown unverified queue action.');
    if (in_array($action, ['move', 'import'], true) && $targetGameId < 1) throw new RuntimeException('Choose a target game first.');

    $source = uvf_resolve($db, $config, $token);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if ($action === 'move') {
        $result = catalog_unverified_move_item($db, $config, $source, $targetGameId);
        $message = 'Moved ' . $result['original_name'] . ' to ' . $result['target_game'] . '.';
    } elseif ($action === 'import') {
        $staged = catalog_unverified_find(
            $db,
            (int)($source['game']['id'] ?? 0),
            (string)$source['queue_name']
        );
        $stagedFileId = (int)($staged['id'] ?? 0);
        if ($stagedFileId > 0) {
            // A dependency row can legally reference an import belonging to another
            // file because the schema has independent foreign keys. Remove any stale
            // rows attached to this staged file before its dependency data is rebuilt.
            unverified_action_clear_file_dependencies($db, $stagedFileId);
        }

        $trustedImportConfig = $config;
        $trustedImportConfig['max_upload_bytes'] = PHP_INT_MAX;
        try {
            $result = catalog_unverified_promote_item(
                $db,
                $trustedImportConfig,
                $source,
                $targetGameId,
                $userId,
                $allowOverride
            );
        } catch (Throwable $promotionError) {
            $verified = $stagedFileId > 0
                ? catalog_one($db, 'SELECT id,original_name,game_id FROM ue_files WHERE id=? AND scan_status="verified"', [$stagedFileId])
                : null;
            if (!$verified) {
                throw $promotionError;
            }

            $target = catalog_one($db, 'SELECT name FROM ue_games WHERE id=?', [(int)$verified['game_id']]) ?: [];
            $result = [
                'status' => 'verified',
                'file_id' => (int)$verified['id'],
                'original_name' => (string)$verified['original_name'],
                'target_game' => (string)($target['name'] ?? 'selected game'),
                'message' => 'The file was verified before a follow-up dependency refresh failed.',
            ];

            $recovery = unverified_action_recover_verified_dependencies(
                $db,
                $trustedImportConfig,
                (int)$verified['id'],
                $promotionError
            );
            if (empty($recovery['recovered'])) {
                $warning = 'File verification completed, but dependency refresh failed: '
                    . (string)$recovery['message']
                    . ' Use File Maintenance to rebuild dependencies for file #'
                    . (int)$verified['id'] . '.';
            }
        }

        $details = catalog_one(
            $db,
            'SELECT package_guid,name_count,import_count,export_count FROM ue_files WHERE id=?',
            [(int)$result['file_id']]
        ) ?: [];
        $guid = trim((string)($details['package_guid'] ?? ''));
        $importDetails = [
            'name_count' => (int)($details['name_count'] ?? 0),
            'import_count' => (int)($details['import_count'] ?? 0),
            'export_count' => (int)($details['export_count'] ?? 0),
            'package_guid' => $guid,
        ];
        $statusLabel = match (strtolower((string)$result['status'])) {
            'verified' => 'Verified',
            'duplicate' => 'Duplicate',
            'alias' => 'Alias added',
            default => ucfirst((string)$result['status']),
        };
        $message = $statusLabel . ' ' . $result['original_name'] . ' for ' . $result['target_game']
            . '. N/I/E: ' . $importDetails['name_count'] . '/' . $importDetails['import_count'] . '/' . $importDetails['export_count']
            . ' | GUID: ' . ($guid !== '' ? $guid : 'N/A') . '.';
        if (is_array($recovery) && !empty($recovery['recovered'])) {
            $message .= ' Dependency repair: ' . (string)$recovery['message'];
        }
        if ($warning !== '') {
            $message .= ' Warning: ' . $warning;
        }
    } else {
        $result = catalog_unverified_discard_item($db, $config, $source);
        $message = 'Deleted ' . $result['original_name'] . ' from unverified storage and the staging database.';
    }

    unverified_action_reply([
        'ok' => true,
        'action' => $action,
        'original_name' => (string)$result['original_name'],
        'file_id' => isset($result['file_id']) ? (int)$result['file_id'] : null,
        'details' => $importDetails,
        'warning' => $warning !== '' ? $warning : null,
        'recovery' => $recovery,
        'message' => $message,
    ]);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    $message = unverified_action_error_text($error);
    error_log('[UnrealDB][' . $requestId . '] unverified action failed: ' . get_class($error) . ': ' . $message);
    unverified_action_reply([
        'ok' => false,
        'error' => $message,
        'request_id' => $requestId,
    ], 400);
}
