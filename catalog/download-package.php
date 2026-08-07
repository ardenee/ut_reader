<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Generate package for.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/ModPackageBuilder.php';

try {
    catalog_start_session();
    $config = catalog_config();
    $db = catalog_db($config);
    $settings = modpkg_settings($db);
    $mode = external_public_download_mode($db);
    if ($mode === 'disabled') {
        throw new RuntimeException('Public downloads are disabled.');
    }
    if ($mode === 'external_mirror') {
        throw new RuntimeException('Generated packages are unavailable in external-mirror-only mode.');
    }
    if (!$settings['enabled']) {
        throw new RuntimeException('Package exports are disabled.');
    }

    $id = max(0, (int)($_GET['id'] ?? 0));
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found.');
    }
    $game = modpkg_game_row($db, (int)$file['game_id']);
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    $available = modpkg_available_formats($game, $settings);
    $format = strtolower(trim((string)($_GET['format'] ?? modpkg_default_format($game, $settings))));
    if (!in_array($format, $available, true)) {
        throw new RuntimeException('The selected package format is not available for this game.');
    }

    $includeDependencies = !isset($_GET['dependencies']) || (string)$_GET['dependencies'] !== '0';
    $allowIncomplete = $settings['allow_incomplete'] && (string)($_GET['allow_incomplete'] ?? '0') === '1';
    $name = substr(trim((string)($_GET['name'] ?? catalog_clean_unreal_package_stem((string)$file['package_name']))), 0, 160);
    $version = substr(trim((string)($_GET['version'] ?? '1.0')), 0, 80);
    $author = substr(trim((string)($_GET['author'] ?? $settings['default_author'])), 0, 160);
    $resumeJobId = max(0, (int)($_GET['job_id'] ?? 0));

    catalog_head('Generate package');
    catalog_page_header(
        'Generate package for ' . catalog_clean_unreal_filename((string)$file['original_name']),
        (string)$game['name'] . ' · ' . (modpkg_format_labels()[$format] ?? $format),
        ['Download options' => 'download-info.php?id=' . $id, 'File information' => 'file-info.php?id=' . $id]
    );

    echo <<<'CSS'
<style>
.package-job-card { max-width:820px; }
.package-job-progress { height:16px; overflow:hidden; border:1px solid var(--line2); border-radius:999px; background:rgba(255,255,255,.05); }
.package-job-progress > span { display:block; width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,#76a9ff,#9dc2ff); transition:width .2s linear; }
.package-job-summary { margin-top:12px; white-space:pre-wrap; color:var(--muted); }
.package-job-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }
</style>
CSS;

    echo '<div class="card package-job-card">';
    echo '<h2 id="package-job-title">Preparing package job</h2>';
    echo '<p id="package-job-message">The package will be built by the background worker. You may leave this page and return using the job URL.</p>';
    echo '<div class="package-job-progress"><span id="package-job-bar"></span></div>';
    echo '<div id="package-job-status" class="package-job-summary">Waiting to queue…</div>';
    echo '<div id="package-job-summary" class="package-job-summary"></div>';
    echo '<div class="package-job-actions">'
        . '<button type="button" id="package-job-cancel" class="secondary">Cancel</button>'
        . '<a id="package-job-download" class="button primary" hidden>Download generated package</a>'
        . '<a class="button secondary" href="download-info.php?id=' . $id . '">Back to download options</a>'
        . '</div>';
    echo '</div>';

    echo '<form id="package-job-form" hidden'
        . ' data-endpoint="generated-package-job.php"'
        . ' data-download-endpoint="generated-package-download.php"'
        . ' data-resume-job-id="' . $resumeJobId . '">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('package-generation')) . '">';
    echo '<input type="hidden" name="file_id" value="' . $id . '">';
    echo '<input type="hidden" name="format" value="' . catalog_h($format) . '">';
    echo '<input type="hidden" name="dependencies" value="' . ($includeDependencies ? '1' : '0') . '">';
    echo '<input type="hidden" name="allow_incomplete" value="' . ($allowIncomplete ? '1' : '0') . '">';
    echo '<input type="hidden" name="name" value="' . catalog_h($name) . '">';
    echo '<input type="hidden" name="version" value="' . catalog_h($version) . '">';
    echo '<input type="hidden" name="author" value="' . catalog_h($author) . '">';
    echo '</form>';
    echo '<script src="assets/generated-package-jobs.js"></script>';
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Package generation error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'Package generation unavailable');
    echo '<p><a class="button" href="javascript:history.back()">Back</a></p>';
    catalog_foot();
}
