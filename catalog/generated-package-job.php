<?php
/**
 * Generated-package job creation, lookup, polling and cancellation.
 *
 * Interactive package builds use a dedicated durable queue so catalogue imports
 * and dependency scans cannot starve them. Equivalent active/completed builds are
 * reused instead of being generated more than once.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPublicAccess.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/DownloadActivity.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedPackageDescriptor;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportSettingsService;
use UnrealDb\Catalog\Infrastructure\Downloads\PdoCatalogPackageExportPlanner;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogGeneratedPackageJobAccess;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogGeneratedPackageQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;

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
function generated_package_authorized_job(CatalogGeneratedPackageJobAccess $access, int $jobId): ?array
{
    if ($jobId < 1) {
        return null;
    }
    $grant = (string)($_SESSION['generated_package_jobs'][(string)$jobId] ?? '');
    if ($grant === '') {
        return null;
    }
    return $access->findAuthorized($jobId, $grant);
}

/** @param array<string,mixed> $job */
function generated_package_grant_job(array $job, string $buildKey, ?string $token = null): void
{
    if (!isset($_SESSION['generated_package_jobs']) || !is_array($_SESSION['generated_package_jobs'])) {
        $_SESSION['generated_package_jobs'] = [];
    }

    $jobId = max(0, (int)($job['id'] ?? 0));
    if ($jobId < 1) {
        return;
    }

    $_SESSION['generated_package_jobs'][(string)$jobId] = $token !== null && $token !== ''
        ? $token
        : 'build:' . strtolower($buildKey);

    if (count($_SESSION['generated_package_jobs']) > 30) {
        $_SESSION['generated_package_jobs'] = array_slice($_SESSION['generated_package_jobs'], -30, null, true);
    }
}

/**
 * @param array<string,mixed> $settings
 * @param array<string,mixed> $game
 * @param array<string,mixed> $file
 * @return array<string,mixed>
 */
function generated_package_request(
    CatalogPackageExportSettingsService $packageSettings,
    array $settings,
    array $game,
    array $file,
    array $input
): array {
    $format = strtolower(trim((string)(
        $input['format'] ?? $packageSettings->defaultFormat($game, $settings)
    )));
    if (!in_array($format, $packageSettings->availableFormats($game, $settings), true)) {
        generated_package_reply(['ok' => false, 'error' => 'The selected package format is not available for this game.'], 400);
    }

    $name = substr(trim((string)($input['name'] ?? '')), 0, 160);
    if ($name === '') {
        $name = catalog_clean_unreal_package_stem((string)$file['package_name']);
    }
    $version = CatalogGeneratedPackageDescriptor::generatedVersion($input['version'] ?? '1.0');
    $author = substr(trim((string)($input['author'] ?? $settings['default_author'])), 0, 160);
    $includeDependencies = (string)($input['dependencies'] ?? '1') !== '0';
    $allowIncompleteRequested = (string)($input['allow_incomplete'] ?? '0') === '1';
    $allowIncomplete = !empty($settings['allow_incomplete']) && $allowIncompleteRequested;

    $identity = [
        'file_id' => (int)$file['id'],
        'format' => $format,
        'include_dependencies' => $includeDependencies,
        'allow_incomplete' => $allowIncomplete,
        'name' => $name,
        'version' => $version,
        'author' => $author,
    ];
    $buildKey = hash(
        'sha256',
        json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );

    return $identity + ['build_key' => $buildKey];
}

/** @param array<string,mixed> $job */
function generated_package_completed_artifact_valid(array $job, array $config): bool
{
    if ((string)($job['status'] ?? '') !== 'completed') {
        return false;
    }
    $result = json_decode((string)($job['result_json'] ?? ''), true);
    if (!is_array($result)) {
        return false;
    }
    $expires = strtotime((string)($result['expires_at'] ?? ''));
    if ($expires === false || $expires <= time()) {
        return false;
    }
    $store = new GeneratedPackageStore((string)($config['storage_path'] ?? ''));
    $path = $store->resolve((string)($result['artifact_name'] ?? ''));
    if ($path === null) {
        return false;
    }
    $size = filesize($path);
    return $size !== false && (int)$size === (int)($result['artifact_size'] ?? -1);
}

/** @return array<string,mixed>|null */
function generated_package_reusable(
    CatalogGeneratedPackageJobAccess $access,
    string $queueName,
    string $buildKey,
    array $config
): ?array {
    foreach ($access->reusableCandidates($queueName, $buildKey) as $job) {
        $status = (string)($job['status'] ?? '');
        if (in_array($status, ['queued', 'running'], true)) {
            return $job;
        }
        if ($status === 'completed' && generated_package_completed_artifact_valid($job, $config)) {
            return $job;
        }
    }
    return null;
}

