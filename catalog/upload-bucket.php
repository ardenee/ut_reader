<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/lib/CatalogRedirectArchive.php';
require_once __DIR__ . '/lib/CatalogEpicRedirect.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function upload_bucket_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    $message = trim($message);
    return $message !== '' ? $message : 'Unknown error';
}

function upload_bucket_is_ajax_request(): bool
{
    return (string)($_GET['ajax'] ?? $_POST['ajax'] ?? '') === '1'
        || (string)($_SERVER['HTTP_X_UPLOAD_BUCKET_AJAX'] ?? '') === '1';
}

function upload_bucket_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?$/i', $value, $match) !== 1) {
        return max(0, (int)$value);
    }
    $number = (float)$match[1];
    $power = match (strtoupper((string)$match[2])) {
        'K' => 1,
        'M' => 2,
        'G' => 3,
        'T' => 4,
        'P' => 5,
        default => 0,
    };
    return (int)floor($number * (1024 ** $power));
}

function upload_bucket_php_limit_text(string $setting): string
{
    $raw = trim((string)ini_get($setting));
    $bytes = upload_bucket_ini_bytes($raw);
    return $bytes > 0 ? catalog_bytes($bytes) . ' (' . $raw . ')' : ($raw !== '' ? $raw : 'unknown');
}

function upload_bucket_post_limit_error(): ?string
{
    $contentLength = max(0, (int)($_SERVER['CONTENT_LENGTH'] ?? 0));
    $postMax = upload_bucket_ini_bytes((string)ini_get('post_max_size'));
    if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
        return 'Request body ' . catalog_bytes($contentLength)
            . ' exceeds PHP post_max_size ' . upload_bucket_php_limit_text('post_max_size')
            . '. The JavaScript chunk uploader was not used or the configured chunk is too large.';
    }
    return null;
}

/** @return list<string> */
function upload_bucket_allowed_extensions(PDO $db, array $config): array
{
    $extensions = [];
    foreach (gp_all_profiles($db) as $profile) {
        foreach (gp_extensions($profile) as $extension) {
            $extension = catalog_clean_unreal_extension((string)$extension);
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
        }
    }

    if ($extensions === []) {
        foreach (($config['allowed_extensions'] ?? []) as $extension) {
            $extension = catalog_clean_unreal_extension((string)$extension);
            if ($extension !== '') {
                $extensions[$extension] = true;
            }
        }
    }

    $result = array_keys($extensions);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($result);
}

function upload_bucket_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE => 'PHP rejected the fallback whole-file request because it exceeds upload_max_filesize ' . upload_bucket_php_limit_text('upload_max_filesize') . '.',
        UPLOAD_ERR_FORM_SIZE => 'The fallback browser upload exceeded the form file-size limit.',
        UPLOAD_ERR_PARTIAL => 'Only part of the file reached the server. Retry the file and check the connection or reverse-proxy timeout.',
        UPLOAD_ERR_NO_FILE => 'No file data reached PHP.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file to its temporary directory.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload before UnrealDB could process it.',
        default => 'PHP upload failed with error code ' . $error . '.',
    };
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

/** @return array{count:int,bytes:int} */
function upload_bucket_stats(string $bucketDir): array
{
    $count = 0;
    $bytes = 0;
    if (!is_dir($bucketDir) || !is_readable($bucketDir)) {
        return ['count' => 0, 'bytes' => 0];
    }

    $iterator = new FilesystemIterator($bucketDir, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $name = $entry->getFilename();
        if (str_starts_with($name, '.') || str_ends_with(strtolower($name), '.txt')) {
            continue;
        }
        $count++;
        $size = $entry->getSize();
        if ($size > 0) {
            $bytes += $size;
        }
    }
    return ['count' => $count, 'bytes' => $bytes];
}

