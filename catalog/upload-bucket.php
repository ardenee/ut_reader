<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
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

function upload_bucket_handle_request(array $config): array
{
    catalog_check_csrf('upload-bucket');
    if (!isset($_FILES['files'])) {
        throw new RuntimeException('No files were uploaded.');
    }

    $allowedExtensions = array_map(static fn($ext): string => strtolower(ltrim((string)$ext, '.')), $config['allowed_extensions'] ?? []);
    $allowedExtensions = array_values(array_filter(array_unique($allowedExtensions), static fn(string $ext): bool => $ext !== ''));
    $maxBytes = (int)($config['max_upload_bytes'] ?? PHP_INT_MAX);

    $ok = 0;
    $failed = 0;
    $messages = [];
    $temporaryPaths = $_FILES['files']['tmp_name'] ?? [];
    if (!is_array($temporaryPaths)) {
        $temporaryPaths = [];
    }

    foreach ($temporaryPaths as $index => $tmp) {
        $submittedName = (string)($_FILES['files']['name'][$index] ?? 'upload.bin');
        $cleanName = catalog_clean_unreal_filename($submittedName);
        $err = (int)($_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        $size = is_string($tmp) && is_file($tmp) ? (int)(filesize($tmp) ?: 0) : 0;
        $meta = ['file_size' => $size, 'file_size_text' => catalog_bytes($size)];

        if ($err !== UPLOAD_ERR_OK) {
            $failed++;
            $messages[] = upload_bucket_result('failed', $cleanName, 'Upload error ' . $err, $meta);
            continue;
        }

        try {
            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('Bad file size: ' . catalog_bytes($size));
            }
            $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
            if ($allowedExtensions !== [] && !in_array($ext, $allowedExtensions, true)) {
                throw new RuntimeException('Extension not allowed for bucket upload: ' . ($ext !== '' ? $ext : '(none)'));
            }

            $cleanNote = $submittedName !== $cleanName ? ' Original browser filename was: ' . basename($submittedName) . '.' : '';
            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s') . '. No game assignment has been made yet.' . $cleanNote;
            $stored = uvf_store_bucket_upload($config, (string)$tmp, $cleanName, $note);
            $ok++;
            $messages[] = upload_bucket_result('bucketed', $cleanName, 'Stored in upload bucket', [
                'file_size' => (int)$stored['size'],
                'file_size_text' => catalog_bytes((int)$stored['size']),
                'queue_name' => (string)$stored['queue_name'],
            ]);
        } catch (Throwable $error) {
            $failed++;
            $messages[] = upload_bucket_result('failed', $cleanName, upload_bucket_short_error($error), $meta);
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    return ['ok' => $ok, 'failed' => $failed, 'messages' => $messages];
}

try {
    $config = catalog_config();
    if (!catalog_require_admin_page('Upload Bucket')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = upload_bucket_handle_request($config);
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
    $bucketItems = uvf_list(catalog_db($config), $config, 0);

    catalog_head('Upload Bucket');
    catalog_flash($_SESSION['upload_bucket_flash'] ?? null);
    unset($_SESSION['upload_bucket_flash']);

    echo <<<'CSS'
<style>
.bucket-progress { margin-top:12px; border:1px solid var(--line); border-radius:14px; padding:12px; background:rgba(255,255,255,.03); }
.bucket-progress progress { width:100%; height:18px; }
.bucket-log { max-height:260px; overflow:auto; margin-top:10px; font-family:Consolas, ui-monospace, monospace; font-size:12px; color:var(--muted); }
.bucket-result { display:flex; gap:8px; align-items:baseline; padding:3px 0; }
.bucket-result-badge { min-width:78px; font-weight:700; text-transform:uppercase; }
.bucket-result-file { color:var(--text); }
.bucket-result-message { color:var(--muted); }
.bucket-result-bucketed .bucket-result-badge { color:#a7f3d0; }
.bucket-result-failed .bucket-result-badge { color:#fecdd3; }
.bucket-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Upload Bucket',
        'Upload unsorted Unreal package files into a neutral bucket. Files stay physical-only until you assign/import them into a game from the queue manager.',
        ['Open Bucket Queue' => 'unverified-files.php?source_game_id=-1', 'All Queues' => 'unverified-files.php', 'Upload Files to Game' => 'profiled-upload.php']
    );

    echo '<div class="grid">';
    echo '<div class="stat"><h2>' . count($bucketItems) . '</h2><p>Files in upload bucket</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes(array_sum(array_map(static fn(array $item): int => (int)$item['size'], $bucketItems)))) . '</h2><p>Bucket storage</p></div>';
    echo '<div class="stat"><h2 class="mono small">' . catalog_h($bucketDir) . '</h2><p>Physical bucket folder</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Upload unsorted files</h2><p>Files are uploaded one at a time to avoid browser/PHP file-count limits. No game, ue_files row, names, imports, exports, or dependencies are created here.</p></div></div><div class="ui-section__body">';
    echo '<form id="upload-bucket-form" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload-bucket')) . '">';
    echo '<p><input id="upload-bucket-files" type="file" name="files[]" multiple required> <button id="upload-bucket-button" type="submit">Upload to bucket</button></p>';
    echo '<p class="muted">Max per file: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '. Allowed extensions use the catalog global allowed extension list.</p>';
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
    const button = document.getElementById('upload-bucket-button');
    const progressBox = document.getElementById('bucket-progress');
    const progressBar = document.getElementById('bucket-progress-bar');
    const progressLabel = document.getElementById('bucket-progress-label');
    const progressCount = document.getElementById('bucket-progress-count');
    const log = document.getElementById('bucket-log');
    if (!form || !fileInput || !window.XMLHttpRequest) return;

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
            const data = new FormData();
            data.append('ajax', '1');
            data.append('csrf', form.querySelector('[name="csrf"]').value);
            data.append('files[]', file, file.name);
            const xhr = new XMLHttpRequest();
            const start = Date.now();
            progressBar.value = 0;
            progressLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + file.name;
            progressCount.textContent = (index - 1) + ' of ' + total + ' complete';
            xhr.open('POST', form.action || window.location.href, true);
            xhr.upload.onprogress = function (e) {
                if (!e.lengthComputable) return;
                const percent = Math.round((e.loaded / e.total) * 100);
                const elapsed = Math.max(0.1, (Date.now() - start) / 1000);
                progressBar.value = percent;
                progressLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + file.name + ' (' + percent + '% at ' + fmtBytes(e.loaded / elapsed) + '/s)';
            };
            xhr.onload = function () {
                progressBar.value = 100;
                progressCount.textContent = index + ' of ' + total + ' complete';
                try {
                    const res = JSON.parse(xhr.responseText || '{}');
                    if (!res.ok) {
                        addLog({status: 'failed', file: file.name, message: res.error || 'server error'});
                    } else if (res.messages && res.messages.length) {
                        res.messages.forEach(addLog);
                    } else {
                        addLog({status: 'bucketed', file: file.name, message: 'stored in upload bucket'});
                    }
                } catch (e) {
                    addLog({status: 'failed', file: file.name, message: 'invalid server response'});
                }
                resolve();
            };
            xhr.onerror = function () {
                addLog({status: 'failed', file: file.name, message: 'upload connection error'});
                resolve();
            };
            xhr.send(data);
        });
    }

    form.addEventListener('submit', async function (event) {
        const files = Array.from(fileInput.files || []);
        if (!files.length) return;
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
