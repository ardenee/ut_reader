<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the internal/administrator API operation for source identity repair.
 * Why: It keeps machine-readable action handling separate from the related HTML administration page.
 * Role: Internal/admin HTTP endpoint supporting a catalog maintenance interface.
 * Audit: Endpoint wrapper should stay thin; shared work belongs in reusable services.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

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

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        source_identity_api_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['progress'] ?? '') !== '') {
        source_identity_api_reply([
            'ok' => false,
            'error' => 'Legacy progress tokens are no longer supported. Poll api/v1/job-status.php with the returned job_id.',
        ], 410);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        source_identity_api_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('source-identity-repair');
    $operation = trim((string)($_POST['operation'] ?? ''));
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

    if (in_array($operation, ['repair_file_step', 'repair_single_file'], true)) {
        $fileId = source_identity_api_post_int('file_id');
        $file = catalog_one(
            $db,
            'SELECT f.id,UPPER(COALESCE(NULLIF(f.detected_engine_key,""),p.engine_key,"")) engine_key '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id '
            . 'WHERE f.id=? AND f.scan_status="verified"',
            [$fileId]
        );
        if (!$file) {
            throw new RuntimeException('A verified file was not found.');
        }
        if (!in_array((string)$file['engine_key'], ['UE4', 'UE5'], true)) {
            throw new RuntimeException('Mounted source identity repair is only available for UE4/UE5 files.');
        }

        $queue = new PdoJobQueue($db);
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REPAIR_SOURCE_IDENTITY_FILE,
            ['file_id' => $fileId],
            10,
            null,
            'source-identity-file:' . $fileId,
            $userId,
            3
        );
        source_identity_api_reply([
            'ok' => true,
            'queued' => true,
            'job_id' => $jobId,
            'status' => 'queued',
            'type' => JobType::REPAIR_SOURCE_IDENTITY_FILE,
            'message' => 'Source identity repair was queued. Poll the durable job status endpoint.',
        ], 202);
    }

    if ($operation === 'refresh_dependencies_step') {
        $fileId = source_identity_api_post_int('file_id');
        if (!catalog_one($db, 'SELECT id FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId])) {
            throw new RuntimeException('A verified file was not found.');
        }

        $queue = new PdoJobQueue($db);
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REBUILD_FILE_DEPENDENCIES,
            ['file_id' => $fileId],
            40,
            null,
            'rebuild-file:' . $fileId,
            $userId,
            3
        );
        source_identity_api_reply([
            'ok' => true,
            'queued' => true,
            'job_id' => $jobId,
            'status' => 'queued',
            'type' => JobType::REBUILD_FILE_DEPENDENCIES,
            'message' => 'Dependency refresh was queued. Poll the durable job status endpoint.',
        ], 202);
    }

    throw new RuntimeException('Unknown source identity repair operation.');
} catch (Throwable $error) {
    error_log('[UnrealDB source identity compatibility API] ' . $error->getMessage());
    source_identity_api_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
