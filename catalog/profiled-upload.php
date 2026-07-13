<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/CatalogRedirectArchive.php';
require_once __DIR__ . '/lib/UploadProgress.php';
require_once __DIR__ . '/lib/FederationAuth.php';

function upload_short_error(Throwable $e): string
{
    $message = trim($e->getMessage());

    if (preg_match('/Bad package tag 0x[0-9A-Fa-f]+/', $message, $m)) {
        return $m[0];
    }

    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    $message = trim($message);

    return $message !== '' ? $message : 'Unknown error';
}

function upload_log_exception(PDO $db, string $filename, Throwable $e): void
{
    $details = $filename . ': ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    error_log('[UnrealDB upload] ' . $details);

    try {
        fed_log($db, null, null, 'ERROR', 'UPLOAD_SCAN_FAIL', $details);
    } catch (Throwable) {
        // Keep upload handling alive even if the optional app log table is unavailable.
    }
}

function upload_result(string $status, string $file, string $message, array $meta = []): array
{
    unset($meta['duplicate_guid'], $meta['duplicate_file_size_text']);
    return ['status' => $status, 'file' => $file, 'message' => $message] + $meta;
}

function upload_result_text(array $entry): string
{
    $text = (string)$entry['file'] . ': ' . (string)$entry['status'] . ' - ' . (string)$entry['message'];
    if (!empty($entry['file_size_text'])) {
        $text .= ' | size: ' . (string)$entry['file_size_text'];
    }
    if (!empty($entry['package_guid'])) {
        $text .= ' | GUID: ' . (string)$entry['package_guid'];
    }
    if (!empty($entry['duplicate_original_name'])) {
        $text .= ' | copy of: ' . (string)$entry['duplicate_original_name'];
    }
    return $text;
}

function upload_alias_already_exists(): bool
{
    return function_exists('catalog_package_alias_last_add_was_existing') && catalog_package_alias_last_add_was_existing();
}

/** @return array{tmp:string,name:string,display_name:string,decompressed:bool,source_extension:string,source_name:string} */
function upload_prepare_scanner_input(string $tmp, string $name, ?callable $progress = null): array
{
    $cleanName = catalog_clean_unreal_filename($name);
    if (!catalog_redirect_archive_is_supported_filename($name)) {
        return [
            'tmp' => $tmp,
            'name' => $cleanName,
            'display_name' => $cleanName,
            'decompressed' => false,
            'source_extension' => '',
            'source_name' => $name,
        ];
    }

    if ($progress) {
        $progress([
            'stage' => 'decompress',
            'done' => 1,
            'total' => 100,
            'percent' => 1,
            'message' => 'Decompressing redirect archive ' . basename($name),
        ]);
    }

    $decoded = catalog_redirect_archive_decompress_to_temp($tmp, $name);
    if (is_file($tmp)) {
        @unlink($tmp);
    }

    return [
        'tmp' => $decoded['path'],
        'name' => catalog_clean_unreal_filename($decoded['filename']),
        'display_name' => catalog_clean_unreal_filename($decoded['filename']),
        'decompressed' => true,
        'source_extension' => (string)$decoded['source_extension'],
        'source_name' => $name,
    ];
}

function upload_guid_is_zero_or_blank(string $guid): bool
{
    $guid = strtoupper(trim($guid));
    return $guid === '' || $guid === '00000000-00000000-00000000-00000000';
}

function upload_guid_from_legacy_header_offset(string $path): string
{
    $bytes = @file_get_contents($path, false, null, 0, 64);
    if (!is_string($bytes) || strlen($bytes) < 52) {
        return '';
    }
    $tag = (int)(unpack('V', substr($bytes, 0, 4))[1] ?? 0);
    if ($tag !== 0x9E2A83C1) {
        return '';
    }

    $parts = [
        (int)(unpack('V', substr($bytes, 36, 4))[1] ?? 0),
        (int)(unpack('V', substr($bytes, 40, 4))[1] ?? 0),
        (int)(unpack('V', substr($bytes, 44, 4))[1] ?? 0),
        (int)(unpack('V', substr($bytes, 48, 4))[1] ?? 0),
    ];
    if ($parts === [0, 0, 0, 0]) {
        return '';
    }

    return sprintf('%08X-%08X-%08X-%08X', $parts[0], $parts[1], $parts[2], $parts[3]);
}

