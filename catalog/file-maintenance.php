<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: HTTP transport for catalog file maintenance actions and progress polling.
 * Why: Maintenance identity, locking, retry, transaction and dependency orchestration belongs behind shared services.
 * Role: Presentation adapter; validates request/session state and serializes the service result.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UploadProgress.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncDependencyBatchService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFullSyncProjectionService;

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

function catalog_maintenance_game_id(array $input): int
{
    $gameId = filter_var($input['game_id'] ?? null, FILTER_VALIDATE_INT);
    if ($gameId === false || $gameId === null || $gameId < 1) {
        throw new RuntimeException('A valid game ID is required.');
    }
    return (int)$gameId;
}

/** @return list<int> */
function catalog_maintenance_file_ids(array $input): array
{
    $raw = trim((string)($input['file_ids_json'] ?? ''));
    if ($raw === '') {
        throw new RuntimeException('Dependency batch file IDs are required.');
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $error) {
        throw new RuntimeException('Dependency batch file IDs are not valid JSON.', 0, $error);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Dependency batch file IDs must be a JSON array.');
    }

    $ids = [];
    foreach ($decoded as $value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Dependency batch contains an invalid file ID.');
        }
        $fileId = (int)$value;
        if ($fileId < 1) {
            throw new RuntimeException('Dependency batch contains an invalid file ID.');
        }
        $ids[] = $fileId;
    }
    return $ids;
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

    if ($operation === 'sync_refresh_dependencies_batch') {
        $payload = (new CatalogFullSyncDependencyBatchService($db, $config, $progress))->refresh(
            catalog_maintenance_game_id($_POST),
            catalog_maintenance_file_ids($_POST)
        );
    } elseif ($operation === 'sync_prepare_dependencies' || $operation === 'sync_finalize_game') {
        $fullSync = new CatalogFullSyncProjectionService($db, $progress);
        $gameId = catalog_maintenance_game_id($_POST);
        $payload = $operation === 'sync_prepare_dependencies'
            ? $fullSync->prepareDependencies($gameId)
            : $fullSync->finalize($gameId);
    } else {
        $service = new CatalogFileMaintenanceActionService($db, $config, $userId, $progress);
        $payload = $service->execute($operation, $_POST);
    }

    if (str_starts_with($operation, 'sync_')) {
        catalog_maintenance_reply($payload);
    }
    catalog_maintenance_redirect_or_reply($payload, $operation, $postProgressToken);
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
