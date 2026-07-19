<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;


require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/lib/CatalogRedirectArchive.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';

function upload_bucket_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    $message = trim($message);
    return $message !== '' ? $message : 'Unknown error';
}

function upload_bucket_result(string $status, string $file, string $message, array $meta = []): array
{
    return ['status' => $status, 'file' => $file, 'message' => $message] + $meta;
}

function upload_bucket_result_text(array $entry): string
{
    $text = (string)$entry['file'] . ': ' . (string)$entry['status'] . ' - ' . (string)$entry['message'];
    if (!empty($entry['file_size_text'])) {
        $text .= ' | size: ' . (string)$entry['file_size_text'];
    }
    return $text;
}

function upload_bucket_source_relative_path(string $submittedPath, string $storedName): string
{
    $submittedPath = scanner_normalize_source_relative_path($submittedPath);
    if ($submittedPath === '') {
        return scanner_normalize_source_relative_path($storedName);
    }

    $directory = trim(str_replace('\\', '/', dirname($submittedPath)), '. /');
    return scanner_normalize_source_relative_path(($directory !== '' ? $directory . '/' : '') . $storedName);
}

function upload_bucket_handle_request(PDO $db, array $config): array
{
    catalog_check_csrf('upload-bucket');
    if (!isset($_FILES['files'])) {
        throw new RuntimeException('No files were uploaded.');
    }

    $allowedExtensions = array_map(static fn($ext): string => strtolower(ltrim((string)$ext, '.')), $config['allowed_extensions'] ?? []);
    $allowedExtensions = array_values(array_filter(array_unique($allowedExtensions), static fn(string $ext): bool => $ext !== ''));
    $maxBytes = (int)($config['max_upload_bytes'] ?? PHP_INT_MAX);
    $stager = new LegacyUnverifiedFileStager($db, $config);
    $uploadedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    $ok = 0;
    $failed = 0;
    $messages = [];
    $temporaryPaths = $_FILES['files']['tmp_name'] ?? [];
    if (!is_array($temporaryPaths)) {
        $temporaryPaths = [];
    }

    foreach ($temporaryPaths as $index => $tmp) {
        $submittedName = (string)($_FILES['files']['name'][$index] ?? 'upload.bin');
        $submittedRelativePath = (string)($_POST['relative_path'] ?? $submittedName);
        $cleanName = catalog_clean_unreal_filename($submittedName);
        $err = (int)($_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        $size = is_string($tmp) && is_file($tmp) ? (int)(filesize($tmp) ?: 0) : 0;
        $meta = ['file_size' => $size, 'file_size_text' => catalog_bytes($size)];

        if ($err !== UPLOAD_ERR_OK) {
            $failed++;
            $messages[] = upload_bucket_result('failed', $cleanName, 'Upload error ' . $err, $meta);
            continue;
        }

        $workingTmp = (string)$tmp;
        $workingName = $cleanName;
        $decompressed = null;

        try {
            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('Bad file size: ' . catalog_bytes($size));
            }

            if (catalog_redirect_archive_is_supported_filename($submittedName)) {
                $decompressed = catalog_redirect_archive_decompress_to_temp($workingTmp, $submittedName);
                $workingTmp = $decompressed['path'];
                $workingName = catalog_clean_unreal_filename($decompressed['filename']);
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            $storedSize = is_file($workingTmp) ? (int)(filesize($workingTmp) ?: 0) : 0;
            if ($storedSize <= 0 || $storedSize > $maxBytes) {
                throw new RuntimeException('Bad decompressed file size: ' . catalog_bytes($storedSize));
            }

            $ext = strtolower(pathinfo($workingName, PATHINFO_EXTENSION));
            if ($allowedExtensions !== [] && !in_array($ext, $allowedExtensions, true)) {
                throw new RuntimeException('Extension not allowed for bucket upload: ' . ($ext !== '' ? $ext : '(none)'));
            }

            $cleanNote = $submittedName !== $workingName ? ' Original browser filename was: ' . basename($submittedName) . '.' : '';
            $redirectNote = is_array($decompressed)
                ? ' Redirect archive .' . $decompressed['source_extension'] . ' was decompressed before storage; compressed wrapper was not retained.'
                : '';
            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s') . '. No game assignment has been made yet.' . $redirectNote . $cleanNote;
            $sourceRelativePath = upload_bucket_source_relative_path($submittedRelativePath, $workingName);
            $staged = $stager->stageBucketUpload(
                $workingTmp,
                $workingName,
                $note,
                $uploadedBy,
                $sourceRelativePath
            );

            $ok++;
            $message = is_array($decompressed)
                ? 'Decompressed redirect archive into upload bucket and indexed as unverified'
                : 'Stored in upload bucket and indexed as unverified';
            if ($staged['parse_error'] !== null) {
                $message .= '; package tables could not be read';
            }
            $messages[] = upload_bucket_result(is_array($decompressed) ? 'decompressed' : 'bucketed', $workingName, $message, [
                'file_size' => (int)$staged['size'],
                'file_size_text' => catalog_bytes((int)$staged['size']),
                'queue_name' => (string)$staged['queue_name'],
                'file_id' => (int)$staged['file_id'],
            ]);
        } catch (Throwable $error) {
            $failed++;
            $messages[] = upload_bucket_result('failed', $workingName, upload_bucket_short_error($error), $meta);
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
            if ($workingTmp !== (string)$tmp && is_file($workingTmp)) {
                @unlink($workingTmp);
            }
        }
    }

    return ['ok' => $ok, 'failed' => $failed, 'messages' => $messages];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Upload Bucket')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = upload_bucket_handle_request($db, $config);
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['upload_bucket_flash'] = 'Bucket upload complete. Stored=' . $result['ok'] . ' Failed=' . $result['failed'] . '. ' . implode(' | ', array_map('upload_bucket_result_text', array_slice($result['messages'], 0, 12)));
        header('Location: upload-bucket.php');
        exit;
    }

    $bucketDir = uvf_upload_bucket_dir($config, true);
    $bucketItems = uvf_list($db, $config, 0);

    catalog_head('Upload Bucket');
    catalog_flash($_SESSION['upload_bucket_flash'] ?? null);
    unset($_SESSION['upload_bucket_flash']);

    echo <<<'CSS'
<style>
.bucket-progress { margin-top:12px; border:1px solid var(--line); border-radius:14px; padding:12px; background:rgba(255,255,255,.03); }
.bucket-progress progress { width:100%; height:18px; }
.bucket-log { max-height:260px; overflow:auto; margin-top:10px; font-family:Consolas, ui-monospace, monospace; font-size:12px; color:var(--muted); }
.bucket-result { display:flex; gap:8px; align-items:baseline; padding:3px 0; }
.bucket-result-badge { min-width:98px; font-weight:700; text-transform:uppercase; }
.bucket-result-file { color:var(--text); }
.bucket-result-message { color:var(--muted); }
.bucket-result-bucketed .bucket-result-badge, .bucket-result-decompressed .bucket-result-badge { color:#a7f3d0; }
.bucket-result-failed .bucket-result-badge { color:#fecdd3; }
.bucket-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Upload Bucket',
        'Upload unsorted Unreal package files into a neutral bucket. You can select individual files or a whole folder/subfolders. Redirect-compressed .uz/.uz2/.uz3 uploads are decompressed first and only the real package is retained.',
        ['Open Bucket Queue' => 'unverified-files.php?source_game_id=-1', 'All Queues' => 'unverified-files.php', 'Upload Files to Game' => 'profiled-upload.php']
    );

    echo '<div class="grid">';
    echo '<div class="stat"><h2>' . count($bucketItems) . '</h2><p>Files in upload bucket</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes(array_sum(array_map(static fn(array $item): int => (int)$item['size'], $bucketItems)))) . '</h2><p>Bucket storage</p></div>';
    echo '<div class="stat"><h2 class="mono small">' . catalog_h($bucketDir) . '</h2><p>Physical bucket folder</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Upload unsorted files</h2><p>Files are uploaded one at a time to avoid browser/PHP file-count limits. Each retained package receives an unverified database row immediately, including readable names/imports/exports, but no game assignment or dependencies.</p></div></div><div class="ui-section__body">';
    echo '<form id="upload-bucket-form" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload-bucket')) . '">';
    echo '<p><label>Choose files<br><input id="upload-bucket-files" type="file" name="files[]" multiple></label></p>';
    echo '<p><label>Choose folder / subfolders<br><input id="upload-bucket-folder" type="file" multiple webkitdirectory directory mozdirectory></label></p>';
    echo '<p><button id="upload-bucket-button" type="submit">Upload to bucket</button></p>';
    echo '<p class="muted">Max per uploaded/decompressed file: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '. Browser folder upload records the client-relative path as package identity context while physical bucket storage still uses only a cleaned filename. Allowed inputs: catalog package extensions plus .uz/.uz2/.uz3 redirect archives.</p>';
    echo '<div id="bucket-progress" class="bucket-progress" hidden>';
    echo '<div class="progress-row"><span id="bucket-progress-label">Waiting...</span><span id="bucket-progress-count"></span></div><progress id="bucket-progress-bar" value="0" max="100"></progress>';
    echo '<div id="bucket-log" class="bucket-log"></div></div>';
    echo '</form>';
    echo '<p class="bucket-actions"><a class="button" href="unverified-files.php?source_game_id=-1">Review bucket / assign files</a><a class="button secondary" href="unverified-files.php">Review all queues</a></p>';
    echo '</div></section>';

    echo <<<'HTML'
