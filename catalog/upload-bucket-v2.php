<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the canonical Upload Bucket interface using the JavaScript/API resumable uploader.
 * Why: This is the proven upload workflow and replaces the retired legacy uploader while retaining the same server APIs.
 * Role: Primary Upload Bucket UI for unsorted files, resumable transfer, validation, and background queue handoff.
 * Audit: Canonical implementation; keep `upload-bucket.php` only as a temporary compatibility redirect.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadTransferStoreFactory;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;

catalog_start_session();
require_once __DIR__ . '/lib/UnverifiedFileManager.php';

function upload_bucket_v2_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    return trim($message) !== '' ? trim($message) : 'Unknown error';
}

/** @return array{count:int,bytes:int} */
function upload_bucket_v2_stats(string $bucketDir): array
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

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Upload Bucket')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        throw new RuntimeException(
            'The new Upload Bucket requires JavaScript and its API uploader. Reload the page with JavaScript enabled.'
        );
    }

    $bucketDir = uvf_upload_bucket_dir($config, true);
    $bucketStats = upload_bucket_v2_stats($bucketDir);
    $allowedExtensions = (new CatalogUploadBucketFilePolicy($db, $config))->allowedExtensions();
    sort($allowedExtensions, SORT_NATURAL | SORT_FLAG_CASE);
    $allowedExtensionJson = json_encode(
        array_values($allowedExtensions),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $chunkBytes = CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes($config);
    $processingUrl = 'background-jobs.php?queue=catalog%3Abucket-processing';

    catalog_head('Upload Bucket');

    echo <<<'CSS'
<style>
.bucket-stats { grid-template-columns:minmax(125px,.55fr) minmax(145px,.7fr) minmax(300px,2.75fr); }
.bucket-stats .stat { min-width:0; }
.bucket-path-card { min-width:0; }
.bucket-path { white-space:normal; overflow-wrap:anywhere; word-break:break-word; line-height:1.35; }
.bucket-progress { margin-top:12px; border:1px solid var(--line); border-radius:14px; padding:12px; background:rgba(255,255,255,.03); }
.bucket-progress progress { width:100%; height:18px; }
.bucket-progress .progress-row + progress { margin-bottom:10px; }
.bucket-progress-note { margin:8px 0 0; color:var(--muted); }
.bucket-log-filter { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top:10px; }
.bucket-log-filter label { font-weight:700; }
.bucket-log-filter a { margin-left:auto; }
.bucket-log { position:relative; height:420px; overflow:auto; margin-top:10px; border-top:1px solid var(--line); font-family:Consolas,ui-monospace,monospace; font-size:12px; line-height:22px; color:var(--muted); contain:strict; }
.bucket-log-spacer { width:1px; opacity:0; pointer-events:none; }
.bucket-log-viewport { position:absolute; top:0; left:0; min-width:100%; }
.bucket-log-line { height:22px; white-space:pre; padding:0 4px; box-sizing:border-box; }
.bucket-log-line-empty { color:var(--muted); }
.bucket-log-line-checked,.bucket-log-line-ready,.bucket-log-line-uploaded,.bucket-log-line-queued { color:#a7f3d0; }
.bucket-log-line-duplicate,.bucket-log-line-skipped { color:#fde68a; }
.bucket-log-line-failed,.bucket-log-line-stopped { color:#fecdd3; }
.bucket-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.bucket-submit-row,.bucket-folder-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.bucket-folder-summary { color:var(--muted); }
.bucket-folder-fallback { margin:8px 0 12px; }
.bucket-folder-fallback summary { cursor:pointer; color:var(--muted); }
.bucket-stop { border-color:#ef4444; color:#fecaca; }
.bucket-phases { margin:0 0 16px; padding-left:22px; }
.bucket-phases li + li { margin-top:5px; }
.bucket-next-phase { margin-top:12px; padding:12px; border:1px solid rgba(96,165,250,.65); border-radius:10px; background:rgba(96,165,250,.08); }
.bucket-next-phase .button { margin-left:8px; }
@media (max-width:900px) { .bucket-stats { grid-template-columns:1fr; } .bucket-log-filter a { margin-left:0; } }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Upload Bucket',
        'One file is inspected, uploaded and queued at a time. Large Chrome folder selections use incremental directory discovery instead of constructing and copying one enormous FileList.',
        [
            'Upload Issues' => 'upload-issues.php',
            'System Errors' => 'system-errors.php',
            'Open Bucket Queue' => 'unverified-files.php?source_game_id=-1',
            'Processing Jobs' => $processingUrl,
            'Upload Files to Game' => 'profiled-upload.php',
        ]
    );

    echo '<div class="grid bucket-stats">';
    echo '<div class="stat"><h2>' . (int)$bucketStats['count'] . '</h2><p>Files in upload bucket</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$bucketStats['bytes'])) . '</h2><p>Bucket storage</p></div>';
    echo '<div class="stat bucket-path-card"><h2 class="mono small bucket-path">' . catalog_h($bucketDir) . '</h2><p>Physical bucket folder</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Upload unsorted files</h2>'
        . '<p>Folder discovery, file inspection and progress rendering yield back to Chrome so the page remains usable with very large folders.</p>'
        . '</div></div><div class="ui-section__body">';
    echo '<ol class="bucket-phases">'
        . '<li>Use the recommended folder button in Chrome to discover subfolders incrementally without creating a 70,000-file browser FileList.</li>'
        . '<li>Validate the extension and inspect only the active file in the reusable Web Worker.</li>'
        . '<li>Ask the API whether that physical file already exists, then upload it in resumable chunks only when needed.</li>'
        . '<li>Validate and queue that file before moving to the next file.</li>'
        . '<li>Keep one compact status line per file. The default view shows errors only; uncheck it to see the complete live status.</li>'
        . '<li>Persist only failed validation, transfer and finalisation results to Upload Issues so they remain reviewable after this page is closed.</li>'
        . '<li>The Stop button works during folder discovery, hashing, transfer and queue finalisation.</li>'
        . '</ol>';
    echo '<form id="upload-bucket-form" method="post" enctype="multipart/form-data" data-allowed-extensions="'
        . catalog_h($allowedExtensionJson) . '">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload-bucket')) . '">';
    echo '<p><label>Choose individual files<br><input id="upload-bucket-files" type="file" name="files[]" multiple></label></p>';
    echo '<div class="bucket-folder-row"><button id="upload-bucket-folder-button" class="secondary" type="button">Choose folder / subfolders</button>'
        . '<span id="upload-bucket-folder-summary" class="bucket-folder-summary">No folder selected</span></div>';
    echo '<details class="bucket-folder-fallback"><summary>Fallback folder selector for browsers without direct folder access</summary>'
        . '<p><input id="upload-bucket-folder" type="file" multiple webkitdirectory directory mozdirectory></p>'
        . '<p class="muted">Chrome users should use the button above. The fallback browser control may pause while Chrome constructs a very large FileList.</p></details>';
    echo '<p class="bucket-submit-row"><button id="upload-bucket-button" type="submit">Check and upload files</button>'
        . '<button id="upload-bucket-stop" class="secondary bucket-stop" type="button" hidden disabled>Stop</button></p>';
    echo '<p class="muted"><strong>Allowed by active game profiles:</strong> '
        . catalog_h($allowedExtensions ? implode(', ', $allowedExtensions) : 'none configured')
        . ', plus .uz/.uz2/.uz3 wrappers whose decompressed extension is allowed.</p>';
    echo '<p class="muted"><strong>Redirect archives:</strong> .uz accepts both historic 1234 and 5678 FCodec signatures. .uz/.uz2/.uz3 are transferred in their compressed wrapper form. Server processing then decompresses the wrapper, calculates the real package MD5/SHA-1, runs the duplicate check and stores the uncompressed package in the Upload Bucket.</p>';
    echo '<p class="muted"><strong>Status format:</strong> each file keeps one line, for example CHECKED : READY : UPLOADED : QUEUED : UPLOADED : path/file.uz : 513.62 KB. Only errors are written to <a href="upload-issues.php">Upload Issues</a>.</p>';
    echo '<p class="muted"><strong>Upload sizing:</strong> No UnrealDB total batch-size limit is applied. Only one file is active and it is split into chunks of up to '
        . catalog_h(catalog_bytes($chunkBytes))
        . '; the server may reduce the effective chunk size to fit PHP upload_max_filesize and post_max_size.</p>';

    $workerScriptPath = __DIR__ . '/assets/upload-file-inspector-worker-compatible.js';
    $workerScriptVersion = is_file($workerScriptPath) ? (string)(filemtime($workerScriptPath) ?: 1) : '1';
    $workerUrl = 'assets/upload-file-inspector-worker-compatible.js?v=' . rawurlencode($workerScriptVersion);

    echo '<div id="bucket-progress" class="bucket-progress" hidden'
        . ' data-chunk-url="api/v1/upload-bucket-chunk.php"'
        . ' data-batch-url="api/v1/upload-bucket-batch.php"'
        . ' data-issue-url="api/v1/upload-bucket-issue.php"'
        . ' data-inspector-worker-url="' . catalog_h($workerUrl) . '"'
        . ' data-processing-url="' . catalog_h($processingUrl) . '"'
        . ' data-chunk-csrf="' . catalog_h(catalog_csrf('upload_bucket_chunk')) . '"'
        . ' data-chunk-bytes="' . $chunkBytes . '">';
    echo '<div class="progress-row"><span id="bucket-overall-progress-label">Waiting to start</span><span id="bucket-overall-progress-count"></span></div>'
        . '<progress id="bucket-overall-progress-bar" value="0" max="100"></progress>';
    echo '<div class="progress-row"><span id="bucket-progress-label">Choose files or a folder and start the upload.</span><span id="bucket-progress-speed"></span></div>'
        . '<progress id="bucket-progress-bar" value="0" max="100"></progress>';
    echo '<p class="bucket-progress-note">The active file is the only file being read or uploaded. Progress paints are throttled to the browser display cycle and both status views are virtualised.</p>';
    echo '<div class="bucket-log-filter">'
        . '<label><input id="upload-bucket-errors-only" type="checkbox" checked> Show errors only</label>'
        . '<span id="upload-bucket-error-count" class="muted">0 errors</span>'
        . '<a href="upload-issues.php">Review saved errors</a>'
        . '</div>';
    echo '<div id="bucket-error-log" class="bucket-log" role="log" aria-label="Upload errors"></div>';
    echo '<div id="bucket-log" class="bucket-log" role="log" aria-label="All upload results"></div></div>';
    echo '</form>';
    echo '<p class="bucket-actions"><a class="button" href="upload-issues.php">Review upload issues</a>'
        . '<a class="button secondary" href="unverified-files.php?source_game_id=-1">Review bucket / assign files</a>'
        . '<a class="button secondary" href="' . catalog_h($processingUrl) . '">Processing jobs</a>'
        . '</p>';
    echo '</div></section>';

    $issueRecorderPath = __DIR__ . '/assets/upload-bucket-v2-issue-recorder.js';
    $issueRecorderVersion = is_file($issueRecorderPath) ? (string)(filemtime($issueRecorderPath) ?: 1) : '1';
    $coordinatorPath = __DIR__ . '/assets/upload-bucket-v2-coordinator.js';
    $coordinatorVersion = is_file($coordinatorPath) ? (string)(filemtime($coordinatorPath) ?: 1) : '1';
    echo '<script src="assets/upload-bucket-v2-issue-recorder.js?v=' . catalog_h($issueRecorderVersion) . '"></script>';
    echo '<script src="assets/upload-bucket-v2-coordinator.js?v=' . catalog_h($coordinatorVersion) . '"></script>';

    catalog_foot();
} catch (Throwable $error) {
    if (function_exists('catalog_system_error_record_exception')) {
        catalog_system_error_record_exception($error, 'upload_bucket_v2_page');
    }
    $message = upload_bucket_v2_short_error($error);
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] new upload bucket request failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Upload Bucket Error');
    }
    echo CatalogUi::alert('danger', $message . ' Reference: ' . $requestId, 'The new Upload Bucket could not be loaded.');
    catalog_foot();
}
