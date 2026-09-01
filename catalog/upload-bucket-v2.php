<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the current Upload Bucket v2 workflow for large browser file/folder ingestion.
 * Why: Upload Bucket needs a durable, one-file-at-a-time transport UI without tying background processing to the browser.
 * Role: Thin presentation entry point; upload validation, chunking and queue orchestration live in shared services/APIs.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogUi.php';
require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadTransferStoreFactory;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogProgramSettingsStore;
use UnrealDb\Catalog\Presentation\CatalogUi;

function upload_bucket_v2_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    if ($message === '') {
        return get_class($error);
    }
    return function_exists('mb_substr') ? mb_substr($message, 0, 800, 'UTF-8') : substr($message, 0, 800);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        if (!catalog_require_admin_page('Upload Bucket')) {
            exit;
        }
    }

    $config = (new CatalogProgramSettingsStore($db, $config))->applyUploadLimits($config);
    $policy = new CatalogUploadBucketFilePolicy($db, $config);
    $allowedExtensions = $policy->allowedExtensions();
    sort($allowedExtensions, SORT_NATURAL | SORT_FLAG_CASE);

    // Keep the browser-advertised chunk size identical to the server transfer
    // store, including PHP upload_max_filesize/post_max_size safety headroom.
    $chunkBytes = CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes($config);
    $processingUrl = 'background-jobs.php?queue=' . rawurlencode(
        (trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog') . ':bucket-processing'
    );

    catalog_head('Upload Bucket');
    echo '<section class="hero"><p class="eyebrow">Administration</p><h1>Upload Bucket</h1>'
        . '<p class="muted">Upload large folders one file at a time. The browser performs lightweight validation and duplicate preflight, then transfers each required file into durable server staging. Package/archive processing starts only after completed transfers are handed to the background queue.</p></section>';

    echo '<section class="card"><div class="card-body">';
    echo '<form id="upload-bucket-form" data-allowed-extensions="'
        . catalog_h(json_encode($allowedExtensions, JSON_UNESCAPED_SLASHES) ?: '[]') . '">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload_bucket_chunk')) . '">';
    echo '<div class="bucket-picker-grid">';
    echo '<div><label for="upload-bucket-files"><strong>Files</strong></label><br>'
        . '<input id="upload-bucket-files" type="file" multiple></div>';
    echo '<div><strong>Folder</strong><br>'
        . '<button id="upload-bucket-folder-button" class="secondary" type="button">Choose folder</button> '
        . '<span id="upload-bucket-folder-summary" class="muted">No folder selected</span></div>';
    echo '</div>';
    echo '<details class="bucket-folder-fallback"><summary>Fallback folder selector for browsers without direct folder access</summary>'
        . '<p><input id="upload-bucket-folder" type="file" multiple webkitdirectory directory mozdirectory></p>'
        . '<p class="muted">Chrome users should use the button above. The fallback browser control may pause while Chrome constructs a very large FileList.</p></details>';
    echo '<p class="bucket-submit-row"><button id="upload-bucket-button" type="submit">Check and upload files</button>'
        . '<button id="upload-bucket-stop" class="secondary bucket-stop" type="button" hidden disabled>Stop</button></p>';
    echo '<p class="muted"><strong>Allowed by active game profiles:</strong> '
        . catalog_h($allowedExtensions ? implode(', ', $allowedExtensions) : 'none configured')
        . ', plus .uz/.uz2/.uz3 wrappers whose decompressed extension is allowed.</p>';
    echo '<p class="muted"><strong>Redirect archives:</strong> .uz accepts both historic 1234 and 5678 FCodec signatures. .uz/.uz2/.uz3 are transferred in their compressed wrapper form. Server processing then decompresses the wrapper, calculates the real package MD5/SHA-1, runs the duplicate check and stores the uncompressed package in the Upload Bucket.</p>';
    echo '<p class="muted"><strong>ZIP / 7z / RAR / UMOD / UT2MOD / UT4MOD:</strong> these are unpack-only transport/install containers. They are not catalogued as Unreal packages. UMOD-family files are parsed natively from their Unreal Setup footer and directory table; ZIP/7z/RAR use the configured PHP archive extensions. Supported Unreal members are extracted one at a time into durable staging and each follows the normal package/redirect processing path. Nested archives and password-protected members are not recursively processed.</p>';
    echo '<p class="muted"><strong>Recovery boundary:</strong> chunking is transport only. If the browser/session disappears before a file reaches the server in complete form, that upload is incomplete and must be started again. Once complete staging succeeds, all processing after that point is background/recoverable.</p>';
    echo '<p class="muted"><strong>Status format:</strong> each file keeps one line, for example CHECKED : READY : UPLOADED : QUEUED : UPLOADED : path/file.uz : 513.62 KB. Only errors are written to <a href="upload-issues.php">Upload Issues</a>.</p>';
    echo '<p class="muted"><strong>Upload sizing:</strong> No UnrealDB total batch-size limit is applied. Only one file is active and it is split into chunks of up to '
        . catalog_h(catalog_bytes($chunkBytes))
        . '; the server may reduce the effective chunk size to fit PHP upload_max_filesize and post_max_size.</p>';

    $workerScriptPath = __DIR__ . '/assets/upload-file-inspector-worker-compatible.js';
    $delegatedInspectorPath = __DIR__ . '/assets/upload-file-inspector-worker.js';
    $redirectReaderPath = __DIR__ . '/assets/unreal-redirect-reader.js';
    // Imported worker dependencies share this query string. Version every
    // browser reader so Chrome cannot retain a stale parser or hash worker.
    $workerScriptVersion = max(
        is_file($workerScriptPath) ? (int)(filemtime($workerScriptPath) ?: 1) : 1,
        is_file($delegatedInspectorPath) ? (int)(filemtime($delegatedInspectorPath) ?: 1) : 1,
        is_file($redirectReaderPath) ? (int)(filemtime($redirectReaderPath) ?: 1) : 1
    );
    $workerUrl = 'assets/upload-file-inspector-worker-compatible.js?v=' . rawurlencode((string)$workerScriptVersion);

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