/** @return array{worker:array<string,mixed>|null,worker_error:string} */
function generated_package_start_worker(
    array $config,
    string $queueName,
    int $jobId
): array {
    if (!isset($_SESSION['generated_package_worker_attempts']) || !is_array($_SESSION['generated_package_worker_attempts'])) {
        $_SESSION['generated_package_worker_attempts'] = [];
    }

    $now = time();
    $lastAttempt = (int)($_SESSION['generated_package_worker_attempts'][(string)$jobId] ?? 0);
    if ($lastAttempt > $now - 15) {
        return ['worker' => null, 'worker_error' => ''];
    }
    $_SESSION['generated_package_worker_attempts'][(string)$jobId] = $now;

    try {
        $launcher = new CatalogDetachedWorker($config);
        $state = $launcher->start(
            $queueName,
            10000,
            CatalogGeneratedPackageQueue::workerCount($config)
        );
        return [
            'worker' => is_array($state['worker'] ?? null) ? $state['worker'] : null,
            'worker_error' => '',
        ];
    } catch (Throwable $error) {
        $message = trim($error->getMessage()) !== '' ? trim($error->getMessage()) : get_class($error);
        error_log('[UnrealDB package worker launch] job #' . $jobId . ': ' . $message);
        return ['worker' => null, 'worker_error' => $message];
    }
}

