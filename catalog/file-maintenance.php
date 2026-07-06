<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogFileMaintenance.php';
require_once __DIR__ . '/lib/UploadProgress.php';

const CATALOG_MAINTENANCE_WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';
const CATALOG_MAINTENANCE_LOCK_WAIT_SECONDS = 45;
const CATALOG_MAINTENANCE_DEADLOCK_RETRIES = 3;

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

/**
 * Full Sync and the individual re-import action share one lifecycle:
 *
 * 1. Check that the stored package still exists.
 * 2. If it is gone, delete its catalog record and all dependent records.
 * 3. Otherwise remove the old record and run the normal upload scanner.
 *
 * @return array{status:string,file_id:?int,game_id:int,original_name:string,message:string}
 */
function catalog_maintenance_reimport_or_remove_missing(PDO $db, array $config, int $fileId, ?int $userId, ?callable $progress): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File no longer exists in the catalog. Refresh the file list before retrying.');
    }

    $storedPath = catalog_file_maintenance_storage_path($config, $file);
    if ($storedPath === null || !is_file($storedPath)) {
        if ($progress !== null) {
            $progress([
                'stage' => 'missing_storage',
                'done' => 0,
                'total' => 100,
                'percent' => 0,
                'message' => 'Stored package is missing. Removing its catalog record and references.',
            ]);
        }

        $removed = catalog_file_maintenance_remove($db, $config, $fileId, $progress);
        return [
            'status' => 'removed_missing',
            'file_id' => null,
            'game_id' => (int)$removed['game_id'],
            'original_name' => (string)$removed['original_name'],
            'message' => 'Stored package was missing; removed its catalog record, tables, locations, and dependency references.',
        ];
    }

    $result = catalog_file_maintenance_reimport($db, $config, $fileId, $userId, $progress);
    return [
        'status' => 'reimported',
        'file_id' => (int)$result['file_id'],
        'game_id' => (int)$result['game_id'],
        'original_name' => (string)$result['original_name'],
        'message' => (string)$result['message'],
    ];
}

function catalog_maintenance_is_deadlock(Throwable $error): bool
{
    $code = (string)$error->getCode();
    $message = strtolower($error->getMessage());

    return $code === '40001'
        || str_contains($message, 'deadlock found')
        || str_contains($message, 'serialization failure')
        || str_contains($message, 'error: 1213');
}

/**
 * Retry only transient InnoDB deadlocks. Individual re-imports restore their
 * own snapshot before throwing, so a retry starts from the original package.
 */
function catalog_maintenance_retry_deadlock(?callable $progress, callable $operation): mixed
{
    for ($attempt = 1; $attempt <= CATALOG_MAINTENANCE_DEADLOCK_RETRIES; $attempt++) {
        try {
            return $operation();
        } catch (Throwable $error) {
            if (!catalog_maintenance_is_deadlock($error) || $attempt === CATALOG_MAINTENANCE_DEADLOCK_RETRIES) {
                throw $error;
            }

            if ($progress !== null) {
                $progress([
                    'stage' => 'retrying_database_write',
                    'done' => 0,
                    'total' => 100,
                    'percent' => 0,
                    'message' => 'Database write conflict detected; retrying maintenance request (' . $attempt . '/' . CATALOG_MAINTENANCE_DEADLOCK_RETRIES . ').',
                ]);
            }
            usleep(250000 * $attempt);
        }
    }

    throw new RuntimeException('Maintenance retry limit reached.');
}

/**
 * MySQL advisory locks are connection-scoped and automatically release if a
 * request dies. This serializes scanner-based catalog mutations from full sync,
 * manual re-import, and delete actions without keeping a long-running lock
 * between browser requests.
 */
function catalog_maintenance_with_write_lock(PDO $db, ?callable $progress, callable $operation): mixed
{
    if ($progress !== null) {
        $progress([
            'stage' => 'waiting_for_catalog_lock',
            'done' => 0,
            'total' => 100,
            'percent' => 0,
            'message' => 'Waiting for another catalog maintenance write to finish.',
        ]);
    }

    $lock = catalog_one(
        $db,
        'SELECT GET_LOCK(?, ?) acquired',
        [CATALOG_MAINTENANCE_WRITE_LOCK, CATALOG_MAINTENANCE_LOCK_WAIT_SECONDS]
    );
    if ((int)($lock['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Another catalog maintenance task is still running. Please retry this package shortly.');
    }

    try {
        if ($progress !== null) {
            $progress([
                'stage' => 'catalog_lock_acquired',
                'done' => 0,
                'total' => 100,
                'percent' => 0,
                'message' => 'Catalog write lock acquired. Starting maintenance.',
            ]);
        }
        return catalog_maintenance_retry_deadlock($progress, $operation);
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([CATALOG_MAINTENANCE_WRITE_LOCK]);
        } catch (Throwable $releaseError) {
            error_log('[UnrealDB][' . catalog_request_id() . '] could not release catalog maintenance lock: ' . $releaseError->getMessage());
        }
    }
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
     * time. The advisory lock prevents two games from mutating dependency and
     * catalog tables concurrently while retaining the short-request design.
     */
    if ($operation === 'sync_reimport') {
        $fileId = catalog_maintenance_file_id();
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static fn() => catalog_maintenance_reimport_or_remove_missing($db, $config, $fileId, $userId, $progress)
        );
        catalog_maintenance_reply([
            'ok' => true,
            'status' => $result['status'],
            'file_id' => $result['file_id'],
            'game_id' => $result['game_id'],
            'original_name' => $result['original_name'],
            'message' => $result['status'] === 'removed_missing'
                ? $result['message']
                : 'Re-imported ' . $result['original_name'] . ' using the normal scanner.',
        ]);
    }

    if ($operation === 'sync_refresh_dependencies') {
        $fileId = catalog_maintenance_file_id();
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static function () use ($db, $config, $fileId, $progress): array {
                $file = catalog_one($db, 'SELECT id, game_id, original_name FROM ue_files WHERE id=?', [$fileId]);
                if (!$file) {
                    throw new RuntimeException('The re-imported package is no longer present in the catalog. Refresh Full Sync to rebuild its package list.');
                }
                scanner_rebuild_dependencies($db, $config, $fileId, $progress, 0, 100, 'Final dependency refresh for ' . $file['original_name']);
                return $file;
            }
        );
        catalog_maintenance_reply([
            'ok' => true,
            'file_id' => $fileId,
            'game_id' => (int)$result['game_id'],
            'original_name' => (string)$result['original_name'],
            'message' => 'Refreshed dependencies for ' . $result['original_name'] . '.',
        ]);
    }

    if ($operation === 'sync_game') {
        throw new RuntimeException('Full Sync now runs in short package-by-package requests. Refresh the Full Sync page and start it again.');
    }

    $fileId = catalog_maintenance_file_id();

    if ($operation === 'reimport' || $operation === 'rebuild') {
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static fn() => catalog_maintenance_reimport_or_remove_missing($db, $config, $fileId, $userId, $progress)
        );
        $message = $result['status'] === 'removed_missing'
            ? $result['message']
            : 'Re-imported ' . $result['original_name'] . ' using the normal scanner.';
        catalog_maintenance_reply([
            'ok' => true,
            'status' => $result['status'],
            'message' => $message,
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ]);
    }

    if ($operation === 'remove') {
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static fn() => catalog_file_maintenance_remove($db, $config, $fileId, $progress)
        );
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