function upload_bucket_chunk_bytes(array $config): int
{
    $chunkConfig = is_array($config['chunk_upload'] ?? null) ? $config['chunk_upload'] : [];
    return max(1024 * 1024, min((int)($chunkConfig['chunk_bytes'] ?? (16 * 1024 * 1024)), 64 * 1024 * 1024));
}

/** Whole-file POST fallback for browsers where the chunk uploader cannot run. */
function upload_bucket_handle_request(PDO $db, array $config): array
{
    $postLimitError = upload_bucket_post_limit_error();
    if ($postLimitError !== null) {
        throw new RuntimeException($postLimitError);
    }

    catalog_check_csrf('upload-bucket');
    if (!isset($_FILES['files'])) {
        throw new RuntimeException(
            'No files reached the fallback bucket handler. PHP upload_max_filesize=' . upload_bucket_php_limit_text('upload_max_filesize')
            . '; post_max_size=' . upload_bucket_php_limit_text('post_max_size') . '.'
        );
    }

    $allowedExtensions = upload_bucket_allowed_extensions($db, $config);
    $stager = new LegacyUnverifiedFileStager($db, $config);
    $uploadedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    $ok = 0;
    $duplicates = 0;
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
            $messages[] = upload_bucket_result('failed', $cleanName, upload_bucket_upload_error_message($err), $meta);
            continue;
        }

        $workingTmp = (string)$tmp;
        $workingName = $cleanName;
        $decompressed = null;
        try {
            if ($size <= 0) {
                throw new RuntimeException('The uploaded file is empty.');
            }
            if (catalog_redirect_archive_is_supported_filename($submittedName)) {
                $decompressed = catalog_epic_redirect_decompress_to_temp(
                    $workingTmp,
                    $submittedName,
                    PHP_INT_MAX,
                    true
                );
                $workingTmp = (string)$decompressed['path'];
                $workingName = catalog_clean_unreal_filename((string)$decompressed['filename']);
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            $storedSize = is_file($workingTmp) ? (int)(filesize($workingTmp) ?: 0) : 0;
            if ($storedSize <= 0) {
                throw new RuntimeException('The stored/decompressed file is empty.');
            }
            $ext = catalog_clean_unreal_extension((string)pathinfo($workingName, PATHINFO_EXTENSION));
            if ($allowedExtensions !== [] && !in_array($ext, $allowedExtensions, true)) {
                throw new RuntimeException(
                    'Extension .' . ($ext !== '' ? $ext : '(none)')
                    . ' is not allowed by any active game profile. See the allowed profile extension list above the upload results.'
                );
            }

            $cleanNote = $submittedName !== $workingName ? ' Original browser filename was: ' . basename($submittedName) . '.' : '';
            $redirectNote = is_array($decompressed)
                ? ' Redirect archive .' . $decompressed['source_extension'] . ' was decompressed before storage; compressed wrapper was not retained. Decoder: ' . $decompressed['decoder'] . '.'
                : '';
            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s') . '. No game assignment has been made yet.' . $redirectNote . $cleanNote;
            $staged = $stager->stageBucketUpload(
                $workingTmp,
                $workingName,
                $note,
                $uploadedBy,
                upload_bucket_source_relative_path($submittedRelativePath, $workingName)
            );

            if ((string)($staged['status'] ?? '') === 'duplicate') {
                $duplicates++;
                $messages[] = upload_bucket_result('duplicate', $workingName, (string)$staged['message'], [
                    'file_size' => $storedSize,
                    'file_size_text' => catalog_bytes($storedSize),
                    'existing_file_id' => (int)$staged['file_id'],
                    'md5' => (string)($staged['md5'] ?? ''),
                ]);
                continue;
            }

            $ok++;
            $message = is_array($decompressed)
                ? 'Decompressed redirect archive into upload bucket and indexed as unverified using ' . $decompressed['decoder']
                : 'Stored in upload bucket and indexed as unverified';
            if ($staged['parse_error'] !== null) {
                $message .= '; package tables could not be read: ' . upload_bucket_short_error(new RuntimeException((string)$staged['parse_error']));
            }
            $messages[] = upload_bucket_result(is_array($decompressed) ? 'decompressed' : 'bucketed', $workingName, $message, [
                'file_size' => (int)$staged['size'],
                'file_size_text' => catalog_bytes((int)$staged['size']),
                'queue_name' => (string)$staged['queue_name'],
                'file_id' => (int)$staged['file_id'],
            ]);
        } catch (Throwable $error) {
            $failed++;
            $requestId = catalog_request_id();
            error_log('[UnrealDB][' . $requestId . '] bucket upload failed for ' . $submittedRelativePath . ': ' . get_class($error) . ': ' . $error->getMessage());
            $messages[] = upload_bucket_result('failed', $workingName, upload_bucket_short_error($error) . ' | reference: ' . $requestId, $meta);
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
            if ($workingTmp !== (string)$tmp && is_file($workingTmp)) {
                @unlink($workingTmp);
            }
        }
    }

    return ['ok' => $ok, 'duplicates' => $duplicates, 'failed' => $failed, 'messages' => $messages];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Upload Bucket')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = upload_bucket_handle_request($db, $config);
        if (upload_bucket_is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'request_id' => catalog_request_id()] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['upload_bucket_flash'] = 'Bucket upload complete. Stored=' . $result['ok'] . ' Duplicate=' . $result['duplicates'] . ' Failed=' . $result['failed'] . '. ' . implode(' | ', array_map('upload_bucket_result_text', array_slice($result['messages'], 0, 12)));
        header('Location: upload-bucket.php');
        exit;
    }

    $bucketDir = uvf_upload_bucket_dir($config, true);
    $bucketStats = upload_bucket_stats($bucketDir);
    $allowedExtensions = upload_bucket_allowed_extensions($db, $config);
    $chunkBytes = upload_bucket_chunk_bytes($config);

    catalog_head('Upload Bucket');
    catalog_flash($_SESSION['upload_bucket_flash'] ?? null);
    unset($_SESSION['upload_bucket_flash']);

    echo <<<'CSS'
