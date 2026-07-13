<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Downloads')) {
        exit;
    }

    catalog_head('Downloads');

    $settings = fed_all_settings($db);
    $activeLinks = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_download_links WHERE status="active"');
    $expiredLinks = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_download_links WHERE status="expired"');
    $waitingJobs = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin","uploading")');
    $failedJobs = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status="failed"');
    $providers = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_download_providers WHERE is_active=1');

    catalog_page_header('Downloads', 'Control public/random user downloads. Federation parent/child transfers bypass this section and use controlled API transfers.', ['Mirror Settings' => 'mirror-providers.php', 'Mirror Links' => 'mirror-links.php', 'Mirror Queue' => 'mirror-queue.php', 'Base Game Protection' => 'base-game-files.php', 'Federation Settings' => 'federation/settings.php']);

    echo '<div class="grid">';
    catalog_stat_card('Public download mode', $settings['public_download_mode'] ?? 'local_direct');
    catalog_stat_card('Active mirror providers', $providers);
    catalog_stat_card('Active mirror links', $activeLinks, '', $activeLinks > 0 ? 'good' : '');
    catalog_stat_card('Expired mirror links', $expiredLinks);
    catalog_stat_card('Mirror jobs waiting', $waitingJobs, '', $waitingJobs > 0 ? 'attention' : '');
    catalog_stat_card('Failed mirror jobs', $failedJobs, '', $failedJobs > 0 ? 'warning' : '');
    catalog_stat_card('Link stale/expiry days', $settings['external_mirror_expiry_days'] ?? '7');
    echo '</div>';

    echo '<div class="card"><h2>Public download modes</h2><table>';
    echo '<tr><th>local_direct</th><td>Users download directly from this site. Default.</td></tr>';
    echo '<tr><th>external_mirror</th><td>Users only receive active external provider links. If missing, a mirror job is queued/pending.</td></tr>';
    echo '<tr><th>external_mirror_preferred</th><td>Use external links when available, otherwise fall back to local direct download and queue a mirror job.</td></tr>';
    echo '<tr><th>disabled</th><td>Public downloads disabled. Admin and federation transfers still work.</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Download tools</h2><div class="grid">';
    catalog_tool_card('Public download settings / providers', 'mirror-providers.php', 'Set site-wide public download mode and manage external/shared providers.', 'primary');
    catalog_tool_card('Mirror links', 'mirror-links.php', 'Review active/expired/broken external links and add manual hosted links.');
    catalog_tool_card('Mirror queue', 'mirror-queue.php', 'Fulfil queued mirror jobs by pasting external shared-provider URLs.', $waitingJobs > 0 ? (string)$waitingJobs : '');
    catalog_tool_card('Base game protection', 'base-game-files.php', 'Seed official/base game GUIDs and block those files from public downloads, federation transfers, mirrors, and bundles.');
    catalog_tool_card('Maintenance', 'federation/maintenance.php', 'Expire stale mirror links and clean old jobs/logs.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Downloads error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