function upload_correct_zero_guid_from_stored_file(PDO $db, array $config, array $result): string
{
    $fileId = (int)($result[1] ?? 0);
    if ($fileId <= 0) {
        return '';
    }

    $file = catalog_one($db, 'SELECT id, package_guid, relative_path FROM ue_files WHERE id=?', [$fileId]);
    if (!$file || !upload_guid_is_zero_or_blank((string)($file['package_guid'] ?? ''))) {
        return '';
    }

    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $storedPath = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    if (!$storageRoot || !$storedPath || !str_starts_with($storedPath, $storageRoot) || !is_file($storedPath)) {
        return '';
    }

    $fallbackGuid = upload_guid_from_legacy_header_offset($storedPath);
    if ($fallbackGuid === '') {
        return '';
    }

    $db->prepare('UPDATE ue_files SET package_guid=? WHERE id=?')->execute([$fallbackGuid, $fileId]);
    return $fallbackGuid;
}

function upload_handle_request(PDO $db, array $config): array
{
    catalog_check_csrf('profiled_upload');
    $gameId = (int)($_POST['game_id'] ?? 0);
    $strict = ($_POST['strict_profile'] ?? '1') === '1';
    $progressToken = upload_progress_token((string)($_POST['progress_token'] ?? ''));
    $userId = $_SESSION['user']['id'] ?? null;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }

    $progress = null;
    if ($progressToken !== '') {
        $progress = static function (array $state) use ($progressToken): void {
            upload_progress_write($progressToken, $state);
        };
    }

    $ok = 0;
    $dup = 0;
    $bad = 0;
    $messages = [];
    foreach ($_FILES['files']['tmp_name'] ?? [] as $i => $tmp) {
        $name = (string)($_FILES['files']['name'][$i] ?? 'upload.bin');
        $displayName = catalog_redirect_archive_is_supported_filename($name)
            ? catalog_redirect_archive_output_name($name)
            : catalog_clean_unreal_filename($name);
        $err = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $uploadSize = is_string($tmp) && is_file($tmp) ? (int)filesize($tmp) : 0;
        $uploadSizeMeta = [
            'file_size' => $uploadSize,
            'file_size_text' => catalog_bytes($uploadSize),
        ];
        if ($err !== UPLOAD_ERR_OK) {
            $bad++;
            $text = 'Upload error ' . $err;
            $messages[] = upload_result('failed', $displayName, $text, $uploadSizeMeta);
            if ($progress) {
                $progress(['stage' => 'failed', 'done' => 100, 'total' => 100, 'percent' => 100, 'message' => $displayName . ': failed - ' . $text]);
            }
            continue;
        }

        $scanTmp = (string)$tmp;
        $scanName = $displayName;
        $prepared = null;

        try {
            $prepared = upload_prepare_scanner_input($scanTmp, $name, $progress);
            $scanTmp = $prepared['tmp'];
            $scanName = $prepared['name'];
            $displayName = $prepared['display_name'];

            $scanSize = is_file($scanTmp) ? (int)(filesize($scanTmp) ?: 0) : 0;
            $scanSizeMeta = [
                'file_size' => $scanSize,
                'file_size_text' => catalog_bytes($scanSize),
            ];

            $result = scanner_scan_uploaded_file($db, $config, $gameId, $scanTmp, $scanName, $userId !== null ? (int)$userId : null, $strict, $progress);
            if (is_file($scanTmp)) {
                @unlink($scanTmp);
            }

            $meta = is_array($result[4] ?? null) ? $result[4] : $scanSizeMeta;
            $correctedGuid = upload_correct_zero_guid_from_stored_file($db, $config, $result);
            if ($correctedGuid !== '') {
                $meta['package_guid'] = $correctedGuid;
            }
            if (!empty($prepared['decompressed'])) {
                $meta['file_size'] = $meta['file_size'] ?? $scanSize;
                $meta['file_size_text'] = $meta['file_size_text'] ?? catalog_bytes($scanSize);
            }

            $aliasAlreadyExists = ($result[0] ?? '') === 'alias' && upload_alias_already_exists();
            $redirectPrefix = !empty($prepared['decompressed']) ? 'Decompressed .' . $prepared['source_extension'] . ' redirect archive; ' : '';
            if (($result[0] ?? '') === 'duplicate' || $aliasAlreadyExists) {
                $dup++;
                $message = $aliasAlreadyExists ? 'Package alias already exists for existing file identity' : (string)($result[2] ?? 'Duplicate in selected game');
                $messages[] = upload_result('duplicate', $displayName, $redirectPrefix . $message, $meta);
            } else {
                $ok++;
                $messages[] = upload_result('imported', $displayName, $redirectPrefix . (string)$result[2], $meta);
            }
        } catch (Throwable $e) {
            $bad++;
            $short = upload_short_error($e);
            upload_log_exception($db, $displayName, $e);

            if (isset($prepared) && is_array($prepared) && is_file($scanTmp)) {
                scanner_store_failed_upload($config, $scanTmp, $scanName, (string)$game['slug'], $e->getMessage());
            } elseif (is_file((string)$tmp) && !catalog_redirect_archive_is_supported_filename($name)) {
                scanner_store_failed_upload($config, (string)$tmp, $displayName, (string)$game['slug'], $e->getMessage());
            } elseif (is_file((string)$tmp)) {
                @unlink((string)$tmp);
            }

            $messages[] = upload_result('failed', $displayName, $short, $uploadSizeMeta);
            if ($progress) {
                $progress(['stage' => 'failed', 'done' => 100, 'total' => 100, 'percent' => 100, 'message' => $displayName . ': failed - ' . $short]);
            }
        }
    }

    return ['ok' => $ok, 'duplicate' => $dup, 'failed' => $bad, 'messages' => $messages];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (($_GET['progress'] ?? '') !== '') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode(upload_progress_read((string)$_GET['progress']));
        exit;
    }

    if (!catalog_support_is_admin()) {
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Admin required']);
            exit;
        }
        if (!catalog_require_admin_page('Upload Files')) {
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = upload_handle_request($db, $config);
        if (($_POST['ajax'] ?? '') === '1') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true] + $result);
            exit;
        }
        session_start();
        $messageText = array_map('upload_result_text', array_slice($result['messages'], 0, 12));
        $_SESSION['profiled_upload_flash'] = 'Upload complete. Imported=' . $result['ok'] . ' Duplicate=' . $result['duplicate'] . ' Failed=' . $result['failed'] . '. ' . implode(' | ', $messageText);
        header('Location: profiled-upload.php?game_id=' . (int)($_POST['game_id'] ?? 0));
        exit;
    }

    catalog_head('Upload Files');
    catalog_flash($_SESSION['profiled_upload_flash'] ?? null);
    unset($_SESSION['profiled_upload_flash']);

    $selectedGameId = (int)($_GET['game_id'] ?? 0);
    $games = catalog_all($db, 'SELECT g.id, g.name, g.slug, p.engine_key profile_engine, p.allowed_extensions_json, p.package_version_min, p.package_version_max FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name');

    catalog_page_header('Upload Files', 'Import packages into the selected game using its assigned scanner profile. You can select individual files or a whole folder/subfolders. Redirect-compressed .uz/.uz2/.uz3 uploads are decompressed first and only the real package is retained.', ['Game Admin' => 'game-manager.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''), 'Sources' => 'sources.php' . ($selectedGameId ? '?game_id=' . $selectedGameId : ''), 'Library' => 'library.php']);

    echo '<div class="card"><h2>Upload and scan</h2><form id="profiled-upload-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('profiled_upload')) . '">';
    echo '<p><label>Target game<br><select name="game_id" required>';
    foreach ($games as $game) {
        $sel = ((int)$game['id'] === $selectedGameId) ? ' selected' : '';
        $label = $game['name'] . ' / ' . (!empty($game['profile_engine']) ? $game['profile_engine'] : 'no active profile');
        echo '<option value="' . (int)$game['id'] . '"' . $sel . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject/move mismatches to unverified</option><option value="0">Loose: allow scanner/parser to try anyway</option></select></label></p>';
    echo '<p><label>Choose files<br><input id="profiled-upload-files" type="file" name="files[]" multiple></label></p>';
    echo '<p><label>Choose folder / subfolders<br><input id="profiled-upload-folder" type="file" multiple webkitdirectory directory mozdirectory></label></p>';
    echo '<p><button id="profiled-upload-button">Upload and scan</button></p>';
    echo '<p class="muted">Max per uploaded/decompressed file: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '. Browser folder upload includes files from the selected folder and its subfolders; the catalog still uses only the cleaned package filename and file identity, not the client folder path. Files are uploaded one at a time so browser/PHP file-count limits do not stop large batches. Allowed inputs: catalog package extensions plus .uz/.uz2/.uz3 redirect archives.</p>';
    echo '<div id="upload-progress" class="upload-progress" hidden>';
    echo '<div class="progress-row"><span id="overall-progress-label">Overall batch</span><span id="overall-progress-count"></span></div><progress id="overall-progress-bar" value="0" max="100"></progress>';
    echo '<div class="progress-row"><span id="upload-progress-label">Waiting...</span><span id="upload-progress-speed"></span></div><progress id="upload-progress-bar" value="0" max="100"></progress>';
    echo '<div id="upload-progress-log" class="upload-progress-log"></div></div>';
    echo '</form></div>';

    echo '<div class="card"><h2>Game profiles</h2><table><tr><th>Game</th><th>Profile engine</th><th>Extensions</th><th>Version range</th><th>Open</th></tr>';
    foreach ($games as $game) {
        $exts = json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true);
        $range = ($game['package_version_min'] !== null || $game['package_version_max'] !== null) ? (($game['package_version_min'] ?? '?') . ' - ' . ($game['package_version_max'] ?? '?')) : 'not fixed';
        $engine = $game['profile_engine'] ?: 'missing profile';
        $engineClass = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
        echo '<tr><td>' . catalog_h($game['name']) . '</td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td class="mono">' . catalog_h(is_array($exts) ? implode(', ', $exts) : '') . '</td><td class="mono">' . catalog_h($range) . '</td><td><a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">select</a></td></tr>';
    }
    echo '</table></div>';

    echo <<<'HTML'
<script>
(function () {
    const form = document.getElementById('profiled-upload-form');
    const fileInput = document.getElementById('profiled-upload-files');
    const folderInput = document.getElementById('profiled-upload-folder');
    const button = document.getElementById('profiled-upload-button');
    const progressBox = document.getElementById('upload-progress');
    const currentBar = document.getElementById('upload-progress-bar');
    const overallBar = document.getElementById('overall-progress-bar');
    const currentLabel = document.getElementById('upload-progress-label');
    const overallLabel = document.getElementById('overall-progress-label');
    const overallCount = document.getElementById('overall-progress-count');
    const speed = document.getElementById('upload-progress-speed');
    const log = document.getElementById('upload-progress-log');
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
        while (v >= 1024 && i < units.length - 1) {
            v /= 1024;
            i++;
        }
        return (i ? v.toFixed(2) : String(v)) + ' ' + units[i];
    }

    function makeToken() {
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function appendMetaText(container, label, value) {
        if (value === undefined || value === null || value === '') return;
        container.appendChild(document.createTextNode(' | ' + label + ': ' + String(value)));
    }

    function makeExamineLink(fileId, text) {
        const link = document.createElement('a');
        link.href = 'file-examine.php?id=' + encodeURIComponent(fileId);
        link.textContent = text || ('file #' + fileId);
        return link;
    }

    function addLog(entry) {
        if (typeof entry === 'string') {
            entry = {status: 'info', file: '', message: entry};
        }
        const status = String(entry.status || 'info').toLowerCase();
        const div = document.createElement('div');
        div.className = 'upload-result upload-result-' + status;

        const badge = document.createElement('span');
        badge.className = 'upload-result-badge';
        badge.textContent = status;
        div.appendChild(badge);

        if (entry.file) {
            let file;
            if (entry.file_id) {
                file = makeExamineLink(entry.file_id, entry.file);
            } else {
                file = document.createElement('span');
                file.textContent = entry.file;
            }
            file.className = 'upload-result-file';
            div.appendChild(file);
        }

        const message = document.createElement('span');
        message.className = 'upload-result-message';
        message.textContent = entry.message || '';
        appendMetaText(message, 'size', entry.file_size_text);
        appendMetaText(message, 'GUID', entry.package_guid);

        if (entry.duplicate_file_id) {
            message.appendChild(document.createTextNode(' | copy of: '));
            message.appendChild(makeExamineLink(entry.duplicate_file_id, entry.duplicate_original_name));
        }

        div.appendChild(message);

        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function setOverall(doneFiles, totalFiles, currentPercent) {
        const percent = Math.round(((doneFiles + (currentPercent / 100)) / Math.max(1, totalFiles)) * 100);
        overallBar.value = percent;
        overallLabel.textContent = 'Overall batch progress (' + percent + '%)';
        overallCount.textContent = doneFiles + ' of ' + totalFiles + ' complete';
    }

    function pollScanProgress(token, index, total, fileName, stopFlag) {
        return window.setInterval(function () {
            fetch('profiled-upload.php?progress=' + encodeURIComponent(token), {cache: 'no-store'})
                .then(function (r) { return r.json(); })
                .then(function (state) {
                    if (stopFlag.done) return;
                    const percent = Math.max(0, Math.min(100, parseInt(state.percent || 0, 10)));
                    currentBar.value = percent;
                    currentLabel.textContent = 'Reading/scanning ' + index + ' of ' + total + ': ' + fileName + ' (' + percent + '%) - ' + (state.message || 'working');
                    speed.textContent = '';
                    setOverall(index - 1, total, percent);
                })
                .catch(function () {});
        }, 650);
    }

    function uploadOne(file, index, total) {
        return new Promise(function (resolve) {
            const token = makeToken();
            const data = new FormData();
            const shownName = displayName(file);
            data.append('ajax', '1');
            data.append('progress_token', token);
            data.append('csrf', form.querySelector('[name="csrf"]').value);
            data.append('game_id', form.querySelector('[name="game_id"]').value);
            data.append('strict_profile', form.querySelector('[name="strict_profile"]').value);
            data.append('files[]', file, file.name);

            const xhr = new XMLHttpRequest();
            const start = Date.now();
            const stopFlag = {done: false};
            let poller = null;
            currentBar.value = 0;
            speed.textContent = '';
            currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + shownName + ' (0%)';
            setOverall(index - 1, total, 0);

            xhr.open('POST', form.action || window.location.href, true);
            xhr.upload.onprogress = function (e) {
                if (!e.lengthComputable) return;
                const percent = Math.round((e.loaded / e.total) * 100);
                currentBar.value = percent;
                const elapsed = Math.max(0.1, (Date.now() - start) / 1000);
                speed.textContent = fmtBytes(e.loaded / elapsed) + '/s';
                currentLabel.textContent = 'Uploading ' + index + ' of ' + total + ': ' + shownName + ' (' + percent + '%)';
                setOverall(index - 1, total, Math.min(50, percent / 2));
            };
            xhr.upload.onload = function () {
                currentBar.value = 0;
                speed.textContent = '';
                currentLabel.textContent = 'Reading/scanning ' + index + ' of ' + total + ': ' + shownName + ' (0%)';
                poller = pollScanProgress(token, index, total, shownName, stopFlag);
            };
            xhr.onload = function () {
                stopFlag.done = true;
                if (poller) window.clearInterval(poller);
                currentBar.value = 100;
                setOverall(index, total, 0);
                try {
                    const res = JSON.parse(xhr.responseText || '{}');
                    if (!res.ok) {
                        addLog({status: 'failed', file: shownName, message: res.error || 'server error'});
                    } else if (res.messages && res.messages.length) {
                        res.messages.forEach(addLog);
                    } else {
                        addLog({status: 'imported', file: shownName, message: 'complete'});
                    }
                } catch (e) {
                    addLog({status: 'failed', file: shownName, message: 'invalid server response'});
                }
                resolve();
            };
            xhr.onerror = function () {
                stopFlag.done = true;
                if (poller) window.clearInterval(poller);
                addLog({status: 'failed', file: shownName, message: 'upload connection error'});
                setOverall(index, total, 0);
                resolve();
            };
            xhr.send(data);
        });
    }

    form.addEventListener('submit', async function (e) {
        const files = selectedFiles();
        if (!files.length) {
            e.preventDefault();
            window.alert('Choose one or more files, or choose a folder/subfolders first.');
            return;
        }
        e.preventDefault();
        button.disabled = true;
        progressBox.hidden = false;
        log.textContent = '';
        overallBar.value = 0;
        currentBar.value = 0;
        setOverall(0, files.length, 0);
        for (let i = 0; i < files.length; i++) {
            await uploadOne(files[i], i + 1, files.length);
        }
        currentLabel.textContent = 'Upload and scan batch complete.';
        speed.textContent = '';
        overallBar.value = 100;
        overallLabel.textContent = 'Overall batch complete (100%)';
        overallCount.textContent = files.length + ' of ' + files.length + ' complete';
        button.disabled = false;
    });
})();
</script>
HTML;

    catalog_foot();
} catch (Throwable $e) {
    if (($_POST['ajax'] ?? '') === '1') {
        if (isset($db) && $db instanceof PDO) {
            upload_log_exception($db, 'upload request', $e);
        } else {
            error_log('[UnrealDB upload] upload request: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => upload_short_error($e)]);
        exit;
    }
    if (!headers_sent()) {
        catalog_head('Upload error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h(upload_short_error($e)) . '</p></div>';
    catalog_foot();
}
