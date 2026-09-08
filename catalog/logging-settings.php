<?php
/**
 * Administrator controls for first-party site activity logging.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogAccessLoggingSettings;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Logging Settings')) {
        exit;
    }

    $service = new CatalogAccessLoggingSettings($db);
    $currentIp = catalog_public_access_client_ip();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('logging_settings');
        $input = $_POST;
        if (isset($_POST['add_current_ip'])) {
            $existing = trim((string)($input['ignored_ips'] ?? ''));
            $input['ignored_ips'] = trim($existing . "\n" . $currentIp);
        }
        $saved = $service->save($input);
        $_SESSION['logging_settings_flash'] = 'Logging settings saved. '
            . count($saved['ignored_ips']) . ' IP address(es) are excluded from Access Matrix ingestion.';
        header('Location: logging-settings.php', true, 303);
        exit;
    }

    $settings = $service->current();

    catalog_head('Logging Settings');
    catalog_page_header(
        'Logging Settings',
        'Control first-party site activity telemetry used by Access Matrix. Exclusions are applied before rows are written, so ignored activity does not affect counts.',
        [
            'Site Activity Logs' => 'access-matrix.php',
            'Download Logs' => 'download-logs.php',
            'Site Blacklist' => 'site-blacklist.php',
        ]
    );

    if (isset($_SESSION['logging_settings_flash'])) {
        catalog_flash((string)$_SESSION['logging_settings_flash']);
        unset($_SESSION['logging_settings_flash']);
    }

    echo '<form method="post">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('logging_settings')) . '">';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Site activity logging</h2>'
        . '<p>These controls affect Access Matrix page views, server renders, sections and interactions.</p></div></div>'
        . '<div class="ui-section__body"><table>';
    echo '<tr><th>Logging</th><td><label><input type="checkbox" name="logging_enabled" value="1"'
        . ($settings['enabled'] ? ' checked' : '') . '> Record site activity events</label></td></tr>';
    echo '<tr><th>Administrator sessions</th><td><label><input type="checkbox" name="ignore_admin_sessions" value="1"'
        . ($settings['ignore_admin_sessions'] ? ' checked' : '')
        . '> Ignore activity while logged in as an administrator</label>'
        . '<br><span class="muted small">Useful during development because admin browsing will not change public activity counts.</span></td></tr>';
    echo '<tr><th>Current IP</th><td><span class="mono">' . catalog_h($currentIp) . '</span>'
        . '<br><span class="muted small">Use the button below to add this address to the explicit ignore list.</span></td></tr>';
    echo '<tr><th>Ignored IP addresses</th><td><textarea name="ignored_ips" rows="10" style="min-width:520px" '
        . 'placeholder="One IPv4 or IPv6 address per line">'
        . catalog_h(implode("\n", $settings['ignored_ips'])) . '</textarea>'
        . '<br><span class="muted small">Exact IP matches only. Up to 200 addresses. These events are discarded before insertion.</span></td></tr>';
    echo '</table></div></section>';

    echo '<p><button class="primary" type="submit">Save logging settings</button> '
        . '<button class="secondary" type="submit" name="add_current_ip" value="1">Add current IP + save</button></p>';
    echo '</form>';

    echo CatalogUi::alert(
        'info',
        'These settings affect new Access Matrix telemetry only. Existing historical events remain unchanged and can be deleted from Site Activity Logs if required.',
        'Historical data'
    );

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] logging settings failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Logging Settings error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Logging settings could not be saved');
    catalog_foot();
}