<style>
.bucket-stats { grid-template-columns:minmax(125px,.55fr) minmax(145px,.7fr) minmax(300px,2.75fr); }
.bucket-stats .stat { min-width:0; }
.bucket-path-card { min-width:0; }
.bucket-path { white-space:normal; overflow-wrap:anywhere; word-break:break-word; line-height:1.35; }
.bucket-progress { margin-top:12px; border:1px solid var(--line); border-radius:14px; padding:12px; background:rgba(255,255,255,.03); }
.bucket-progress progress { width:100%; height:18px; }
.bucket-progress .progress-row + progress { margin-bottom:10px; }
.bucket-log { max-height:320px; overflow:auto; margin-top:10px; font-family:Consolas, ui-monospace, monospace; font-size:12px; color:var(--muted); }
.bucket-result { display:flex; gap:8px; align-items:baseline; padding:3px 0; white-space:nowrap; }
.bucket-result-badge { min-width:98px; font-weight:700; text-transform:uppercase; }
.bucket-result-file { color:var(--text); }
.bucket-result-message { color:var(--muted); white-space:normal; }
.bucket-result-bucketed .bucket-result-badge, .bucket-result-decompressed .bucket-result-badge { color:#a7f3d0; }
.bucket-result-duplicate .bucket-result-badge { color:#fde68a; }
.bucket-result-failed .bucket-result-badge { color:#fecdd3; }
.bucket-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
@media (max-width:900px) { .bucket-stats { grid-template-columns:1fr; } }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Upload Bucket',
        'Upload unsorted Unreal package files into a neutral bucket. Files matching an existing bucket row by size and MD5 are discarded instead of creating another physical file or unverified database row. Redirect-compressed .uz/.uz2/.uz3 uploads are decompressed first and compared using the real package content.',
        ['Open Bucket Queue' => 'unverified-files.php?source_game_id=-1', 'All Queues' => 'unverified-files.php', 'Upload Files to Game' => 'profiled-upload.php']
    );

    echo '<div class="grid bucket-stats">';
    echo '<div class="stat"><h2>' . (int)$bucketStats['count'] . '</h2><p>Files in upload bucket</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$bucketStats['bytes'])) . '</h2><p>Bucket storage</p></div>';
    echo '<div class="stat bucket-path-card"><h2 class="mono small bucket-path">' . catalog_h($bucketDir) . '</h2><p>Physical bucket folder</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Upload unsorted files</h2><p>Every selected file is uploaded in resumable chunks. Each retained file receives an unverified database row immediately, but no game assignment or dependencies. Duplicate size+MD5 content already present in this bucket is reported and discarded.</p></div></div><div class="ui-section__body">';
    echo '<form id="upload-bucket-form" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload-bucket')) . '">';
    echo '<p><label>Choose files<br><input id="upload-bucket-files" type="file" name="files[]" multiple></label></p>';
    echo '<p><label>Choose folder / subfolders<br><input id="upload-bucket-folder" type="file" multiple webkitdirectory directory mozdirectory></label></p>';
    echo '<p><button id="upload-bucket-button" type="submit">Upload to bucket</button></p>';
    echo '<p class="muted"><strong>Allowed by active game profiles:</strong> ' . catalog_h($allowedExtensions ? implode(', ', $allowedExtensions) : 'none configured') . ', plus .uz/.uz2/.uz3 wrappers whose decompressed extension is allowed.</p>';
    echo '<p class="muted"><strong>Upload sizing:</strong> No UnrealDB total-file-size limit is applied to bucket uploads. Files are split into chunks of up to ' . catalog_h(catalog_bytes($chunkBytes)) . '; PHP upload_max_filesize and post_max_size only need to accept one chunk plus multipart overhead.</p>';
    echo '<div id="bucket-progress" class="bucket-progress" hidden'
        . ' data-chunk-url="api/v1/upload-bucket-chunk.php"'
        . ' data-chunk-csrf="' . catalog_h(catalog_csrf('upload_bucket_chunk')) . '"'
        . ' data-chunk-bytes="' . $chunkBytes . '">';
    echo '<div class="progress-row"><span id="bucket-overall-progress-label">Overall batch progress</span><span id="bucket-overall-progress-count"></span></div><progress id="bucket-overall-progress-bar" value="0" max="100"></progress>';
    echo '<div class="progress-row"><span id="bucket-progress-label">Waiting...</span><span id="bucket-progress-speed"></span></div><progress id="bucket-progress-bar" value="0" max="100"></progress>';
    echo '<div id="bucket-log" class="bucket-log"></div></div>';
    echo '</form>';
    echo '<p class="bucket-actions"><a class="button" href="unverified-files.php?source_game_id=-1">Review bucket / assign files</a><a class="button secondary" href="unverified-files.php">Review all queues</a></p>';
    echo '</div></section>';

    $scriptPath = __DIR__ . '/assets/upload-bucket.js';
    $scriptVersion = is_file($scriptPath) ? (string)(filemtime($scriptPath) ?: 1) : '1';
    echo '<script src="assets/upload-bucket.js?v=' . catalog_h($scriptVersion) . '"></script>';

    catalog_foot();
} catch (Throwable $error) {
    $message = upload_bucket_short_error($error);
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] upload bucket request failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (upload_bucket_is_ajax_request()) {
        $status = str_contains($message, 'exceeds PHP post_max_size') ? 413 : 500;
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message, 'request_id' => $requestId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    catalog_head('Upload Bucket Error');
    echo CatalogUi::alert('danger', $message . ' Reference: ' . $requestId, 'The upload bucket could not be loaded.');
    catalog_foot();
}
