<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPakArchive.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

function pak_import_public_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    return $message !== '' ? $message : 'PAK import request failed.';
}

/** @return array{path:string,name:string,uploaded:bool} */
function pak_import_source(): array
{
    $local = trim((string)($_POST['local_pak_path'] ?? ''));
    if ($local !== '') {
        $real = realpath($local);
        if ($real === false || !is_file($real) || !is_readable($real) || is_link($real)) {
            throw new RuntimeException('Local PAK path is not a readable regular file.');
        }
        return ['path' => $real, 'name' => basename($real), 'uploaded' => false];
    }
    $file = $_FILES['pak_file'] ?? null;
    if (!is_array($file)) {
        throw new RuntimeException('Choose a .pak file or enter a readable local .pak path.');
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $path = (string)($file['tmp_name'] ?? '');
    if ($error !== UPLOAD_ERR_OK || $path === '' || !is_file($path)) {
        throw new RuntimeException('PAK upload failed with PHP upload error ' . $error . '.');
    }
    return ['path' => $path, 'name' => (string)($file['name'] ?? 'upload.pak'), 'uploaded' => true];
}

/** @return array{job_id:int,worker:array<string,mixed>|null,worker_error:string} */
function pak_import_enqueue(PDO $db, array $config): array
{
    catalog_check_csrf('pak-import');
    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        throw new RuntimeException('PAK archive tables are missing. Run php catalog/bin/migrate.php migrate first.');
    }

    $gameId = (int)($_POST['game_id'] ?? 0);
    $strict = (string)($_POST['strict_profile'] ?? '1') === '1';
    $game = $gameId > 0 ? catalog_one(
        $db,
        'SELECT g.id,p.engine_key FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
        [$gameId]
    ) : null;
    if (!$game || preg_match('/^UE[45]/i', trim((string)$game['engine_key'])) !== 1) {
        throw new RuntimeException('Choose a valid UE4 or UE5 target game.');
    }

    $source = pak_import_source();
    if (!catalog_pak_archive_is_supported_filename($source['name'])) {
        throw new RuntimeException('Selected file is not a .pak archive.');
    }
    $size = filesize($source['path']);
    if ($size === false || $size <= 0 || $size > (int)$config['max_upload_bytes']) {
        throw new RuntimeException('Bad PAK size: ' . catalog_bytes((int)($size ?: 0)));
    }

    $store = new CatalogIncomingFileStore($config);
    $staged = $source['uploaded']
        ? $store->stageUploadedFile($source['path'], $source['name'])
        : $store->stageLocalFile($source['path'], $source['name']);
    $queue = new PdoJobQueue($db);
    $queueName = (string)($config['queue']['name'] ?? 'catalog');
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    try {
        $jobId = $queue->enqueue(
            $queueName,
            JobType::IMPORT_STAGED_PAK,
            [
                'game_id' => $gameId,
                'staged_path' => $staged['relative_path'],
                'original_name' => $source['name'],
                'strict_profile' => $strict,
                'user_id' => $userId,
                'size' => $staged['size'],
                'sha256' => $staged['sha256'],
            ],
            5,
            null,
            null,
            $userId,
            3
        );
    } catch (Throwable $error) {
        $store->delete($staged['relative_path']);
        throw $error;
    }

    $worker = null;
    $workerError = '';
    try {
        $worker = (new CatalogDetachedWorker($config))->start($queueName, 10000);
    } catch (Throwable $error) {
        $workerError = pak_import_public_error($error);
        error_log('[UnrealDB PAK worker launch] ' . $error->getMessage());
    }

    return ['job_id' => $jobId, 'worker' => $worker, 'worker_error' => $workerError];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('PAK Import')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $queued = pak_import_enqueue($db, $config);
        if ($queued['worker_error'] !== '') {
            $_SESSION['pak_import_flash'] = 'PAK job #' . $queued['job_id'] . ' was queued, but the detached worker could not be started: ' . $queued['worker_error'];
        }
        header('Location: pak-import.php?game_id=' . (int)($_POST['game_id'] ?? 0) . '&job_id=' . $queued['job_id']);
        exit;
    }

    $selectedGameId = (int)($_GET['game_id'] ?? 0);
    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,p.engine_key profile_engine FROM ue_games g '
        . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
        . 'WHERE UPPER(p.engine_key) LIKE "UE4%" OR UPPER(p.engine_key) LIKE "UE5%" '
        . 'ORDER BY g.name'
    );

    catalog_head('PAK Import');
    catalog_flash($_SESSION['pak_import_flash'] ?? null);
    unset($_SESSION['pak_import_flash']);
    catalog_page_header(
        'PAK Import',
        'The original UE4 or UE5 PAK is retained as a self-contained downloadable archive while its entries are extracted and cataloged separately.',
        ['PAK Archives' => 'paks.php', 'Background Jobs' => 'background-jobs.php', 'Upload Files' => 'profiled-upload.php', 'Unverified Files' => 'unverified-files.php']
    );

    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        echo CatalogUi::alert('warning', 'PAK archive management is not installed. Run php catalog/bin/migrate.php migrate before importing PAK files.');
    } else {
        echo CatalogUi::alert(
            'info',
            'Original PAK retention enabled for UE4 and UE5.',
            'The PAK container is copied into durable game storage. Every readable index entry is recorded and standalone packages link back to that original archive. Encrypted indexes, unsupported compression, and UE5 IoStore .utoc/.ucas containers remain separate and unsupported by this PAK importer.'
        );
    }

    if ($jobId > 0) {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Background import job #' . $jobId . '</h2><p class="muted">The detached worker continues if this page or browser is closed.</p></div></div><div class="ui-section__body">';
        echo '<div id="pak-import-job" data-job-id="' . $jobId . '" data-queue="' . catalog_h((string)($config['queue']['name'] ?? 'catalog')) . '" data-status-url="api/v1/job-status.php" data-action-url="api/v1/job-action.php" data-run-url="api/v1/job-run.php" data-action-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';
        echo '<p id="pak-import-status">Loading job status...</p><progress id="pak-import-progress" value="0" max="100" style="width:100%"></progress>';
        echo '<p><button id="pak-import-cancel" type="button">Cancel job</button></p><div id="pak-import-result"></div></div></div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Retain and import PAK</h2><p>Use an uploaded file or a readable local server path.</p></div></div><div class="ui-section__body">';
    if ($games === []) {
        echo CatalogUi::emptyState('No UE4 or UE5 target games', 'Create a game or assign a UE4/UE5 profile before importing PAK archives.', ['label' => 'Game manager', 'href' => 'game-manager.php'], '▣');
    } else {
        echo '<form method="post" enctype="multipart/form-data" data-ui-loading-form><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('pak-import')) . '">';
        echo '<p><label>Target UE4/UE5 game<br><select name="game_id" required><option value="">Choose target game</option>';
        foreach ($games as $game) {
            echo '<option value="' . (int)$game['id'] . '"' . ((int)$game['id'] === $selectedGameId ? ' selected' : '') . '>'
                . catalog_h((string)$game['name'] . ' / ' . (string)$game['profile_engine']) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: retain mismatches as unverified</option><option value="0">Loose: allow detected reader override</option></select></label></p>';
        echo '<p><label>Upload .pak<br><input type="file" name="pak_file" accept=".pak"></label></p>';
        echo '<p><label>Or local .pak path<br><input type="text" name="local_pak_path" style="width:min(100%,760px)"></label></p>';
        echo '<p><button type="submit">Retain and import PAK</button></p></form>';
    }
    echo '</div></section>';
    echo '<script src="assets/pak-import-jobs.js"></script>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB PAK import][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('PAK Import Error');
    }
    echo CatalogUi::alert('danger', pak_import_public_error($error), 'PAK import request failed.');
    catalog_foot();
}
