<?php
declare(strict_types=1);

ini_set('display_errors', '0');
@set_time_limit(0);
ob_start();
$GLOBALS['unverified_action_replied'] = false;
$GLOBALS['unverified_action_progress_token'] = '';

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';
require_once __DIR__ . '/lib/UploadProgress.php';

function unverified_action_json(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return is_string($json) ? $json : '{"ok":false,"error":"The server could not encode the action response."}';
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

function unverified_action_emit(?callable $progress, string $stage, int $percent, string $message): void
{
    if ($progress === null) {
        return;
    }
    $progress([
        'stage' => $stage,
        'done' => max(0, min(100, $percent)),
        'total' => 100,
        'percent' => max(0, min(100, $percent)),
        'message' => $message,
    ]);
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
    $stmt = $db->prepare('DELETE d FROM ue_dependencies d INNER JOIN ue_imports i ON i.id=d.import_id WHERE i.file_id=?');
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
    Throwable $initialError,
    ?callable $progress = null
): array {
    $error = $initialError;
    $removed = 0;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $importId = unverified_action_dependency_collision_import_id($error);
        if ($importId < 1) {
            break;
        }

        unverified_action_emit($progress, 'dependency_recovery', 58, 'Repairing a stale dependency collision');
        $stmt = $db->prepare('DELETE FROM ue_dependencies WHERE import_id=?');
        $stmt->execute([$importId]);
        $removed += $stmt->rowCount();
        $removed += unverified_action_clear_file_dependencies($db, $fileId);

        try {
            scanner_rebuild_dependencies($db, $config, $fileId, $progress, 60, 72, 'Rebuilding imported file dependencies');
            scanner_rebuild_affected_dependencies($db, $config, $fileId, $progress, 72, 99);
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

/** @return array<string,mixed> */
function unverified_action_resolve_source(PDO $db, array $config, string $token): array
{
    $decoded = uvf_base64url_decode($token);
    $payload = $decoded === null ? null : json_decode($decoded, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid unverified file reference.');
    }

    $gameId = (int)($payload['game_id'] ?? -1);
    $queueName = basename((string)($payload['name'] ?? ''));
    if ($gameId < 0 || $queueName === '' || $queueName !== (string)($payload['name'] ?? '') || str_ends_with(strtolower($queueName), '.txt')) {
        throw new RuntimeException('Invalid unverified file reference.');
    }

    if ($gameId === 0) {
        $game = uvf_bucket_game();
    } else {
        $game = catalog_one($db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new RuntimeException('The source game no longer exists.');
        }
    }

    $dir = uvf_unverified_dir($config, $game);
    $path = $dir . DIRECTORY_SEPARATOR . $queueName;
    if (!is_file($path) || !uvf_path_inside($path, $dir)) {
        throw new RuntimeException('The selected unverified file is no longer available.');
    }

    $row = catalog_one(
        $db,
        'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=? LIMIT 1',
        [catalog_unverified_queue_key($gameId, $queueName)]
    );
    $originalName = trim((string)($row['original_name'] ?? ''));
    if ($originalName === '') {
        $originalName = uvf_original_name_from_queue_name($queueName);
    }

    $reasonPath = $path . '.txt';
    $reason = trim((string)($row['unverified_reason'] ?? ''));
    if ($reason === '' && is_file($reasonPath) && uvf_path_inside($reasonPath, $dir)) {
        $reason = trim((string)@file_get_contents($reasonPath, false, null, 0, 65535));
    }

    return [
        'token' => $token,
        'game' => $game,
        'queue_name' => $queueName,
        'original_name' => $originalName,
        'path' => $path,
        'reason_path' => $reasonPath,
        'reason' => $reason,
        'size' => (int)(filesize($path) ?: 0),
        'modified_at' => (int)(filemtime($path) ?: 0),
        'extension' => (string)($row['extension'] ?? catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION))),
        'package_name' => (string)($row['package_name'] ?? scanner_logical_package_name($originalName)),
        'md5' => strtolower(trim((string)($row['md5'] ?? ''))),
        'package_guid' => trim((string)($row['package_guid'] ?? '')),
        'source_relative_path' => (string)($row['source_relative_path'] ?? ''),
    ];
}

/** @return array{status:string,file_id:int,original_name:string,target_game:string,message:string} */
function unverified_action_promote_item(
    PDO $db,
    array $config,
    array $source,
    int $targetGameId,
    ?int $userId,
    bool $allowProfileOverride,
    ?callable $progress
): array {
    unverified_action_emit($progress, 'staging', 3, 'Loading staged package tables');
    $indexed = catalog_unverified_index_item($db, $config, $source, $userId, false);
    $row = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified"', [(int)$indexed['file_id']]);
    if (!$row) {
        throw new RuntimeException('The unverified database row is unavailable.');
    }

    $target = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$targetGameId]);
    if (!$target) {
        throw new RuntimeException('Target game not found.');
    }

    $physicalOriginal = (string)$source['original_name'];
    unverified_action_emit($progress, 'preparing', 8, 'Preparing queued package');
    $prepared = catalog_unverified_prepare_path((string)$source['path'], $physicalOriginal);
    try {
        unverified_action_emit($progress, 'classifying', 12, 'Checking the selected game profile');
        $classification = gp_classify_file($db, $targetGameId, $prepared['path'], $prepared['name']);
        if (!$allowProfileOverride && empty($classification['ok_for_selected_game'])) {
            throw new RuntimeException('Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown') . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '. ' . implode(' ', (array)$classification['notes']));
        }

        $sourceRelativePath = scanner_normalize_source_relative_path((string)($row['source_relative_path'] ?? ''));
        $detectedEngine = strtoupper((string)($classification['detected_engine'] ?? $row['detected_engine_key'] ?? ''));
        if (in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath === '') {
            throw new RuntimeException('UE4 package identity requires a mounted source-relative path. Requeue this file through folder upload, Local Source Scan or PAK import before verifying it.');
        }

        $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true)
            ? scanner_ue_package_name_from_source_relative($sourceRelativePath)
            : (string)$row['package_name'];

        unverified_action_emit($progress, 'hashing', 20, 'Verifying MD5 and SHA-1');
        $md5 = md5_file($prepared['path']);
        $sha1 = sha1_file($prepared['path']);
        if (!is_string($md5) || !is_string($sha1)) {
            throw new RuntimeException('Could not verify queued file hashes.');
        }
        $guid = trim((string)($row['package_guid'] ?? ''));

        unverified_action_emit($progress, 'duplicate_check', 30, 'Checking for an existing file or package alias');
        if ($guid !== '') {
            $duplicate = catalog_one($db, 'SELECT * FROM ue_files WHERE game_id=? AND scan_status="verified" AND package_guid=? AND md5=? LIMIT 1', [$targetGameId, $guid, strtolower($md5)]);
        } else {
            $duplicate = catalog_one($db, 'SELECT * FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1', [$targetGameId, strtolower($md5)]);
        }
        if ($duplicate) {
            $status = 'duplicate';
            $message = 'Duplicate in selected game';
            if (strcasecmp((string)$duplicate['package_name'], $packageName) !== 0) {
                catalog_package_alias_add($db, (int)$duplicate['id'], $targetGameId, $packageName, (string)$row['original_name'], $guid, strtolower($md5), (int)$row['file_size']);
                $status = 'alias';
                $message = 'Package alias added for existing file identity';
            }
            catalog_unverified_discard_item($db, $config, $source);
            unverified_action_emit($progress, 'done', 100, $message);
            return ['status' => $status, 'file_id' => (int)$duplicate['id'], 'original_name' => (string)$row['original_name'], 'target_game' => (string)$target['name'], 'message' => $message];
        }

        unverified_action_emit($progress, 'storage', 38, 'Moving package into verified storage');
        $ext = catalog_clean_unreal_extension((string)pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
        $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . scanner_slug_text((string)$target['slug']) . '/verified';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create verified storage folder.');
        }
        $storedName = strtolower($md5) . '.' . $ext;
        $dest = $dir . '/' . $storedName;
        $moved = false;
        if (is_file($dest)) {
            if (!@unlink((string)$source['path'])) {
                throw new RuntimeException('Could not discard queued physical duplicate.');
            }
        } elseif ($prepared['temporary']) {
            if (!@copy($prepared['path'], $dest)) {
                throw new RuntimeException('Could not store decompressed package.');
            }
            if (!@unlink((string)$source['path'])) {
                @unlink($dest);
                throw new RuntimeException('Could not remove compressed queue wrapper.');
            }
            $moved = true;
        } else {
            if (!@rename((string)$source['path'], $dest)) {
                throw new RuntimeException('Could not move queued package into verified storage.');
            }
            $moved = true;
        }

        unverified_action_emit($progress, 'database', 46, 'Promoting the staged database record');
        try {
            $db->beginTransaction();
            $notes = trim((string)$row['scan_notes'] . "\nVerified from unverified queue for " . (string)$target['name'] . '.');
            $db->prepare('UPDATE ue_files SET game_id=?,package_name=?,stored_name=?,relative_path=?,detected_engine_key=?,detected_package_version=?,detected_licensee_version=?,detection_confidence=?,compatibility_status=?,compatibility_label=?,detection_notes=?,file_size=?,md5=?,sha1=?,scan_status="verified",scan_notes=?,uploaded_by=COALESCE(?,uploaded_by),unverified_queue_key=NULL,unverified_queue_game_id=NULL,unverified_queue_name=NULL,unverified_reason=NULL WHERE id=?')->execute([$targetGameId, $packageName, $storedName, 'storage/games/' . scanner_slug_text((string)$target['slug']) . '/verified/' . $storedName, $classification['detected_engine'], $classification['package_version'], $classification['licensee_version'], $classification['confidence'], $classification['compatibility_status'] ?? 'native', $classification['compatibility_label'] ?? null, implode("\n", (array)$classification['notes']), (int)(filesize($dest) ?: 0), strtolower($md5), strtolower($sha1), $notes, $userId, (int)$row['id']]);
            if ($packageName !== (string)$row['package_name']) {
                $exports = catalog_all($db, 'SELECT id,local_path FROM ue_exports WHERE file_id=?', [(int)$row['id']]);
                $update = $db->prepare('UPDATE ue_exports SET full_path=? WHERE id=?');
                foreach ($exports as $export) {
                    $update->execute([scanner_join_path_parts([$packageName, (string)$export['local_path']]), (int)$export['id']]);
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($moved && is_file($dest) && !is_file((string)$source['path'])) {
                @rename($dest, (string)$source['path']);
            }
            throw $error;
        }

        if (is_file((string)$source['reason_path'])) {
            @unlink((string)$source['reason_path']);
        }

        scanner_rebuild_dependencies($db, $config, (int)$row['id'], $progress, 52, 68, 'Rebuilding imported file dependencies');
        scanner_rebuild_affected_dependencies($db, $config, (int)$row['id'], $progress, 68, 99);
        unverified_action_emit($progress, 'done', 100, 'Import and dependency refresh complete');
        return ['status' => 'verified', 'file_id' => (int)$row['id'], 'original_name' => (string)$row['original_name'], 'target_game' => (string)$target['name'], 'message' => 'Promoted existing unverified database row to verified; package tables were reused.'];
    } finally {
        if ($prepared['temporary'] && is_file($prepared['path'])) {
            @unlink($prepared['path']);
        }
    }
}

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['unverified_action_replied'])) {
        return;
    }
    $last = error_get_last();
    if (!is_array($last) || !in_array((int)($last['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    $progressToken = (string)($GLOBALS['unverified_action_progress_token'] ?? '');
    if ($progressToken !== '') {
        upload_progress_write($progressToken, [
            'stage' => 'failed',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'message' => 'The server stopped unexpectedly while processing this file.',
        ]);
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

$progressToken = '';
try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        unverified_action_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['progress'] ?? '') !== '') {
        $progressToken = upload_progress_token((string)$_GET['progress']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unverified_action_reply(upload_progress_read($progressToken));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }

    catalog_check_csrf('unverified-files');
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_unverified_schema_ensure($db);

    $action = trim((string)($_POST['action'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));
    $progressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $GLOBALS['unverified_action_progress_token'] = $progressToken;
    $progress = $progressToken !== '' ? static function (array $state) use ($progressToken): void {
        upload_progress_write($progressToken, $state);
    } : null;

    $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    $targetGameId = $targetGameId === false || $targetGameId === null ? 0 : (int)$targetGameId;
    $allowOverride = (string)($_POST['allow_profile_override'] ?? '') === '1';
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $importDetails = null;
    $warning = '';
    $recovery = null;

    if ($token === '') {
        throw new RuntimeException('A queued file is required.');
    }
    if (!in_array($action, ['move', 'import', 'delete'], true)) {
        throw new RuntimeException('Unknown unverified queue action.');
    }
    if (in_array($action, ['move', 'import'], true) && $targetGameId < 1) {
        throw new RuntimeException('Choose a target game first.');
    }

    unverified_action_emit($progress, 'starting', 0, 'Resolving queued file');
    $source = unverified_action_resolve_source($db, $config, $token);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($action === 'move') {
        unverified_action_emit($progress, 'moving', 25, 'Moving queued file');
        $result = catalog_unverified_move_item($db, $config, $source, $targetGameId);
        $message = 'Moved ' . $result['original_name'] . ' to ' . $result['target_game'] . '.';
        unverified_action_emit($progress, 'done', 100, $message);
    } elseif ($action === 'import') {
        $staged = catalog_unverified_find($db, (int)($source['game']['id'] ?? 0), (string)$source['queue_name']);
        $stagedFileId = (int)($staged['id'] ?? 0);
        if ($stagedFileId > 0) {
            unverified_action_emit($progress, 'dependency_cleanup', 2, 'Clearing stale staged dependency links');
            unverified_action_clear_file_dependencies($db, $stagedFileId);
        }

        $trustedImportConfig = $config;
        $trustedImportConfig['max_upload_bytes'] = PHP_INT_MAX;
        try {
            $result = unverified_action_promote_item(
                $db,
                $trustedImportConfig,
                $source,
                $targetGameId,
                $userId,
                $allowOverride,
                $progress
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
                $promotionError,
                $progress
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
            'verified' => 'Imported',
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
        unverified_action_emit($progress, 'done', 100, $message);
    } else {
        unverified_action_emit($progress, 'deleting', 25, 'Deleting queued file');
        $result = catalog_unverified_discard_item($db, $config, $source);
        $message = 'Deleted ' . $result['original_name'] . ' from unverified storage and the staging database.';
        unverified_action_emit($progress, 'done', 100, $message);
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
    if ($progressToken !== '') {
        upload_progress_write($progressToken, [
            'stage' => 'failed',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'message' => $message,
        ]);
    }
    error_log('[UnrealDB][' . $requestId . '] unverified action failed: ' . get_class($error) . ': ' . $message);
    unverified_action_reply([
        'ok' => false,
        'error' => $message,
        'request_id' => $requestId,
    ], 400);
}
