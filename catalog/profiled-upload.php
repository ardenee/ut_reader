<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes profiled file uploads.
 * Why: HTTP/file-selection concerns stay in the page while staging, queueing and worker lifecycle are delegated.
 * Role: Web UI entry point for uploads targeted at a game profile.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPakArchive.php';

use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;

function profiled_upload_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    return trim($message) !== '' ? trim($message) : 'Unknown upload error.';
}

function profiled_upload_relative_path(int $index, string $fallback): string
{
    $paths = $_POST['relative_paths'] ?? [];
    $value = is_array($paths) && isset($paths[$index]) ? (string)$paths[$index] : $fallback;
    $value = trim(str_replace(["\0", '\\'], ['', '/'], $value), '/');
    $parts = [];
    foreach (explode('/', $value) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts !== []) {
                array_pop($parts);
            }
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

/** @return array{jobs:list<array<string,mixed>>,messages:list<array<string,mixed>>,worker:array<string,mixed>|null,worker_error:string} */
function profiled_upload_enqueue(PDO $db, array $config): array
{
    catalog_check_csrf('profiled_upload');
    (new CatalogPublicAccessGuard($config))->transferAllowedOrThrow($db, 'Upload');
    $gameId = (int)($_POST['game_id'] ?? 0);
    $strict = (string)($_POST['strict_profile'] ?? '1') === '1';
    $deferWorkerStart = (string)($_POST['defer_worker_start'] ?? '0') === '1';
    $game = $gameId > 0 ? catalog_one($db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]) : null;
    if (!$game) {
        throw new RuntimeException('Choose a valid target game.');
    }

    $temporaryFiles = $_FILES['files']['tmp_name'] ?? [];
    if (!is_array($temporaryFiles) || $temporaryFiles === []) {
        throw new RuntimeException('Choose one or more files or a folder to upload.');
    }

    $store = new CatalogIncomingFileStore($config);
    $queue = new CatalogProfiledUploadQueue($db, $config);
    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $jobs = [];
    $messages = [];

    foreach ($temporaryFiles as $index => $temporaryPath) {
        $originalName = (string)($_FILES['files']['name'][$index] ?? 'upload.bin');
        $displayName = profiled_upload_relative_path((int)$index, $originalName);
        $errorCode = (int)($_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            $messages[] = [
                'status' => 'failed',
                'file' => $displayName,
                'message' => 'PHP upload error ' . $errorCode
                    . '. Large files can use the chunked uploader, but recovery starts only after the complete file has been durably staged on the server.',
            ];
            continue;
        }
        if (!is_string($temporaryPath) || !is_file($temporaryPath)) {
            $messages[] = ['status' => 'failed', 'file' => $displayName, 'message' => 'Uploaded temporary file is missing.'];
            continue;
        }
        $size = filesize($temporaryPath);
        $isPak = catalog_pak_archive_is_supported_filename($originalName);
        $isArchive = CatalogArchiveExtractor::isArchiveName($originalName);
        $limit = ($isPak || $isArchive) ? $queue->containerLimitBytes() : (int)$config['max_upload_bytes'];
        if ($size === false || $size <= 0 || $size > $limit) {
            $messages[] = [
                'status' => 'failed',
                'file' => $displayName,
                'message' => 'Bad file size: ' . catalog_bytes((int)($size ?: 0)) . '; configured limit: ' . catalog_bytes($limit) . '.',
            ];
            @unlink($temporaryPath);
            continue;
        }

        $staged = null;
        try {
            // Browser priority is durable ingress. The background recovery
            // boundary begins only after this complete file has been moved into
            // controlled server staging; an incomplete browser transfer is not a
            // resumable catalog job.
            $staged = $store->stageUploadedFile($temporaryPath, $originalName, false);
            $queued = $queue->enqueueStaged(
                $gameId,
                $staged,
                $originalName,
                $displayName,
                $strict,
                $userId,
                $deferWorkerStart
            );
        } catch (Throwable $error) {
            if (is_array($staged) && isset($staged['relative_path'])) {
                $store->delete((string)$staged['relative_path']);
            }
            $messages[] = ['status' => 'failed', 'file' => $displayName, 'message' => profiled_upload_error($error)];
            continue;
        }

        $jobs[] = $queued;
        $messages[] = [
            'status' => 'queued',
            'file' => $displayName,
            'message' => !empty($queued['deduplicated'])
                ? 'The same staged file is already represented by job #' . $queued['job_id'] . '.'
                : ($deferWorkerStart
                    ? 'Complete upload durably staged as held background job #' . $queued['job_id'] . '; it will be released after this browser batch finishes staging.'
                    : 'Complete upload durably staged for resumable background import as job #' . $queued['job_id'] . '.'),
            'file_size_text' => catalog_bytes($queued['size']),
            'job_id' => $queued['job_id'],
        ];
    }

    if ($jobs === []) {
        $first = $messages[0]['message'] ?? 'No upload files could be queued.';
        throw new RuntimeException((string)$first);
    }

    $worker = null;
    $workerError = '';
    if (!$deferWorkerStart) {
        $workerState = (new CatalogQueueWorkerStarter($db, $config))->start($queueName, true, $userId);
        $workerError = (string)($workerState['worker_error'] ?? '');
        if ($workerError !== '') {
            error_log('[UnrealDB profiled upload worker launch] ' . $workerError);
        }
        $worker = is_array($workerState['worker'] ?? null) ? $workerState['worker'] : null;
    }

    return [
        'jobs' => $jobs,
        'messages' => $messages,
        'worker' => $worker,
        'worker_error' => $workerError,
    ];
}

