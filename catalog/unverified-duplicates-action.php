<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

function unverified_duplicates_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        unverified_duplicates_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $queue = new PdoJobQueue($db);
    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $jobId = max(0, (int)($_GET['job_id'] ?? 0));
        if ($jobId < 1) {
            unverified_duplicates_reply(['ok' => false, 'error' => 'A positive job_id is required.'], 400);
        }
        $job = catalog_one(
            $db,
            'SELECT id,job_type,status,progress_json,result_json,last_error,cancel_requested_at,created_at,updated_at,completed_at '
            . 'FROM ue_background_jobs WHERE id=? AND job_type=?',
            [$jobId, JobType::CLEAN_UNVERIFIED_DUPLICATES]
        );
        if (!$job) {
            unverified_duplicates_reply(['ok' => false, 'error' => 'The duplicate cleanup job was not found.'], 404);
        }
        foreach (['progress_json' => 'progress', 'result_json' => 'result'] as $source => $target) {
            $decoded = !empty($job[$source]) ? json_decode((string)$job[$source], true) : null;
            $job[$target] = is_array($decoded) ? $decoded : null;
            unset($job[$source]);
        }
        unverified_duplicates_reply(['ok' => true, 'job' => $job]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        unverified_duplicates_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('unverified-files');
    $action = strtolower(trim((string)($_POST['action'] ?? 'enqueue')));
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($action === 'cancel') {
        $jobId = max(0, (int)($_POST['job_id'] ?? 0));
        if ($jobId < 1) {
            unverified_duplicates_reply(['ok' => false, 'error' => 'A positive job_id is required.'], 400);
        }
        $owned = catalog_one(
            $db,
            'SELECT id FROM ue_background_jobs WHERE id=? AND job_type=?',
            [$jobId, JobType::CLEAN_UNVERIFIED_DUPLICATES]
        );
        if (!$owned) {
            unverified_duplicates_reply(['ok' => false, 'error' => 'The duplicate cleanup job was not found.'], 404);
        }
        $status = $queue->requestCancellation($jobId, $userId, 'Cancelled from Unverified Files.');
        unverified_duplicates_reply(['ok' => true, 'job_id' => $jobId, 'status' => $status]);
    }

    if ($action !== 'enqueue') {
        unverified_duplicates_reply(['ok' => false, 'error' => 'Unsupported duplicate cleanup action.'], 400);
    }

    $jobId = $queue->enqueue(
        $queueName,
        JobType::CLEAN_UNVERIFIED_DUPLICATES,
        [],
        15,
        null,
        'unverified-duplicate-cleanup',
        $userId,
        2
    );
    unverified_duplicates_reply([
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'queued',
        'type' => JobType::CLEAN_UNVERIFIED_DUPLICATES,
    ], 202);
} catch (Throwable $error) {
    error_log('[UnrealDB unverified duplicates] ' . $error->getMessage());
    unverified_duplicates_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
