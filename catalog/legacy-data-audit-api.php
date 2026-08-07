<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the internal/administrator API operation for legacy data audit.
 * Why: It keeps machine-readable action handling separate from the related HTML administration page.
 * Role: Internal/admin HTTP endpoint supporting a catalog maintenance interface.
 * Audit: Endpoint wrapper should stay thin; shared work belongs in reusable services.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogLegacyDataAudit.php';
require_once __DIR__ . '/lib/UploadProgress.php';

function legacy_data_audit_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function legacy_data_audit_post_int(string $name): int
{
    $value = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < 1) {
        throw new RuntimeException('A valid ' . str_replace('_', ' ', $name) . ' is required.');
    }
    return (int)$value;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        legacy_data_audit_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['progress'] ?? '') !== '') {
        $token = upload_progress_token((string)$_GET['progress']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        legacy_data_audit_reply(upload_progress_read($token));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        legacy_data_audit_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('legacy-data-audit');
    $operation = trim((string)($_POST['operation'] ?? ''));
    $token = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $progress = $token !== '' ? static function (array $state) use ($token): void {
        upload_progress_write($token, $state);
    } : null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $config = catalog_config();
    $db = catalog_db($config);

    if ($operation === 'list_files') {
        $gameId = legacy_data_audit_post_int('game_id');
        $game = catalog_one(
            $db,
            'SELECT g.id,g.name,p.engine_key FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
            [$gameId]
        );
        if (!$game || !in_array(strtoupper((string)$game['engine_key']), ['UE1', 'UE2', 'UE3'], true)) {
            throw new RuntimeException('Select a UE1, UE2 or UE3 game.');
        }
        $files = catalog_all(
            $db,
            'SELECT id,original_name,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,original_name,id',
            [$gameId]
        );
        legacy_data_audit_reply(['ok' => true, 'game' => $game, 'files' => $files]);
    }

    if ($operation === 'audit_file') {
        $fileId = legacy_data_audit_post_int('file_id');
        $result = catalog_legacy_audit_file($db, $config, $fileId, $progress);
        legacy_data_audit_reply(['ok' => true, 'result' => $result]);
    }

    throw new RuntimeException('Unknown legacy audit operation.');
} catch (Throwable $error) {
    error_log('[UnrealDB legacy data audit] ' . $error->getMessage());
    legacy_data_audit_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