/** @return array{released:int,requested:int,worker:array<string,mixed>|null,worker_error:string} */
function profiled_upload_release_batch(PDO $db, array $config): array
{
    catalog_check_csrf('profiled_upload');
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if ($userId < 1) {
        throw new RuntimeException('Administrator authentication is required to release upload jobs.');
    }
    $raw = (string)($_POST['job_ids'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Upload batch job list is invalid.');
    }
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $decoded),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        throw new RuntimeException('Upload batch contains no queued jobs to release.');
    }

    $released = (new CatalogProfiledUploadQueue($db, $config))->releaseHeldJobs($ids, $userId);
    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $workerState = (new CatalogQueueWorkerStarter($db, $config))->start($queueName, true, $userId);
    $workerError = (string)($workerState['worker_error'] ?? '');
    if ($workerError !== '') {
        error_log('[UnrealDB profiled upload batch release worker launch] ' . $workerError);
    }
    return [
        'released' => $released,
        'requested' => count($ids),
        'worker' => is_array($workerState['worker'] ?? null) ? $workerState['worker'] : null,
        'worker_error' => $workerError,
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();

    if (!catalog_support_is_admin()) {
        if ((string)($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Administrator authentication is required.']);
            exit;
        }
        if (!catalog_require_admin_page('Upload Files')) {
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((string)($_POST['action'] ?? '') === 'release_batch') {
            $result = profiled_upload_release_batch($db, $config);
        } else {
            $result = profiled_upload_enqueue($db, $config);
        }
        if ((string)($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['profiled_upload_flash'] = isset($result['released'])
            ? 'Released ' . (int)$result['released'] . ' staged import job(s) for background processing.'
            : ($result['worker_error'] !== ''
                ? 'Queued ' . count($result['jobs']) . ' import job(s), but the detached worker could not be started: ' . $result['worker_error']
                : 'Queued ' . count($result['jobs']) . ' import job(s) and started the detached worker.');
        header('Location: profiled-upload.php?game_id=' . (int)($_POST['game_id'] ?? 0));
        exit;
    }

    $selectedGameId = (int)($_GET['game_id'] ?? 0);
    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key profile_engine,p.allowed_extensions_json,p.package_version_min,p.package_version_max '
        . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name'
    );
    $chunkStore = new CatalogChunkedUploadStore($config);
    $containerLimit = $chunkStore->maxBytes();
    $hashWorker = __DIR__ . '/assets/profiled-upload-hash-worker.js';
    $hashWorkerVersion = is_file($hashWorker) ? (string)filemtime($hashWorker) : '1';

    catalog_head('Upload Files');
    catalog_flash($_SESSION['profiled_upload_flash'] ?? null);
    unset($_SESSION['profiled_upload_flash']);
    catalog_page_header(
        'Upload Files',
        'Ordinary package files are first SHA-1 hashed locally in a Web Worker and checked against already verified content for the selected game; matching content is skipped before network transfer. Files that need uploading are copied into durable controlled staging as quickly as possible. Redirect wrappers, ZIP/7z/RAR archives, UMOD/UT2MOD/UT4MOD install containers and PAK containers defer package duplicate decisions to background processing because their uploaded bytes are transport/container bytes rather than the final catalogued package payload. No background import jobs are created while the selected browser batch is uploading. After the batch is fully staged, one coordinator job starts detached CLI workers for authoritative hashing, decompression/unpacking, header validation and import.',
        [
            'Background Jobs' => 'background-jobs.php',
            'Game Admin' => 'game-manager.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''),
            'Sources' => 'sources.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''),
            'PAK Import' => 'pak-import.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''),
            'Library' => 'library.php',
        ]
    );

    echo '<div class="card"><h2>Upload and import</h2>';
    echo '<form id="profiled-upload-form" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('profiled_upload')) . '">';
    echo '<p><label>Target game<br><select name="game_id" required>';
    foreach ($games as $game) {
        $selected = (int)$game['id'] === $selectedGameId ? ' selected' : '';
        echo '<option value="' . (int)$game['id'] . '"' . $selected . '>'
            . catalog_h((string)$game['name'] . ' / ' . ((string)($game['profile_engine'] ?: 'no active profile'))) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: retain header-detected mismatches as unverified</option><option value="0">Loose: allow the header-detected reader for another engine family</option></select></label></p>';
    echo '<p><label>Choose files<br><input id="profiled-upload-files" type="file" name="files[]" multiple></label></p>';
    echo '<p><label>Choose folder / subfolders<br><input id="profiled-upload-folder" type="file" multiple webkitdirectory directory mozdirectory></label></p>';
    echo '<p><button id="profiled-upload-button" type="submit">Upload and queue</button> <button id="profiled-upload-cancel" type="button" hidden>Cancel current upload</button></p>';
    echo '<p class="muted">Ordinary files are hashed locally one at a time before upload so already verified duplicates can be skipped without sending their bytes. Redirect wrappers and ZIP/7z/RAR/UMOD/UT2MOD/UT4MOD transport/install containers skip package hashing until their real Unreal payloads are available. This browser preflight is advisory only; uploaded files are always handled authoritatively by the background worker. Package reader selection is based on serialized Unreal header data, never the filename or extension. Large package files and all PAK/archive containers use chunked transfer. <strong>Chunking does not make an interrupted browser upload session recoverable:</strong> incomplete batches must be restarted, and no background import jobs are created before finalization. Normal-file limit: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '; container/archive limit: ' . catalog_h(catalog_bytes($containerLimit)) . '.</p>';
    echo '<div id="profiled-upload-progress" class="upload-progress" hidden '
        . 'data-queue="' . catalog_h((string)($config['queue']['name'] ?? 'catalog')) . '" '
        . 'data-status-url="api/v1/job-status.php" '
        . 'data-worker-status-url="api/v1/job-worker-status.php" '
        . 'data-action-url="api/v1/job-action.php" '
        . 'data-run-url="api/v1/job-run.php" '
        . 'data-chunk-url="api/v1/profiled-upload-chunk.php" '
        . 'data-preflight-url="api/v1/profiled-upload-preflight.php" '
        . 'data-hash-worker-url="assets/profiled-upload-hash-worker.js?v=' . catalog_h($hashWorkerVersion) . '" '
        . 'data-action-csrf="' . catalog_h(catalog_csrf('job_action')) . '" '
        . 'data-chunk-csrf="' . catalog_h(catalog_csrf('profiled_upload_chunk')) . '" '
        . 'data-preflight-csrf="' . catalog_h(catalog_csrf('profiled_upload_preflight')) . '" '
        . 'data-chunk-bytes="' . $chunkStore->chunkBytes() . '" '
        . 'data-normal-limit="' . (int)$config['max_upload_bytes'] . '" '
        . 'data-container-limit="' . $containerLimit . '">';
    echo '<div class="progress-row"><span id="overall-progress-label">Overall preflight/upload</span><span id="overall-progress-count"></span></div><progress id="overall-progress-bar" value="0" max="100"></progress>';
    echo '<div class="progress-row"><span id="upload-progress-label">Waiting...</span><span id="upload-progress-speed"></span></div><progress id="upload-progress-bar" value="0" max="100"></progress>';
    echo '<div id="upload-progress-log" class="upload-progress-log"></div></div>';
    echo '</form></div>';

    echo '<div class="card"><h2>Game profiles</h2><table><tr><th>Game</th><th>Profile engine</th><th>Discovery extensions</th><th>Version range</th><th>Open</th></tr>';
    foreach ($games as $game) {
        $extensions = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
        $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null)
            ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?'))
            : 'not fixed';
        $engine = (string)($game['profile_engine'] ?: 'missing profile');
        echo '<tr><td>' . catalog_h($game['name']) . '</td><td>' . catalog_h($engine) . '</td><td class="mono">'
            . catalog_h(is_array($extensions) ? implode(', ', $extensions) : '') . '</td><td class="mono">' . catalog_h($range)
            . '</td><td><a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">select</a></td></tr>';
    }
    echo '</table><p class="muted small">Discovery extensions are only source/file-picker hints. They are not trusted for engine detection or package acceptance.</p></div>';
    $uploadClient = __DIR__ . '/assets/profiled-upload-jobs.js';
    $uploadClientVersion = is_file($uploadClient) ? (string)filemtime($uploadClient) : '1';
    $diagnosticClient = __DIR__ . '/assets/profiled-upload-diagnostics.js';
    $diagnosticClientVersion = is_file($diagnosticClient) ? (string)filemtime($diagnosticClient) : '1';
    echo '<script src="assets/profiled-upload-jobs.js?v=' . catalog_h($uploadClientVersion) . '"></script>';
    echo '<script src="assets/profiled-upload-diagnostics.js?v=' . catalog_h($diagnosticClientVersion) . '"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB profiled upload][' . catalog_request_id() . '] ' . $error->getMessage());
    if ((string)($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => profiled_upload_error($error)]);
        exit;
    }
    if (!headers_sent()) {
        catalog_head('Upload error');
    }
    echo CatalogUi::alert('danger', profiled_upload_error($error), 'Upload request failed.');
    catalog_foot();
}
