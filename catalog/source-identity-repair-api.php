<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/UploadProgress.php';

const SOURCE_IDENTITY_API_LOCK = 'unrealdb_catalog_maintenance_write_v1';
const SOURCE_IDENTITY_API_LOCK_WAIT = 45;

function source_identity_api_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function source_identity_api_post_int(string $name): int
{
    $value = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < 1) {
        throw new RuntimeException('A valid ' . str_replace('_', ' ', $name) . ' is required.');
    }
    return (int)$value;
}

function source_identity_api_progress(string $token): ?callable
{
    if ($token === '') {
        return null;
    }
    return static function (array $state) use ($token): void {
        upload_progress_write($token, $state);
    };
}

/** @return mixed */
function source_identity_api_with_lock(PDO $db, callable $operation): mixed
{
    $lock = catalog_one($db, 'SELECT GET_LOCK(?, ?) acquired', [SOURCE_IDENTITY_API_LOCK, SOURCE_IDENTITY_API_LOCK_WAIT]);
    if ((int)($lock['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Another catalog maintenance task is running. Try again after it finishes.');
    }

    try {
        return $operation();
    } finally {
        try {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([SOURCE_IDENTITY_API_LOCK]);
        } catch (Throwable $releaseError) {
            error_log('[UnrealDB source identity API] lock release failed: ' . $releaseError->getMessage());
        }
    }
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        source_identity_api_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['progress'] ?? '') !== '') {
        $token = upload_progress_token((string)$_GET['progress']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        source_identity_api_reply(upload_progress_read($token));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        source_identity_api_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('source-identity-repair');
    $operation = trim((string)($_POST['operation'] ?? ''));
    $token = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $progress = source_identity_api_progress($token);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);

    if ($operation === 'list_files') {
        $gameId = source_identity_api_post_int('game_id');
        $files = catalog_all(
            $db,
            'SELECT id,original_name,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,original_name,id',
            [$gameId]
        );
        source_identity_api_reply(['ok' => true, 'game_id' => $gameId, 'files' => $files]);
    }

    if ($operation === 'repair_file_step') {
        $fileId = source_identity_api_post_int('file_id');
        $file = catalog_one($db, 'SELECT id,original_name,package_name,game_id FROM ue_files WHERE id=?', [$fileId]);
        if (!$file) {
            throw new RuntimeException('File no longer exists in the catalog.');
        }
        $result = source_identity_api_with_lock(
            $db,
            static fn(): array => catalog_source_identity_rebuild_file($db, $config, $fileId, $progress, false)
        );
        source_identity_api_reply([
            'ok' => true,
            'file_id' => $fileId,
            'file' => (string)$file['original_name'],
            'changed' => (bool)$result['changed'],
            'old_package_name' => (string)$result['old_package_name'],
            'new_package_name' => (string)$result['new_package_name'],
            'alias_count' => (int)$result['alias_count'],
        ]);
    }

    if ($operation === 'refresh_dependencies_step') {
        $fileId = source_identity_api_post_int('file_id');
        $file = catalog_one($db, 'SELECT id,original_name,package_name,game_id FROM ue_files WHERE id=?', [$fileId]);
        if (!$file) {
            throw new RuntimeException('File no longer exists in the catalog.');
        }
        source_identity_api_with_lock(
            $db,
            static function () use ($db, $config, $fileId, $progress, $file): void {
                scanner_rebuild_dependencies(
                    $db,
                    $config,
                    $fileId,
                    $progress,
                    0,
                    100,
                    'Refreshing dependencies for ' . (string)$file['original_name']
                );
            }
        );
        source_identity_api_reply([
            'ok' => true,
            'file_id' => $fileId,
            'file' => (string)$file['original_name'],
            'message' => 'Dependencies refreshed.',
        ]);
    }

    if ($operation === 'repair_single_file') {
        $fileId = source_identity_api_post_int('file_id');
        $result = source_identity_api_with_lock(
            $db,
            static fn(): array => catalog_source_identity_rebuild_file($db, $config, $fileId, $progress, true)
        );
        source_identity_api_reply([
            'ok' => true,
            'changed' => (bool)$result['changed'],
            'old_package_name' => (string)$result['old_package_name'],
            'new_package_name' => (string)$result['new_package_name'],
            'alias_count' => (int)$result['alias_count'],
            'dependency_files_refreshed' => (int)$result['dependency_files_refreshed'],
            'message' => $result['changed']
                ? 'Canonical database identity repaired: ' . $result['old_package_name'] . ' → ' . $result['new_package_name']
                : 'This file already matches its mounted source path.',
        ]);
    }

    throw new RuntimeException('Unknown source identity repair operation.');
} catch (Throwable $error) {
    error_log('[UnrealDB source identity API] ' . $error->getMessage());
    source_identity_api_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