try {
    catalog_start_session();
    $config = catalog_config();
    $db = catalog_db($config);
    $queue = new PdoJobQueue($db);
    $access = new CatalogGeneratedPackageJobAccess($db);
    $queueName = CatalogGeneratedPackageQueue::name($config);
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $jobId = max(0, (int)($_GET['job_id'] ?? 0));
        $job = generated_package_authorized_job($access, $jobId);
        if (!$job) {
            generated_package_reply(['ok' => false, 'error' => 'The package generation job is unavailable in this browser session.'], 404);
        }

        $workerState = ['worker' => null, 'worker_error' => ''];
        if ((string)$job['status'] === 'queued') {
            $workerState = generated_package_start_worker($config, (string)$job['queue_name'], $jobId);
        }

        foreach (['progress_json' => 'progress', 'result_json' => 'result'] as $source => $target) {
            $decoded = !empty($job[$source]) ? json_decode((string)$job[$source], true) : null;
            $job[$target] = is_array($decoded) ? $decoded : null;
            unset($job[$source]);
        }
        unset($job['payload_json'], $job['payload']);
        if (is_array($job['result'] ?? null) && !empty($job['result']['expires_at'])) {
            $expires = strtotime((string)$job['result']['expires_at']);
            $job['result']['expired'] = $expires !== false && $expires <= time();
        }
        generated_package_reply(['ok' => true, 'job' => $job] + $workerState);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        generated_package_reply(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    catalog_check_csrf('package-generation');
    $action = strtolower(trim((string)($_POST['action'] ?? 'enqueue')));

    if ($action === 'cancel') {
        $jobId = max(0, (int)($_POST['job_id'] ?? 0));
        if (!generated_package_authorized_job($access, $jobId)) {
            generated_package_reply(['ok' => false, 'error' => 'The package generation job is unavailable in this browser session.'], 404);
        }
        $status = $queue->requestCancellation($jobId, $userId, 'Cancelled from generated package download.');
        catalog_download_audit_generation_status($db, $jobId, 'cancelled');
        generated_package_reply(['ok' => true, 'job_id' => $jobId, 'status' => $status]);
    }

    if (!in_array($action, ['enqueue', 'lookup'], true)) {
        generated_package_reply(['ok' => false, 'error' => 'Unsupported package generation action.'], 400);
    }

    $fileId = max(0, (int)($_POST['file_id'] ?? 0));
    $file = $fileId > 0
        ? catalog_one($db, 'SELECT id,game_id,package_name FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId])
        : null;
    if (!$file) {
        generated_package_reply(['ok' => false, 'error' => 'A valid verified file is required.'], 400);
    }

    $packageSettings = new CatalogPackageExportSettingsService($db);
    $settings = $packageSettings->settings();
    $game = $packageSettings->game((int)$file['game_id']);
    if (!$game || !$settings['enabled']) {
        generated_package_reply(['ok' => false, 'error' => 'Generated packages are unavailable for this file.'], 409);
    }
    if (external_public_download_mode($db) === 'disabled') {
        generated_package_reply(['ok' => false, 'error' => 'Generated packages are disabled.'], 409);
    }

    $request = generated_package_request($packageSettings, $settings, $game, $file, $_POST);
    $buildKey = (string)$request['build_key'];
    $reusable = generated_package_reusable($access, $queueName, $buildKey, $config);
    if ($reusable !== null) {
        generated_package_grant_job($reusable, $buildKey);
        $status = (string)$reusable['status'];
        $jobId = (int)$reusable['id'];
        if ($status === 'queued') {
            $workerState = generated_package_start_worker($config, (string)$reusable['queue_name'], $jobId);
        } else {
            $workerState = ['worker' => null, 'worker_error' => ''];
        }
        generated_package_reply([
            'ok' => true,
            'job_id' => $jobId,
            'status' => $status,
            'type' => JobType::GENERATE_MOD_PACKAGE,
            'reused' => true,
            'ready' => $status === 'completed',
            'download_url' => $status === 'completed'
                ? 'generated-package-download.php?job_id=' . $jobId
                : null,
        ] + $workerState, $status === 'completed' ? 200 : 202);
    }

    if ($action === 'lookup') {
        generated_package_reply([
            'ok' => true,
            'status' => 'none',
            'reused' => false,
            'ready' => false,
        ]);
    }

    // Preflight before queueing so known dependency gaps are a user-visible
    // package choice, not a background worker/System Error.
    $plan = (new PdoCatalogPackageExportPlanner($db, $config))->plan(
        (int)$file['id'],
        (string)$request['format'],
        (bool)$request['include_dependencies'],
        $settings
    );
    $missingCount = count((array)$plan['missing']);
    if ($missingCount > 0 && empty($request['allow_incomplete'])) {
        generated_package_reply([
            'ok' => false,
            'error' => 'This package has ' . $missingCount
                . ' genuinely missing dependency object'
                . ($missingCount === 1 ? '' : 's')
                . '. Enable incomplete package generation or resolve the missing dependencies before building.',
            'missing_dependencies' => $missingCount,
            'package_only_dependencies' => count((array)$plan['package_only']),
        ], 409);
    }

    $token = generated_package_token();
    $payload = [
        'file_id' => (int)$file['id'],
        'format' => (string)$request['format'],
        'include_dependencies' => (bool)$request['include_dependencies'],
        'allow_incomplete' => (bool)$request['allow_incomplete'],
        'options' => [
            'name' => (string)$request['name'],
            'version' => (string)$request['version'],
            'author' => (string)$request['author'],
        ],
        'build_key' => $buildKey,
        'access_token_hash' => hash('sha256', $token),
    ];

    // Count only a genuinely new build. Reusing an active/completed package above
    // does not consume another public package-build allowance.
    catalog_public_package_limit($db);
    $dedupeKey = 'generated-package:' . $buildKey;
    $jobId = $queue->enqueue(
        $queueName,
        JobType::GENERATE_MOD_PACKAGE,
        $payload,
        0,
        null,
        $dedupeKey,
        $userId,
        2
    );

    $job = $access->find($jobId);
    if ($job === null) {
        generated_package_reply(['ok' => false, 'error' => 'The queued package job could not be reloaded.'], 503);
    }
    $storedBuildKey = strtolower(trim((string)($job['payload']['build_key'] ?? '')));
    if ($storedBuildKey === '' || !hash_equals($buildKey, $storedBuildKey)) {
        generated_package_reply(['ok' => false, 'error' => 'The package job deduplication identity did not match the request.'], 503);
    }
    generated_package_grant_job(
        $job,
        $buildKey,
        $access->isAuthorized($job, $token) ? $token : null
    );

    catalog_download_audit_generation_queued($db, [
        'job_id' => $jobId,
        'file_id' => (int)$file['id'],
        'game_id' => (int)$file['game_id'],
        'user_id' => $userId,
        'ip_address' => catalog_public_access_client_ip(),
        'user_agent' => catalog_download_audit_user_agent(),
        'package_format' => (string)$request['format'],
        'package_name' => (string)$request['name'],
        'package_version' => (string)$request['version'],
        'include_dependencies' => (bool)$request['include_dependencies'],
        'allow_incomplete' => (bool)$request['allow_incomplete'],
    ]);

    $workerState = generated_package_start_worker($config, $queueName, $jobId);
    generated_package_reply([
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'queued',
        'type' => JobType::GENERATE_MOD_PACKAGE,
        'reused' => false,
        'ready' => false,
    ] + $workerState, 202);
} catch (Throwable $error) {
    error_log('[UnrealDB package jobs] ' . get_class($error) . ': ' . $error->getMessage());
    $status = http_response_code();
    if ($status < 400) {
        $status = 503;
    }
    $message = in_array($status, [409, 429], true)
        ? $error->getMessage()
        : 'Package generation is temporarily unavailable.';
    generated_package_reply(['ok' => false, 'error' => $message], $status);
}
