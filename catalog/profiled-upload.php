<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPakArchive.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;

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
    $gameId = (int)($_POST['game_id'] ?? 0);
    $strict = (string)($_POST['strict_profile'] ?? '1') === '1';
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
    $queueName = (string)($config['queue']['name'] ?? 'catalog');
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $jobs = [];
    $messages = [];

    foreach ($temporaryFiles as $index => $temporaryPath) {
        $originalName = (string)($_FILES['files']['name'][$index] ?? 'upload.bin');
        $displayName = profiled_upload_relative_path((int)$index, $originalName);
        $errorCode = (int)($_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            $messages[] = ['status' => 'failed', 'file' => $displayName, 'message' => 'PHP upload error ' . $errorCode . '. Large PAK files should use the resumable chunked uploader.'];
            continue;
        }
        if (!is_string($temporaryPath) || !is_file($temporaryPath)) {
            $messages[] = ['status' => 'failed', 'file' => $displayName, 'message' => 'Uploaded temporary file is missing.'];
            continue;
        }
        $size = filesize($temporaryPath);
        $isPak = catalog_pak_archive_is_supported_filename($originalName);
        $limit = $isPak ? $queue->containerLimitBytes() : (int)$config['max_upload_bytes'];
        if ($size === false || $size <= 0 || $size > $limit) {
            $messages[] = [
                'status' => 'failed',
                'file' => $displayName,
                'message' => 'Bad file size: ' . catalog_bytes((int)($size ?: 0)) . '; configured limit: ' . catalog_bytes($limit) . '.',
            ];
            @unlink($temporaryPath);
            continue;
        }

        try {
            $staged = $store->stageUploadedFile($temporaryPath, $originalName);
            $queued = $queue->enqueueStaged($gameId, $staged, $originalName, $displayName, $strict, $userId);
        } catch (Throwable $error) {
            if (isset($staged['relative_path'])) {
                $store->delete((string)$staged['relative_path']);
            }
            $messages[] = ['status' => 'failed', 'file' => $displayName, 'message' => profiled_upload_error($error)];
            continue;
        }

        $jobs[] = $queued;
        $messages[] = [
            'status' => 'queued',
            'file' => $displayName,
            'message' => 'Upload staged for background import as job #' . $queued['job_id'] . '.',
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
    try {
        $worker = (new CatalogDetachedWorker($config))->start($queueName, 10000);
    } catch (Throwable $error) {
        $workerError = profiled_upload_error($error);
        error_log('[UnrealDB profiled upload worker launch] ' . $error->getMessage());
    }

    return [
        'jobs' => $jobs,
        'messages' => $messages,
        'worker' => $worker,
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
        $result = profiled_upload_enqueue($db, $config);
        if ((string)($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['profiled_upload_flash'] = $result['worker_error'] !== ''
            ? 'Queued ' . count($result['jobs']) . ' import job(s), but the detached worker could not be started: ' . $result['worker_error']
            : 'Queued ' . count($result['jobs']) . ' import job(s) and started the detached worker.';
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

    catalog_head('Upload Files');
    catalog_flash($_SESSION['profiled_upload_flash'] ?? null);
    unset($_SESSION['profiled_upload_flash']);
    catalog_page_header(
        'Upload Files',
        'Uploads are copied into durable controlled staging, queued, and automatically started by a detached CLI worker. Large PAK files use resumable chunks and continue from chunks already received when the file is selected again.',
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
    echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: retain mismatches as unverified</option><option value="0">Loose: allow detected reader override</option></select></label></p>';
    echo '<p><label>Choose files<br><input id="profiled-upload-files" type="file" name="files[]" multiple></label></p>';
    echo '<p><label>Choose folder / subfolders<br><input id="profiled-upload-folder" type="file" multiple webkitdirectory directory mozdirectory></label></p>';
    echo '<p><button id="profiled-upload-button" type="submit">Upload and import</button> <button id="profiled-upload-cancel" type="button" hidden>Cancel current upload/job</button></p>';
    echo '<p class="muted">Normal package files use one durable upload request. UE4/UE5 .pak files use resumable ' . catalog_h(catalog_bytes($chunkStore->chunkBytes())) . ' chunks, so Apache and PHP never receive the whole container in one request. Normal-file limit: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '; PAK container limit: ' . catalog_h(catalog_bytes($containerLimit)) . '.</p>';
    echo '<div id="profiled-upload-progress" class="upload-progress" hidden '
        . 'data-queue="' . catalog_h((string)($config['queue']['name'] ?? 'catalog')) . '" '
        . 'data-status-url="api/v1/job-status.php" '
        . 'data-action-url="api/v1/job-action.php" '
        . 'data-run-url="api/v1/job-run.php" '
        . 'data-chunk-url="api/v1/profiled-upload-chunk.php" '
        . 'data-action-csrf="' . catalog_h(catalog_csrf('job_action')) . '" '
        . 'data-chunk-csrf="' . catalog_h(catalog_csrf('profiled_upload_chunk')) . '" '
        . 'data-chunk-bytes="' . $chunkStore->chunkBytes() . '" '
        . 'data-container-limit="' . $containerLimit . '">';
    echo '<div class="progress-row"><span id="overall-progress-label">Overall batch</span><span id="overall-progress-count"></span></div><progress id="overall-progress-bar" value="0" max="100"></progress>';
    echo '<div class="progress-row"><span id="upload-progress-label">Waiting...</span><span id="upload-progress-speed"></span></div><progress id="upload-progress-bar" value="0" max="100"></progress>';
    echo '<div id="upload-progress-log" class="upload-progress-log"></div></div>';
    echo '</form></div>';

    echo '<div class="card"><h2>Game profiles</h2><table><tr><th>Game</th><th>Profile engine</th><th>Extensions</th><th>Version range</th><th>Open</th></tr>';
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
    echo '</table></div>';
    echo '<script src="assets/profiled-upload-jobs.js"></script>';
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
