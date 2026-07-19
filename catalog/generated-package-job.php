<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/ModPackageBuilder.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Security\FileRequestRateLimiter;

function generated_package_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function generated_package_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** @return array<string,mixed>|null */
function generated_package_authorized_job(PDO $db, int $jobId): ?array
{
    if ($jobId < 1) {
        return null;
    }
    $token = (string)($_SESSION['generated_package_jobs'][(string)$jobId] ?? '');
    if ($token === '') {
        return null;
    }
    $job = catalog_one(
        $db,
        'SELECT id,job_type,payload_json,status,progress_json,result_json,last_error,cancel_requested_at,created_at,updated_at,completed_at '
        . 'FROM ue_background_jobs WHERE id=? AND job_type=?',
        [$jobId, JobType::GENERATE_MOD_PACKAGE]
    );
    if (!$job) {
        return null;
    }
    $payload = json_decode((string)$job['payload_json'], true);
    $expected = is_array($payload) ? (string)($payload['access_token_hash'] ?? '') : '';
    if ($expected === '' || !hash_equals($expected, hash('sha256', $token))) {
        return null;
    }
    return $job;
}

try {
    catalog_start_session();
    $config = catalog_config();
    $db = catalog_db($config);
    $queue = new PdoJobQueue($db);
    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $jobId = max(0, (int)($_GET['job_id'] ?? 0));
        $job = generated_package_authorized_job($db, $jobId);
        if (!$job) {
            generated_package_reply(['ok' => false, 'error' => 'The package generation job is unavailable in this browser session.'], 404);
        }
        foreach (['progress_json' => 'progress', 'result_json' => 'result'] as $source => $target) {
            $decoded = !empty($job[$source]) ? json_decode((string)$job[$source], true) : null;
            $job[$target] = is_array($decoded) ? $decoded : null;
            unset($job[$source]);
        }
        unset($job['payload_json']);
        if (is_array($job['result'] ?? null) && !empty($job['result']['expires_at'])) {
            $expires = strtotime((string)$job['result']['expires_at']);
            $job['result']['expired'] = $expires !== false && $expires <= time();
        }
        generated_package_reply(['ok' => true, 'job' => $job]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        generated_package_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('package-generation');
    $action = strtolower(trim((string)($_POST['action'] ?? 'enqueue')));

    if ($action === 'cancel') {
        $jobId = max(0, (int)($_POST['job_id'] ?? 0));
        if (!generated_package_authorized_job($db, $jobId)) {
            generated_package_reply(['ok' => false, 'error' => 'The package generation job is unavailable in this browser session.'], 404);
        }
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $status = $queue->requestCancellation($jobId, $userId, 'Cancelled from generated package download.');
        generated_package_reply(['ok' => true, 'job_id' => $jobId, 'status' => $status]);
    }

    if ($action !== 'enqueue') {
        generated_package_reply(['ok' => false, 'error' => 'Unsupported package generation action.'], 400);
    }

    $rateDirectory = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . '.security' . DIRECTORY_SEPARATOR . 'request-rate-limits';
    $maxRequests = max(1, min((int)(getenv('UNREALDB_PACKAGE_GENERATION_MAX_REQUESTS') ?: 3), 50));
    $windowSeconds = max(60, min((int)(getenv('UNREALDB_PACKAGE_GENERATION_WINDOW_SECONDS') ?: 600), 86400));
    $clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
    $retryAfter = (new FileRequestRateLimiter($rateDirectory, $maxRequests, $windowSeconds))
        ->consume('package-generation', $clientIp);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        generated_package_reply(['ok' => false, 'error' => 'Too many package generation requests. Try again later.'], 429);
    }

    $fileId = max(0, (int)($_POST['file_id'] ?? 0));
    $file = $fileId > 0
        ? catalog_one($db, 'SELECT id,game_id FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId])
        : null;
    if (!$file) {
        generated_package_reply(['ok' => false, 'error' => 'A valid verified file is required.'], 400);
    }

    $settings = modpkg_settings($db);
    $game = modpkg_game_row($db, (int)$file['game_id']);
    if (!$game || !$settings['enabled']) {
        generated_package_reply(['ok' => false, 'error' => 'Generated packages are unavailable for this file.'], 409);
    }
    $mode = external_public_download_mode($db);
    if ($mode === 'disabled' || $mode === 'external_mirror') {
        generated_package_reply(['ok' => false, 'error' => 'Generated packages are unavailable in the current public download mode.'], 409);
    }

    $format = strtolower(trim((string)($_POST['format'] ?? modpkg_default_format($game, $settings))));
    if (!in_array($format, modpkg_available_formats($game, $settings), true)) {
        generated_package_reply(['ok' => false, 'error' => 'The selected package format is not available for this game.'], 400);
    }

    $name = substr(trim((string)($_POST['name'] ?? '')), 0, 160);
    $version = substr(trim((string)($_POST['version'] ?? '1.0')), 0, 80);
    $author = substr(trim((string)($_POST['author'] ?? $settings['default_author'])), 0, 160);
    $includeDependencies = (string)($_POST['dependencies'] ?? '1') !== '0';
    $allowIncomplete = (string)($_POST['allow_incomplete'] ?? '0') === '1';
    $token = generated_package_token();
    $payload = [
        'file_id' => $fileId,
        'format' => $format,
        'include_dependencies' => $includeDependencies,
        'allow_incomplete' => $allowIncomplete,
        'options' => ['name' => $name, 'version' => $version, 'author' => $author],
        'access_token_hash' => hash('sha256', $token),
    ];
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $jobId = $queue->enqueue($queueName, JobType::GENERATE_MOD_PACKAGE, $payload, 30, null, null, $userId, 2);

    if (!isset($_SESSION['generated_package_jobs']) || !is_array($_SESSION['generated_package_jobs'])) {
        $_SESSION['generated_package_jobs'] = [];
    }
    $_SESSION['generated_package_jobs'][(string)$jobId] = $token;
    if (count($_SESSION['generated_package_jobs']) > 20) {
        $_SESSION['generated_package_jobs'] = array_slice($_SESSION['generated_package_jobs'], -20, null, true);
    }

    generated_package_reply([
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'queued',
        'type' => JobType::GENERATE_MOD_PACKAGE,
    ], 202);
} catch (Throwable $error) {
    error_log('[UnrealDB package jobs] ' . $error->getMessage());
    generated_package_reply(['ok' => false, 'error' => 'Package generation is temporarily unavailable.'], 503);
}