<script>
(function () {
    const form = document.getElementById('upload-bucket-form');
    const fileInput = document.getElementById('upload-bucket-files');
    const folderInput = document.getElementById('upload-bucket-folder');
    const button = document.getElementById('upload-bucket-button');
    const progressBox = document.getElementById('bucket-progress');
    const progressBar = document.getElementById('bucket-progress-bar');
    const progressLabel = document.getElementById('bucket-progress-label');
    const progressCount = document.getElementById('bucket-progress-count');
    const log = document.getElementById('bucket-log');
    if (!form || !fileInput || !window.XMLHttpRequest) return;

    function selectedFiles() {
        return Array.from(fileInput.files || []).concat(folderInput ? Array.from(folderInput.files || []) : []);
    }

    function displayName(file) {
        return file.webkitRelativePath || file.name;
    }

    function fmtBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let v = bytes;
        let i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (i ? v.toFixed(2) : String(v)) + ' ' + units[i];
    }

    function addLog(entry) {
        const status = String(entry.status || 'info').toLowerCase();
        const div = document.createElement('div');
        div.className = 'bucket-result bucket-result-' + status;
        const badge = document.createElement('span');
        badge.className = 'bucket-result-badge';
        badge.textContent = status;
        div.appendChild(badge);
        const file = document.createElement('span');
        file.className = 'bucket-result-file';
        file.textContent = entry.file || '';
        div.appendChild(file);
        const message = document.createElement('span');
        message.className = 'bucket-result-message';
        message.textContent = (entry.message || '') + (entry.file_size_text ? ' | size: ' + entry.file_size_text : '');
        div.appendChild(message);
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function uploadOne(file, index, total) {
        return new Promise(function (resolve) {
            const shownName = displayName(file);
            const data = new FormData();
            data.append('ajax', '1');
            data.append('csrf', form.querySelector('[name="csrf"]').value);
            data.append('relative_path', shownName);
            data.append('files[]', file, file.name);
            const xhr = new XMLHttpRequest();
            const start = Date.now();
            progressBar.value = 0;
            progressLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + shownName;
            progressCount.textContent = (index - 1) + ' of ' + total + ' complete';
            xhr.open('POST', form.action || window.location.href, true);
            xhr.upload.onprogress = function (e) {
                if (!e.lengthComputable) return;
                const percent = Math.round((e.loaded / e.total) * 100);
                const elapsed = Math.max(0.1, (Date.now() - start) / 1000);
                progressBar.value = percent;
                progressLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + shownName + ' (' + percent + '% at ' + fmtBytes(e.loaded / elapsed) + '/s)';
            };
            xhr.onload = function () {
                progressBar.value = 100;
                progressCount.textContent = index + ' of ' + total + ' complete';
                try {
                    const res = JSON.parse(xhr.responseText || '{}');
                    if (!res.ok) {
                        addLog({status: 'failed', file: shownName, message: res.error || 'server error'});
                    } else if (res.messages && res.messages.length) {
                        res.messages.forEach(addLog);
                    } else {
                        addLog({status: 'bucketed', file: shownName, message: 'stored and indexed in upload bucket'});
                    }
                } catch (e) {
                    addLog({status: 'failed', file: shownName, message: 'invalid server response'});
                }
                resolve();
            };
            xhr.onerror = function () {
                addLog({status: 'failed', file: shownName, message: 'upload connection error'});
                resolve();
            };
            xhr.send(data);
        });
    }

    form.addEventListener('submit', async function (event) {
        const files = selectedFiles();
        if (!files.length) {
            event.preventDefault();
            window.alert('Choose one or more files, or choose a folder/subfolders first.');
            return;
        }
        event.preventDefault();
        button.disabled = true;
        progressBox.hidden = false;
        log.textContent = '';
        for (let i = 0; i < files.length; i++) {
            await uploadOne(files[i], i + 1, files.length);
        }
        progressLabel.textContent = 'Upload bucket batch complete.';
        progressCount.textContent = files.length + ' of ' + files.length + ' complete';
        progressBar.value = 100;
        button.disabled = false;
    });
})();
</script>
HTML;

    catalog_foot();
} catch (Throwable $error) {
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => upload_bucket_short_error($error)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    catalog_head('Upload Bucket Error');
    echo CatalogUi::alert('danger', upload_bucket_short_error($error), 'The upload bucket could not be loaded.');
    catalog_foot();
}
