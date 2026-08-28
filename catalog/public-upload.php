<?php
/**
 * Public contribution upload page.
 *
 * Client-side inspection and batched identity preflight prevent unnecessary
 * network transfers. Accepted files enter a secondary quarantine first and are
 * promoted only to unverified staging by background workers.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadTransferStoreFactory;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogPublicUploadSettingsStore;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();

    $settings = (new CatalogPublicUploadSettingsStore($db, $config))->settings();
    $policy = new CatalogUploadBucketFilePolicy($db, $config);
    $allowedExtensions = $policy->allowedPackageExtensions();
    sort($allowedExtensions, SORT_NATURAL | SORT_FLAG_CASE);
    $chunkBytes = CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes($config);

    catalog_head('Contribute to UnrealDB');

    echo '<style>'
        . '.public-upload-picker{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}'
        . '.public-upload-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
        . '.public-upload-progress progress{width:100%;height:18px}'
        . '.public-upload-log{max-height:320px;overflow:auto;background:rgba(0,0,0,.18);border:1px solid var(--line);border-radius:6px;padding:8px;font:12px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}'
        . '.public-upload-log-line{overflow-wrap:anywhere;padding:2px 0}'
        . '.public-upload-log-uploaded,.public-upload-log-accepted{color:#a7f3d0}'
        . '.public-upload-log-rejected,.public-upload-log-failed{color:#fecdd3}'
        . '.public-upload-log-skipped{color:#bfdbfe}'
        . '</style>';

    catalog_page_header(
        'Contribute!',
        'Help expand the UnrealDB preservation catalog by contributing Unreal package files. Files are checked in your browser first, uploaded only when needed, and held as unverified until an administrator reviews them.',
        ['Browse Games' => 'games.php', 'Search' => 'index.php?page=search']
    );

    if (empty($settings['enabled'])) {
        echo CatalogUi::alert(
            'warning',
            'Public contribution uploads are currently disabled. Existing catalog browsing and downloads are unaffected.',
            'Uploads temporarily unavailable'
        );
        catalog_foot();
        exit;
    }

    echo '<section class="card"><h2>How contribution upload works</h2>'
        . '<p>Your browser checks extensions and Unreal package/redirect signatures before transfer. For normal package files it calculates MD5 and SHA-1 off the main browser thread; UE1/UE2 package GUIDs are also supplied when they can be read safely.</p>'
        . '<p>Up to <strong>100 checked files</strong> are sent to UnrealDB as one small identity manifest. The server performs batched indexed duplicate checks and returns only the files that still need uploading. Matching MD5 + SHA-1 + size is treated as an exact duplicate and skipped. A matching GUID with different hashes is <strong>not</strong> discarded; it is uploaded and flagged for review.</p>'
        . '<p>Accepted files are uploaded <strong>one at a time</strong> into a separate public quarantine. The upload request only transfers bytes. Authoritative hashing, redirect decompression, package parsing and unverified indexing happen afterward in background jobs.</p>'
        . '<p class="muted">Public upload currently accepts normal Unreal package files plus .uz, .uz2 and .uz3 redirects. ZIP, 7z, RAR, UMOD-family archives and PAK containers are intentionally excluded from the anonymous upload surface.</p>'
        . '</section>';

    echo '<section class="card"><h2>Select files</h2>'
        . '<form id="public-upload-form" data-allowed-extensions="'
        . catalog_h(json_encode($allowedExtensions, JSON_UNESCAPED_SLASHES) ?: '[]') . '">'
        . '<div class="public-upload-picker">'
        . '<div><label for="public-upload-files"><strong>Files</strong></label><br>'
        . '<input id="public-upload-files" type="file" multiple></div>'
        . '<div><strong>Folder</strong><br>'
        . '<button id="public-upload-folder-button" class="secondary" type="button">Choose folder</button> '
        . '<span id="public-upload-folder-summary" class="muted">No folder selected</span><br>'
        . '<small class="muted">Modern browsers scan the selected folder lazily in 100-file batches when upload starts.</small></div>'
        . '</div>'
        . '<details><summary>Fallback folder selector</summary>'
        . '<p><input id="public-upload-folder" type="file" multiple webkitdirectory directory></p>'
        . '<p class="muted">Use this only if the Choose folder button is not supported by your browser.</p></details>'
        . '<p><strong>Accepted package extensions:</strong> '
        . catalog_h($allowedExtensions !== [] ? implode(', ', array_map(static fn(string $ext): string => '.' . $ext, $allowedExtensions)) : 'active game-profile package types')
        . ', .uz, .uz2, .uz3.</p>'
        . '<p><strong>Maximum file size:</strong> ' . catalog_h(catalog_bytes((int)$settings['max_file_bytes'])) . '.</p>'
        . '<div class="public-upload-actions">'
        . '<button id="public-upload-start" type="submit">Check and contribute files</button>'
        . '<button id="public-upload-stop" class="secondary" type="button" hidden disabled>Stop</button>'
        . '</div>'
        . '</form></section>';

    $workerPath = __DIR__ . '/assets/upload-file-inspector-worker-compatible.js';
    $workerDelegate = __DIR__ . '/assets/upload-file-inspector-worker.js';
    $workerVersion = max(
        is_file($workerPath) ? (int)(filemtime($workerPath) ?: 1) : 1,
        is_file($workerDelegate) ? (int)(filemtime($workerDelegate) ?: 1) : 1
    );
    echo '<section id="public-upload-progress" class="card public-upload-progress" hidden'
        . ' data-preflight-url="api/v1/public-upload-preflight.php"'
        . ' data-upload-url="api/v1/public-upload.php"'
        . ' data-worker-url="assets/upload-file-inspector-worker-compatible.js?v=' . catalog_h((string)$workerVersion) . '"'
        . ' data-csrf="' . catalog_h(catalog_csrf('public_upload')) . '"'
        . ' data-chunk-bytes="' . (int)$chunkBytes . '"'
        . ' data-max-file-bytes="' . (int)$settings['max_file_bytes'] . '">'
        . '<h2>Contribution progress</h2>'
        . '<p id="public-upload-progress-label">Waiting to start.</p>'
        . '<progress id="public-upload-progress-bar" value="0" max="100"></progress>'
        . '<p id="public-upload-summary" class="muted">0 checked · 0 accepted · 0 already held/pending · 0 rejected · 0 uploaded · 0 failed</p>'
        . '<div id="public-upload-log" class="public-upload-log" role="log" aria-label="Public upload results"></div>'
        . '</section>';

    echo '<section class="card"><h2>After upload</h2>'
        . '<p>Completing the browser transfer does not place a file directly into the verified catalog. Background workers verify the received bytes, decompress supported redirects, calculate authoritative hashes and package identity, then place valid packages into unverified staging.</p>'
        . '<p>Expensive game/dependency compatibility matching is queued separately so uploads remain fast. Administrators can then review the unverified file and decide whether and where it should be imported into the main catalog.</p>'
        . '<p class="muted">Your IP address and basic browser user-agent are recorded with contribution reservations for abuse control and operational troubleshooting. Public contributors cannot assign games, approve files, or promote files into the verified catalog.</p>'
        . '</section>';

    $script = __DIR__ . '/assets/public-upload.js';
    $version = is_file($script) ? (string)(filemtime($script) ?: 1) : '1';
    echo '<script src="assets/public-upload.js?v=' . catalog_h($version) . '"></script>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] public upload page failed: '
        . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Contribute to UnrealDB');
    }
    echo CatalogUi::alert(
        'danger',
        'Public contribution upload is temporarily unavailable. Reference: ' . catalog_request_id(),
        'Contribution upload unavailable'
    );
    catalog_foot();
}
