<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the established Upload Bucket page for resumable staged uploads and background processing.
 * Why: It remains available while the newer upload-bucket interface is developed and compared against existing
 *      workflows.
 * Role: Active legacy-labelled upload UI linked from navigation, unverified files, background jobs, and contract
 *       tests.
 * Audit: Parallel implementation with `upload-bucket-v2.php`; substantial overlap exists, so converge shared logic
 *        before retiring either route.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function upload_bucket_short_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
    $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
    return trim($message) !== '' ? trim($message) : 'Unknown error';
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

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Upload Bucket')) {
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        throw new RuntimeException(
            'Upload Bucket requires the resumable JavaScript uploader. Reload the page with JavaScript enabled; whole-file fallback processing has been disabled so uploading and package processing cannot run at the same time.'
        );
    }

    $bucketDir = uvf_upload_bucket_dir($config, true);
    $bucketStats = upload_bucket_stats($bucketDir);
    $allowedExtensions = upload_bucket_allowed_extensions($db, $config);
    $allowedExtensionJson = json_encode(
        array_values($allowedExtensions),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $chunkBytes = upload_bucket_chunk_bytes($config);
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
.bucket-log { max-height:420px; overflow:auto; margin-top:10px; font-family:Consolas,ui-monospace,monospace; font-size:12px; color:var(--muted); }
.bucket-result { display:flex; gap:8px; align-items:baseline; padding:3px 0; white-space:nowrap; }
.bucket-result-badge { min-width:98px; font-weight:700; text-transform:uppercase; }
.bucket-result-file { color:var(--text); }
.bucket-result-message { color:var(--muted); white-space:normal; }
.bucket-result-uploaded .bucket-result-badge,.bucket-result-ready .bucket-result-badge,.bucket-result-queued .bucket-result-badge { color:#a7f3d0; }
.bucket-result-duplicate .bucket-result-badge,.bucket-result-waiting .bucket-result-badge,.bucket-result-skipped .bucket-result-badge { color:#fde68a; }
.bucket-result-retrying .bucket-result-badge { color:#fdba74; }
.bucket-result-failed .bucket-result-badge { color:#fecdd3; }
.bucket-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.bucket-phases { margin:0 0 16px; padding-left:22px; }
.bucket-phases li + li { margin-top:5px; }
.bucket-next-phase { margin-top:12px; padding:12px; border:1px solid rgba(96,165,250,.65); border-radius:10px; background:rgba(96,165,250,.08); }
.bucket-next-phase .button { margin-left:8px; }
@media (max-width:900px) { .bucket-stats { grid-template-columns:1fr; } }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Upload Bucket',
        'Each file is transferred completely into durable staging, then validated and queued individually. No batch-wide start or finalisation request has to finish before the page can continue.',
        [
            'Open Bucket Queue' => 'unverified-files.php?source_game_id=-1',
            'Processing Jobs' => $processingUrl,
            'All Queues' => 'unverified-files.php',
            'Upload Files to Game' => 'profiled-upload.php',
        ]
    );

    echo '<div class="grid bucket-stats">';
    echo '<div class="stat"><h2>' . (int)$bucketStats['count'] . '</h2><p>Files in upload bucket</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$bucketStats['bytes'])) . '</h2><p>Bucket storage</p></div>';
    echo '<div class="stat bucket-path-card"><h2 class="mono small bucket-path">' . catalog_h($bucketDir) . '</h2><p>Physical bucket folder</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Upload unsorted files</h2>'
        . '<p>Progress is based on actual file states and server results, not elapsed-time estimates.</p>'
        . '</div></div><div class="ui-section__body">';
    echo '<ol class="bucket-phases">'
        . '<li>Request the existing Upload Bucket worker to stop cooperatively, but begin transferring selected files immediately.</li>'
        . '<li>Filter extensions, calculate MD5/SHA-1 for ordinary files, check physical duplicates and write resumable chunks to durable staging.</li>'
        . '<li>After transfers complete, wait only if an identified previous processing job is still finishing its current file.</li>'
        . '<li>Validate and queue each completed staged file separately. One file failure cannot prevent later files from being handled.</li>'
        . '<li>Files whose final queue request fails remain complete in durable staging and can be retried without retransferring stored chunks.</li>'
        . '<li>Start the processing worker after all staged files have received an individual result.</li>'
        . '</ol>';
    echo '<form id="upload-bucket-form" method="post" enctype="multipart/form-data" data-allowed-extensions="'
        . catalog_h($allowedExtensionJson) . '">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload-bucket')) . '">';
    echo '<p><label>Choose files<br><input id="upload-bucket-files" type="file" name="files[]" multiple></label></p>';
    echo '<p><label>Choose folder / subfolders<br><input id="upload-bucket-folder" type="file" multiple webkitdirectory directory mozdirectory></label></p>';
    echo '<p><button id="upload-bucket-button" type="submit">Check and upload files</button></p>';
    echo '<p class="muted"><strong>Allowed by active game profiles:</strong> '
        . catalog_h($allowedExtensions ? implode(', ', $allowedExtensions) : 'none configured')
        . ', plus .uz/.uz2/.uz3 wrappers whose decompressed extension is allowed.</p>';
    echo '<p class="muted"><strong>Duplicate rule:</strong> uncompressed files are checked before transfer. Redirect wrappers are checked only after decompression. A database identity is rejected only when its physical file is confirmed; official base-game metadata without a stored source remains uploadable.</p>';
    echo '<p class="muted"><strong>Upload sizing:</strong> No UnrealDB total batch-size limit is applied. Files are split into chunks of up to '
        . catalog_h(catalog_bytes($chunkBytes))
        . '; the server may reduce the effective chunk size to fit PHP upload_max_filesize and post_max_size.</p>';
    echo '<div id="bucket-progress" class="bucket-progress" hidden'
        . ' data-chunk-url="api/v1/upload-bucket-chunk.php"'
        . ' data-batch-url="api/v1/upload-bucket-batch.php"'
        . ' data-processing-url="' . catalog_h($processingUrl) . '"'
        . ' data-chunk-csrf="' . catalog_h(catalog_csrf('upload_bucket_chunk')) . '"'
        . ' data-chunk-bytes="' . $chunkBytes . '">';
    echo '<div class="progress-row"><span id="bucket-overall-progress-label">Waiting to start</span><span id="bucket-overall-progress-count"></span></div>'
        . '<progress id="bucket-overall-progress-bar" value="0" max="100"></progress>';
    echo '<div class="progress-row"><span id="bucket-progress-label">Choose files and start the upload.</span><span id="bucket-progress-speed"></span></div>'
        . '<progress id="bucket-progress-bar" value="0" max="100"></progress>';
    echo '<p class="bucket-progress-note">Do not close or reload this page while files are being transferred or queued. Completed chunks remain durable and are reused when the same files are selected again.</p>';
    echo '<div id="bucket-log" class="bucket-log"></div></div>';
    echo '</form>';
    echo '<p class="bucket-actions"><a class="button" href="unverified-files.php?source_game_id=-1">Review bucket / assign files</a>'
        . '<a class="button secondary" href="' . catalog_h($processingUrl) . '">Processing jobs</a>'
        . '<a class="button secondary" href="unverified-files.php">Review all queues</a></p>';
    echo '</div></section>';

    $filterScriptPath = __DIR__ . '/assets/upload-bucket-extension-filter.js';
    $filterScriptVersion = is_file($filterScriptPath) ? (string)(filemtime($filterScriptPath) ?: 1) : '1';
    $hashScriptPath = __DIR__ . '/assets/upload-file-hash.js';
    $hashScriptVersion = is_file($hashScriptPath) ? (string)(filemtime($hashScriptPath) ?: 1) : '1';
    $coordinatorPath = __DIR__ . '/assets/upload-bucket-coordinator.js';
    $coordinatorVersion = is_file($coordinatorPath) ? (string)(filemtime($coordinatorPath) ?: 1) : '1';
    echo '<script src="assets/upload-bucket-extension-filter.js?v=' . catalog_h($filterScriptVersion) . '"></script>';
    echo '<script src="assets/upload-file-hash.js?v=' . catalog_h($hashScriptVersion) . '"></script>';
    echo '<script src="assets/upload-bucket-coordinator.js?v=' . catalog_h($coordinatorVersion) . '"></script>';

    catalog_foot();
} catch (Throwable $error) {
    $message = upload_bucket_short_error($error);
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] upload bucket request failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Upload Bucket Error');
    }
    echo CatalogUi::alert('danger', $message . ' Reference: ' . $requestId, 'The upload bucket could not be loaded.');
    catalog_foot();
}
