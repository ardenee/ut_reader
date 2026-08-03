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

function catalog_maintenance_should_redirect(string $operation, string $progressToken): bool
{
    if ($progressToken !== '' || str_starts_with($operation, 'sync_')) {
        return false;
    }

    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($requestedWith === 'xmlhttprequest') {
        return false;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return !(str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));
}

function catalog_maintenance_redirect_or_reply(array $payload, string $operation, string $progressToken, int $status = 200): never
{
    if ($status >= 200 && $status < 300 && isset($payload['return_url']) && catalog_maintenance_should_redirect($operation, $progressToken)) {
        if (!empty($payload['message'])) {
            catalog_start_session();
            $_SESSION['catalog_maintenance_flash'] = [
                'type' => 'success',
                'message' => (string)$payload['message'],
            ];
            session_write_close();
        }
        header('Location: ' . (string)$payload['return_url']);
        exit;
    }

    catalog_maintenance_reply($payload, $status);
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

function catalog_maintenance_post_string(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

/** @return array{0:array<string,mixed>,1:bool} */
function catalog_maintenance_current_file(PDO $db, int $fileId, string $notFoundMessage): array
{
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if ($file) {
        return [$file, false];
    }

    $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
    $packageName = catalog_maintenance_post_string('package_name');
    $md5 = catalog_maintenance_post_string('md5');
    $packageGuid = catalog_maintenance_post_string('package_guid');

    if ($gameId === false || $gameId === null || $gameId < 1 || $packageName === '' || $md5 === '') {
        throw new RuntimeException($notFoundMessage);
    }

    $where = 'game_id=? AND package_name=? AND md5=? AND scan_status="verified"';
    $args = [(int)$gameId, $packageName, $md5];
    if ($packageGuid !== '') {
        $where .= ' AND package_guid=?';
        $args[] = $packageGuid;
    } else {
        $where .= ' AND (package_guid IS NULL OR package_guid="")';
    }

    $replacement = catalog_one(
        $db,
        'SELECT * FROM ue_files WHERE ' . $where . ' ORDER BY uploaded_at DESC, id DESC LIMIT 1',
        $args
    );
    if (!$replacement) {
        throw new RuntimeException($notFoundMessage);
    }

    return [$replacement, true];
}

/** @return list<array<string,mixed>> */
function catalog_maintenance_alias_rows(PDO $db, int $fileId): array
{
    catalog_package_aliases_ensure($db);
    return catalog_all($db, 'SELECT * FROM ue_file_package_aliases WHERE file_id=? ORDER BY id', [$fileId]);
}

/** @return list<array<string,mixed>> */
function catalog_maintenance_location_rows(PDO $db, int $fileId): array
{
    return catalog_all($db, 'SELECT * FROM ue_file_locations WHERE file_id=? ORDER BY id', [$fileId]);
}

/**
 * @param list<array<string,mixed>> $aliases
 * @return list<string>
 */
function catalog_maintenance_package_names(array $file, array $aliases): array
{
    $names = [(string)($file['package_name'] ?? '')];
    foreach ($aliases as $alias) {
        $names[] = (string)($alias['package_name'] ?? '');
    }

    $names = array_map('trim', $names);
    $names = array_filter($names, static fn(string $name): bool => $name !== '');
    return array_values(array_unique($names));
}

/**
 * @param list<string> $packageNames
 * @return list<int>
 */
function catalog_maintenance_referring_file_ids(PDO $db, int $gameId, array $packageNames, int $excludeFileId = 0): array
{
    $packageNames = array_values(array_unique(array_filter(
        array_map('trim', $packageNames),
        static fn(string $name): bool => $name !== ''
    )));
    if ($packageNames === []) {
        return [];
    }

    $conditions = [];
    $args = [$gameId];
    foreach ($packageNames as $packageName) {
        $conditions[] = '(t.value_hash=? AND t.value_length=? AND t.value_prefix=?)';
        $args[] = md5($packageName, true);
        $args[] = strlen($packageName);
        $args[] = substr($packageName, 0, 200);
    }

    $sql = 'SELECT DISTINCT l.file_id'
        . ' FROM ue_dependency_links l'
        . ' JOIN ue_terms t ON t.id=l.required_package_term_id'
        . ' JOIN ue_files owner ON owner.id=l.file_id'
        . ' WHERE owner.game_id=? AND (' . implode(' OR ', $conditions) . ')';
    if ($excludeFileId > 0) {
        $sql .= ' AND l.file_id<>?';
        $args[] = $excludeFileId;
    }

    return array_map(
        static fn(array $row): int => (int)$row['file_id'],
        catalog_all($db, $sql, $args)
    );
}

/**
 * Remap source locations and exact package aliases after the scanner assigns a
 * new file ID. Alias rows have no foreign key, so leaving them on the old ID
 * makes exact alias dependency matching silently fail.
 *
 * @param list<array<string,mixed>> $aliases
 * @param list<array<string,mixed>> $locations
 */
function catalog_maintenance_restore_identity_rows(PDO $db, int $oldFileId, int $newFileId, array $aliases, array $locations): void
{
    $replacement = catalog_one($db, 'SELECT package_name FROM ue_files WHERE id=?', [$newFileId]);
    if (!$replacement) {
        throw new RuntimeException('Replacement package disappeared before its aliases and source locations could be restored.');
    }

    $db->beginTransaction();
    try {
        catalog_package_aliases_ensure($db);
        $db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$oldFileId]);

        $aliasInsert = $db->prepare(
            'INSERT INTO ue_file_package_aliases(file_id,game_id,package_name,original_name,package_guid,md5,file_size)'
            . ' VALUES(?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE original_name=VALUES(original_name),package_guid=VALUES(package_guid),md5=VALUES(md5),file_size=VALUES(file_size)'
        );
        foreach ($aliases as $alias) {
            $packageName = trim((string)($alias['package_name'] ?? ''));
            if ($packageName === '' || strcasecmp($packageName, (string)$replacement['package_name']) === 0) {
                continue;
            }
            $aliasInsert->execute([
                $newFileId,
                (int)$alias['game_id'],
                $packageName,
                (string)$alias['original_name'],
                $alias['package_guid'],
                (string)$alias['md5'],
                (int)$alias['file_size'],
            ]);
        }

        $locationInsert = $db->prepare(
            'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at)'
            . ' VALUES(?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source),last_seen_at=VALUES(last_seen_at)'
        );
        foreach ($locations as $location) {
            $locationInsert->execute([
                $newFileId,
                (int)$location['source_id'],
                (string)$location['source_relative_path'],
                (int)$location['exists_in_source'],
                $location['last_seen_at'],
            ]);
        }

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

/**
 * Re-import one package using its preserved mounted source path, then refresh
 * every dependency owner that references either its old or new primary/alias
 * identities. Full Sync skips the related refresh because its final pass covers
 * every remaining file.
 *
 * @return array{status:string,file_id:?int,game_id:int,original_name:string,message:string}
 */
function catalog_maintenance_reimport_with_identity_refresh(PDO $db, array $config, int $fileId, ?int $userId, ?callable $progress): array
{
    [$file, $resolvedFromStaleId] = catalog_maintenance_current_file(
        $db,
        $fileId,
        'File no longer exists in the catalog. Refresh the file list before retrying.'
    );

    if ($resolvedFromStaleId) {
        return [
            'status' => 'stale_replaced',
            'file_id' => (int)$file['id'],
            'game_id' => (int)$file['game_id'],
            'original_name' => (string)$file['original_name'],
            'message' => 'Package already has a current catalog record after an earlier re-import.',
        ];
    }

    $oldFileId = (int)$file['id'];
    $oldAliases = catalog_maintenance_alias_rows($db, $oldFileId);
    $oldLocations = catalog_maintenance_location_rows($db, $oldFileId);
    $oldPackageNames = catalog_maintenance_package_names($file, $oldAliases);
    $referringFileIds = catalog_maintenance_referring_file_ids($db, (int)$file['game_id'], $oldPackageNames, $oldFileId);

    $storedPath = catalog_file_maintenance_storage_path($config, $file);
    if ($storedPath === null || !is_file($storedPath)) {
        $removed = catalog_file_maintenance_remove($db, $config, $oldFileId, $progress);
        catalog_package_aliases_ensure($db);
        $db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$oldFileId]);
        return [
            'status' => 'removed_missing',
            'file_id' => null,
            'game_id' => (int)$removed['game_id'],
            'original_name' => (string)$removed['original_name'],
            'message' => 'Stored package was missing; removed its catalog record, aliases, compact metadata, locations, and dependency references.',
        ];
    }

    $result = catalog_file_maintenance_reimport($db, $config, $oldFileId, $userId, $progress);
    $newFileId = (int)$result['file_id'];
    catalog_maintenance_restore_identity_rows($db, $oldFileId, $newFileId, $oldAliases, $oldLocations);

    if ((string)($_POST['operation'] ?? '') !== 'sync_reimport') {
        $newFile = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$newFileId]);
        if (!$newFile) {
            throw new RuntimeException('Re-imported package disappeared before dependency refresh.');
        }
        $newAliases = catalog_maintenance_alias_rows($db, $newFileId);
        $newPackageNames = catalog_maintenance_package_names($newFile, $newAliases);
        $referringFileIds = array_merge(
            $referringFileIds,
            catalog_maintenance_referring_file_ids($db, (int)$newFile['game_id'], $newPackageNames, $newFileId),
            [$newFileId]
        );
        $referringFileIds = array_values(array_unique(array_map('intval', $referringFileIds)));
        $referringFileIds = array_values(array_filter(
            $referringFileIds,
            static fn(int $id): bool => $id > 0 && (bool)catalog_one($db, 'SELECT id FROM ue_files WHERE id=?', [$id])
        ));
        catalog_file_maintenance_refresh_ids(
            $db,
            $config,
            $referringFileIds,
            $progress,
            70,
            100,
            'Refreshing old/new exact dependency identities'
        );
        $result['message'] .= '; dependency files refreshed=' . count($referringFileIds);
    }

    return [
        'status' => 'reimported',
        'file_id' => $newFileId,
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

    $lock = catalog_one($db, 'SELECT GET_LOCK(?, ?) acquired', [CATALOG_MAINTENANCE_WRITE_LOCK, CATALOG_MAINTENANCE_LOCK_WAIT_SECONDS]);
    if ((int)($lock['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Another catalog maintenance task is still running. Please retry this package shortly.');
    }

    try {
        return catalog_maintenance_retry_deadlock($progress, $operation);
    } finally {
        try {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([CATALOG_MAINTENANCE_WRITE_LOCK]);
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
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);

    if ($operation === 'sync_reimport') {
        $fileId = catalog_maintenance_file_id();
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static fn() => catalog_maintenance_reimport_with_identity_refresh($db, $config, $fileId, $userId, $progress)
        );
        catalog_maintenance_reply([
            'ok' => true,
            'status' => $result['status'],
            'file_id' => $result['file_id'],
            'game_id' => $result['game_id'],
            'original_name' => $result['original_name'],
            'message' => $result['message'],
        ]);
    }

    if ($operation === 'sync_refresh_dependencies') {
        $fileId = catalog_maintenance_file_id();
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static function () use ($db, $config, $fileId, $progress): array {
                [$file] = catalog_maintenance_current_file(
                    $db,
                    $fileId,
                    'The re-imported package is no longer present in the catalog. Refresh Full Sync to rebuild its package list.'
                );
                scanner_rebuild_dependencies($db, $config, (int)$file['id'], $progress, 0, 100, 'Final dependency refresh for ' . $file['original_name']);
                return $file;
            }
        );
        catalog_maintenance_reply([
            'ok' => true,
            'file_id' => (int)$result['id'],
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
            static fn() => catalog_maintenance_reimport_with_identity_refresh($db, $config, $fileId, $userId, $progress)
        );
        catalog_maintenance_redirect_or_reply([
            'ok' => true,
            'status' => $result['status'],
            'message' => $result['status'] === 'reimported'
                ? 'Rebuilt ' . $result['original_name'] . ' from its preserved source path. ' . $result['message']
                : $result['message'],
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ], $operation, $postProgressToken);
    }

    if ($operation === 'remove') {
        $result = catalog_maintenance_with_write_lock(
            $db,
            $progress,
            static fn() => catalog_file_maintenance_remove($db, $config, $fileId, $progress)
        );
        catalog_package_aliases_ensure($db);
        $db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
        catalog_maintenance_redirect_or_reply([
            'ok' => true,
            'message' => 'Removed ' . $result['original_name'] . ' from storage and the catalog.' . $result['warning'],
            'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
        ], $operation, $postProgressToken);
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
