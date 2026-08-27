<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the Downloads administration dashboard.
 * Why: Cross-subsystem settings aggregation and mirror counters now belong to a dedicated Infrastructure read model.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoDownloadAdminSummaryQuery;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Downloads')) {
        exit;
    }

    catalog_head('Downloads');

    $summary = (new PdoDownloadAdminSummaryQuery($db, $config))->summary();
    $settings = $summary['settings'];
    $publicSettings = $summary['public'];
    $packageSettings = $summary['package'];
    $activeLinks = $summary['active_links'];
    $expiredLinks = $summary['expired_links'];
    $waitingJobs = $summary['waiting_jobs'];
    $failedJobs = $summary['failed_jobs'];
    $providers = $summary['providers'];

    catalog_page_header(
        'Downloads',
        'Monitor public downloads, generated packages, transfer limits and external mirrors. Federation parent/child transfers use controlled API transfers.',
        [
            'Download Settings' => 'downloads-settings.php',
            'Package Export Settings' => 'download-package-settings.php',
            'Mirror Providers' => 'mirror-providers.php',
            'Mirror Links' => 'mirror-links.php',
            'Mirror Queue' => 'mirror-queue.php',
            'Base Game Protection' => 'base-game-files.php',
            'Federation Settings' => 'federation/settings.php',
        ]
    );

    echo '<div class="grid">';
    catalog_stat_card('Public download mode', $settings['public_download_mode'] ?? 'local_direct');
    catalog_stat_card('Downloads per IP', (int)$publicSettings['public_download_max_files'], 'Per ' . catalog_public_access_window_label((int)$publicSettings['public_download_window_seconds']));
    catalog_stat_card('Generated packages per IP', (int)$publicSettings['public_package_max_builds'], 'Per ' . catalog_public_access_window_label((int)$publicSettings['public_package_window_seconds']));
    catalog_stat_card('Local speed limit', (int)$publicSettings['public_download_speed_kbps'] > 0 ? (int)$publicSettings['public_download_speed_kbps'] . ' KB/s' : 'unlimited');
    catalog_stat_card('Rapid-link block', (int)$publicSettings['public_burst_block_seconds'] . ' seconds', 'After more than ' . (int)$publicSettings['public_burst_max_requests'] . ' requests in ' . (int)$publicSettings['public_burst_window_seconds'] . ' seconds');
    catalog_stat_card('Crawler blocking', $publicSettings['public_block_crawlers'] ? 'enabled' : 'disabled', '', $publicSettings['public_block_crawlers'] ? 'good' : 'attention');
    catalog_stat_card('Package exports', $packageSettings['enabled'] ? 'enabled' : 'disabled', 'Native UMOD-family, UT3 ZIP, and UT4 PAK generation', $packageSettings['enabled'] ? 'good' : 'attention');
    catalog_stat_card('Package limit', (int)$packageSettings['max_files'] . ' files', catalog_bytes((int)$packageSettings['max_bytes']) . ' maximum payload');
    catalog_stat_card('Active mirror providers', $providers);
    catalog_stat_card('Active mirror links', $activeLinks, '', $activeLinks > 0 ? 'good' : '');
    catalog_stat_card('Expired mirror links', $expiredLinks);
    catalog_stat_card('Mirror jobs waiting', $waitingJobs, '', $waitingJobs > 0 ? 'attention' : '');
    catalog_stat_card('Failed mirror jobs', $failedJobs, '', $failedJobs > 0 ? 'warning' : '');
    catalog_stat_card('Link stale/expiry days', $settings['external_mirror_expiry_days'] ?? '7');
    echo '</div>';

    echo '<div class="card"><h2>Public restrictions shown to users</h2><p>Each IP may download ' . (int)$publicSettings['public_download_max_files'] . ' individual files per ' . catalog_h(catalog_public_access_window_label((int)$publicSettings['public_download_window_seconds'])) . ' and generate ' . (int)$publicSettings['public_package_max_builds'] . ' packages per ' . catalog_h(catalog_public_access_window_label((int)$publicSettings['public_package_window_seconds'])) . '.</p><p class="muted">Opening links too rapidly can block the IP for ' . (int)$publicSettings['public_burst_block_seconds'] . ' seconds. These controls are managed from Download Settings.</p></div>';

    echo '<div class="card"><h2>Generated package outputs</h2><table>';
    echo '<tr><th>UT99</th><td>.umod</td></tr>';
    echo '<tr><th>UT2003</th><td>.ut2mod</td></tr>';
    echo '<tr><th>UT2004</th><td>.ut4mod</td></tr>';
    echo '<tr><th>UT3 PC</th><td>Structured ZIP with UTGame paths</td></tr>';
    echo '<tr><th>UT4</th><td>Uncompressed, unencrypted version-3 PAK with source-relative Content paths</td></tr>';
    echo '<tr><th>Other games</th><td>Dependency ZIP</td></tr>';
    echo '</table><p class="muted">All generated packages use the dependency graph, exclude protected base-game files, and validate their output before download.</p></div>';

    echo '<div class="card"><h2>Public download modes</h2><table>';
    echo '<tr><th>local_direct</th><td>Users download directly from this site. Generated packages are available.</td></tr>';
    echo '<tr><th>external_mirror</th><td>Users only receive active external provider links. Generated packages are unavailable because they require the local payload.</td></tr>';
    echo '<tr><th>external_mirror_preferred</th><td>Use external links for individual files when available; generated packages still use local catalog files.</td></tr>';
    echo '<tr><th>disabled</th><td>Public downloads and generated packages are disabled. Admin and federation transfers still work.</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Download tools</h2><div class="grid">';
    catalog_tool_card('Download Settings', 'downloads-settings.php', 'Configure public mode, per-IP limits, speed controls, crawler/burst protection and mirror behavior.', 'primary');
    catalog_tool_card('Package export settings', 'download-package-settings.php', 'Enable formats, configure payload limits and UT4 mount paths, and override per-game defaults.');
    catalog_tool_card('Mirror providers', 'mirror-providers.php', 'Manage external/shared provider definitions, priorities and provider-specific limits.');
    catalog_tool_card('Mirror links', 'mirror-links.php', 'Review active/expired/broken external links and add manual hosted links.');
    catalog_tool_card('Mirror queue', 'mirror-queue.php', 'Fulfil queued mirror jobs by pasting external shared-provider URLs.', $waitingJobs > 0 ? (string)$waitingJobs : '');
    catalog_tool_card('Download logs', 'download-logs.php', 'Review individual downloads and generated package activity.');
    catalog_tool_card('Base game protection', 'base-game-files.php', 'Seed official/base game GUIDs and block those files from public downloads, transfers, mirrors, and generated packages.');
    catalog_tool_card('Maintenance', 'federation/maintenance.php', 'Expire stale mirror links and clean old jobs/logs.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Downloads error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
