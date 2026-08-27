<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes centralized public download settings.
 * Why: Download limits, speed, abuse protection and mirror behavior should be administered from one page.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogDownloadSettingsService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Download Settings')) {
        exit;
    }

    $service = new CatalogDownloadSettingsService($db, $config);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('download_settings');
        $_SESSION['download_settings_flash'] = $service->save($_POST);
        header('Location: downloads-settings.php', true, 303);
        exit;
    }

    $current = $service->current();
    $public = $current['public'];
    $mirror = $current['mirror'];

    catalog_head('Download Settings');
    catalog_page_header(
        'Download Settings',
        'Control public download mode, per-IP limits, transfer speed, automated-access protection and mirror behavior.',
        [
            'Downloads' => 'download-admin.php',
            'Download Logs' => 'download-logs.php',
            'Package Export Settings' => 'download-package-settings.php',
            'Mirror Providers' => 'mirror-providers.php',
        ]
    );

    if (isset($_SESSION['download_settings_flash'])) {
        catalog_flash((string)$_SESSION['download_settings_flash']);
        unset($_SESSION['download_settings_flash']);
    }

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('download_settings')) . '">';

    echo '<div class="card"><h2>Public download mode</h2><table>';
    echo '<tr><th>Mode</th><td><select name="public_download_mode">';
    foreach ([
        'local_direct' => 'Use own site / direct download',
        'external_mirror' => 'External mirror only',
        'external_mirror_preferred' => 'Prefer external mirror, fallback to own site',
        'disabled' => 'Disable public downloads',
    ] as $key => $label) {
        $selected = (($mirror['public_download_mode'] ?? 'local_direct') === $key) ? ' selected' : '';
        echo '<option value="' . catalog_h($key) . '"' . $selected . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '</table><p class="muted small">Federation parent/child transfers are controlled separately and are not limited by this public download mode.</p></div>';

    echo '<div class="card"><h2>Download limits and speed</h2><table>';
    echo '<tr><th>Individual file downloads</th><td><input type="number" min="1" max="10000" name="public_download_max_files" value="' . (int)$public['public_download_max_files'] . '"> per <input type="number" min="60" max="604800" name="public_download_window_seconds" value="' . (int)$public['public_download_window_seconds'] . '"> seconds, per IP</td></tr>';
    echo '<tr><th>Generated package builds</th><td><input type="number" min="1" max="10000" name="public_package_max_builds" value="' . (int)$public['public_package_max_builds'] . '"> per <input type="number" min="60" max="604800" name="public_package_window_seconds" value="' . (int)$public['public_package_window_seconds'] . '"> seconds, per IP</td></tr>';
    echo '<tr><th>Local download speed</th><td><input type="number" min="0" max="1048576" name="public_download_speed_kbps" value="' . (int)$public['public_download_speed_kbps'] . '"> KB/s per transfer <span class="muted small">0 means unlimited. Applies to individual files, generated packages and original PAK downloads. External mirror speed cannot be controlled here.</span></td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Automated access and rapid-link protection</h2><table>';
    echo '<tr><th>Known crawlers</th><td><label><input type="checkbox" name="public_block_crawlers" value="1"' . ($public['public_block_crawlers'] ? ' checked' : '') . '> block known crawler, spider, scripted downloader and headless-browser user agents</label></td></tr>';
    echo '<tr><th>Rapid requests</th><td>More than <input type="number" min="2" max="10000" name="public_burst_max_requests" value="' . (int)$public['public_burst_max_requests'] . '"> public page/link requests in <input type="number" min="1" max="3600" name="public_burst_window_seconds" value="' . (int)$public['public_burst_window_seconds'] . '"> seconds blocks the IP.</td></tr>';
    echo '<tr><th>Temporary block</th><td><input type="number" min="10" max="86400" name="public_burst_block_seconds" value="' . (int)$public['public_burst_block_seconds'] . '"> seconds</td></tr>';
    echo '</table><p class="muted small">Logged-in administrators are exempt from the public action limits and burst protection.</p></div>';

    echo '<div class="card"><h2>External mirror behavior</h2><table>';
    echo '<tr><th>Auto queue missing mirror</th><td><label><input type="checkbox" name="external_mirror_auto_queue" value="1"' . ((string)($mirror['external_mirror_auto_queue'] ?? '1') === '1' ? ' checked' : '') . '> create a mirror job when a required external link is missing</label></td></tr>';
    echo '<tr><th>Default link expiry</th><td><input type="number" min="1" max="3650" name="external_mirror_expiry_days" value="' . (int)($mirror['external_mirror_expiry_days'] ?? 7) . '"> days</td></tr>';
    echo '<tr><th>Require approval</th><td><label><input type="checkbox" name="external_mirror_require_admin_approval" value="1"' . ((string)($mirror['external_mirror_require_admin_approval'] ?? '0') === '1' ? ' checked' : '') . '> require administrator approval before a mirror job is queued</label></td></tr>';
    echo '<tr><th>Maximum mirror file size</th><td><input type="number" min="1" max="1048576" name="external_mirror_max_file_size_mb" value="' . (int)($mirror['external_mirror_max_file_size_mb'] ?? 1024) . '"> MB</td></tr>';
    echo '</table><p class="muted small">Provider definitions, priorities and provider-specific limits remain under Mirror Providers.</p></div>';

    echo '<p><button class="primary" type="submit">Save download settings</button></p></form>';

    echo '<div class="card"><h2>Advanced download administration</h2><div class="grid">';
    catalog_tool_card('Package export settings', 'download-package-settings.php', 'Enable download formats, configure generated package payload limits and per-game defaults.');
    catalog_tool_card('Mirror providers', 'mirror-providers.php', 'Manage external/shared providers, priorities and provider-specific limits.');
    catalog_tool_card('Download logs', 'download-logs.php', 'Review individual downloads and generated package activity.');
    catalog_tool_card('Base game protection', 'base-game-files.php', 'Manage packages that must not be redistributed.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] download settings failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Download Settings error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Download settings could not be saved');
    catalog_foot();
}
