<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Processes state-changing browser actions for unverified files.
 * Why: HTTP/session/progress concerns remain here while source resolution and catalog mutations are delegated.
 * Role: Thin web action endpoint for move, import and delete operations.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Import\CatalogProfileMismatchException;

ini_set('display_errors', '0');
@set_time_limit(0);
ob_start();
$GLOBALS['unverified_action_replied'] = false;
$GLOBALS['unverified_action_progress_token'] = '';
$GLOBALS['unverified_action_started_at'] = microtime(true);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UploadProgress.php';

use UnrealDb\Catalog\Application\Unverified\CatalogUnverifiedActionService;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedActionSourceResolver;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedImporterAdapter;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueMutationService;

function unverified_action_json(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return is_string($json)
        ? $json
        : '{"ok":false,"error":"The server could not encode the action response."}';
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

function unverified_action_elapsed_ms(): int
{
    return max(0, (int)round(
        (microtime(true) - (float)($GLOBALS['unverified_action_started_at'] ?? microtime(true))) * 1000
    ));
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
        'elapsed_ms' => unverified_action_elapsed_ms(),
    ]);
}

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['unverified_action_replied'])) {
        return;
    }
    $last = error_get_last();
    if (!is_array($last)
        || !in_array(
            (int)($last['type'] ?? 0),
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        )) {
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
            'elapsed_ms' => unverified_action_elapsed_ms(),
        ]);
    }

    $requestId = function_exists('catalog_request_id')
        ? catalog_request_id()
        : bin2hex(random_bytes(8));
    error_log(
        '[UnrealDB][' . $requestId . '] fatal unverified action error: '
        . (string)($last['message'] ?? 'unknown fatal error')
    );
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

    // Read every value that depends on the administrator session, then release
    // the session before configuration, database, filesystem or package work.
    $action = trim((string)($_POST['action'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));
    $progressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $GLOBALS['unverified_action_progress_token'] = $progressToken;
    $targetGameId = filter_input(INPUT_POST, 'target_game_id', FILTER_VALIDATE_INT);
    $targetGameId = $targetGameId === false || $targetGameId === null
        ? 0
        : (int)$targetGameId;
    $allowOverride = (string)($_POST['allow_profile_override'] ?? '') === '1';
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($token === '') {
        throw new RuntimeException('A queued file is required.');
    }
    if (!in_array($action, ['move', 'import', 'delete'], true)) {
        throw new RuntimeException('Unknown unverified queue action.');
    }
    if ($action === 'move' && $targetGameId < 1) {
        throw new RuntimeException('Choose one target game before moving a queued file.');
    }
    if ($action === 'import' && $targetGameId < 1 && $targetGameId !== -1) {
        throw new RuntimeException('Choose a target game or All exact compatible games first.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $progress = $progressToken !== ''
        ? static function (array $state) use ($progressToken): void {
            upload_progress_write($progressToken, $state);
        }
        : null;
    $emit = $progress !== null
        ? static function (string $stage, int $percent, string $message) use ($progress): void {
            unverified_action_emit($progress, $stage, $percent, $message);
        }
        : null;

    $config = catalog_config();
    $db = catalog_db($config);
    try {
        $db->exec('SET SESSION innodb_lock_wait_timeout=5');
        $db->exec('SET SESSION lock_wait_timeout=5');
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    } catch (Throwable) {
        // Compatible servers may expose only part of this session tuning.
    }

    unverified_action_emit($progress, 'starting', 0, 'Resolving queued file');
    $source = (new CatalogUnverifiedActionSourceResolver($db, $config))->resolve($token);
    $service = new CatalogUnverifiedActionService(
        new CatalogUnverifiedQueueMutationService($db, $config),
        new CatalogUnverifiedImporterAdapter($db, $config)
    );

    $result = $service->execute(
        $action,
        $source,
        $targetGameId,
        $userId,
        $allowOverride,
        $emit
    );
    $result['elapsed_ms'] = unverified_action_elapsed_ms();
    unverified_action_reply($result);
} catch (CatalogProfileMismatchException $error) {
    $message = 'File remains in Unverified because it does not match the selected game profile. '
        . 'Enable profile override to import it into that game.';
    if ($progressToken !== '') {
        upload_progress_write($progressToken, [
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'status' => 'unverified_profile_mismatch',
            'message' => $message,
            'elapsed_ms' => unverified_action_elapsed_ms(),
        ]);
    }
    unverified_action_reply([
        'ok' => true,
        'status' => 'unverified_profile_mismatch',
        'message' => $message,
        'request_id' => catalog_request_id(),
        'elapsed_ms' => unverified_action_elapsed_ms(),
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
            'elapsed_ms' => unverified_action_elapsed_ms(),
        ]);
    }
    error_log(
        '[UnrealDB][' . $requestId . '] unverified action failed after '
        . unverified_action_elapsed_ms() . ' ms: '
        . get_class($error) . ': ' . $message
    );
    unverified_action_reply([
        'ok' => false,
        'error' => $message,
        'request_id' => $requestId,
        'elapsed_ms' => unverified_action_elapsed_ms(),
    ], 400);
}
