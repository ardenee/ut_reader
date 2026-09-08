#!/usr/bin/env php
<?php
/** Read-only contract for access-matrix telemetry and full-site IP blocking. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$migration = $read('migrations/202609080002_access_matrix_site_blocklist.php');
$recorder = $read('src/Infrastructure/Telemetry/CatalogAccessEventRecorder.php');
$siteBlock = $read('src/Infrastructure/Security/CatalogSiteBlocklist.php');
$guard = $read('src/Infrastructure/Security/CatalogPublicAccessGuard.php');
$support = $read('lib/CatalogSupportCore.php');
$transform = $read('src/Presentation/Http/CatalogPageResponseTransform.php');
$client = $read('assets/catalog-access-matrix.js');
$endpoint = $read('access-event.php');
$admin = $read('access-matrix.php');
$blockedPage = $read('blacklisted.php');
$siteBlacklist = $read('site-blacklist.php');
$downloadLogs = $read('download-logs.php');
$navigation = $read('lib/CatalogNavigation.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$record(
    'schema_has_events_site_blocks_and_feedback',
    str_contains($migration, "'ue_access_events'")
        && str_contains($migration, "'ue_site_blocked_ips'")
        && str_contains($migration, "'ue_site_block_feedback'")
        && str_contains($migration, 'idx_ue_access_events_page')
        && str_contains($migration, 'idx_ue_access_events_ip'),
    'Migration must create indexed raw access events, full-site IP blocks and removal-request storage.'
);

$record(
    'browser_confirmation_collapses_dynamic_page_render',
    str_contains($recorder, "'event_type' => 'server_page'")
        && str_contains($recorder, "['page_view', 'section', 'interaction']")
        && str_contains($recorder, 'promoteRecentServerPage(')
        && str_contains($recorder, 'UPDATE ue_access_events SET event_type="page_view"')
        && str_contains($client, "send({event_type: 'page_view'});")
        && str_contains($support, 'CatalogAccessEventRecorder')
        && str_contains($support, 'recordPageView();'),
    'A dynamic PHP render must be promoted to the browser-confirmed page_view instead of creating two primary page-load rows; cached pages still insert browser page_view rows.'
);

$record(
    'interaction_tracking_avoids_duplicate_navigation_and_form_values',
    str_contains($client, 'closest(\'a[href],button,input[type="submit"],input[type="button"]\')')
        && str_contains($client, "event_type: 'section'")
        && str_contains($client, "event_type: 'interaction'")
        && str_contains($client, "if (element.matches('a[href]') && sameSitePath(element.getAttribute('href'))) {")
        && str_contains($client, 'if (event.persisted) trackPageView();')
        && !str_contains($client, 'FormData(')
        && !str_contains($client, '.value) body.set'),
    'Client telemetry must avoid a second interaction row for normal same-site navigation, count BFCache restores as page loads, and never record form-field contents.'
);

$record(
    'client_endpoint_is_same_origin_and_rate_limited',
    str_contains($endpoint, 'HTTP_SEC_FETCH_SITE')
        && str_contains($endpoint, "'same-origin', 'same-site'")
        && str_contains($endpoint, "'access-matrix-event'")
        && str_contains($endpoint, 'catalog_start_session();')
        && str_contains($endpoint, 'recordClientEvent($_POST)'),
    'Interaction telemetry endpoint must accept same-site POST events only and cap write amplification.'
);

$record(
    'site_blocklist_has_precache_markers',
    str_contains($siteBlock, 'public static function isBlockedCached')
        && str_contains($siteBlock, "'security' . DIRECTORY_SEPARATOR . 'site-blocklist'")
        && str_contains($siteBlock, 'writeMarker(')
        && str_contains($siteBlock, 'DELETE FROM ue_site_blocked_ips'),
    'Full-site blocks must mirror to filesystem markers so the public guard can reject requests before DB/cache work.'
);

$record(
    'public_guard_redirects_blocked_ips',
    str_contains($guard, 'CatalogSiteBlocklist::isBlockedCached')
        && str_contains($guard, '$script !== \'blacklisted.php\'')
        && str_contains($guard, "header('Location: ' . \$location)")
        && str_contains($guard, 'http_response_code(302)')
        && strpos($guard, 'CatalogSiteBlocklist::isBlockedCached') < strpos($guard, 'if (!$this->guardableMethod())')
        && str_contains($guard, 'A full-site administrator block applies to every HTTP method.'),
    'Anonymous blocked IPs must be redirected to blacklisted.php before normal browsing; administrator sessions remain exempt.'
);

$record(
    'blacklisted_page_accepts_removal_feedback_without_normal_guard',
    str_contains($blockedPage, "require_once __DIR__ . '/lib/CatalogSupportCore.php';")
        && !str_contains($blockedPage, "require_once __DIR__ . '/lib/CatalogSupport.php';")
        && str_contains($blockedPage, 'ue_site_block_feedback')
        && str_contains($blockedPage, 'Request removal')
        && str_contains($blockedPage, 'site_block_feedback'),
    'The blacklist landing page must bypass the normal site guard and store bounded removal requests.'
);

$record(
    'admin_matrix_has_busy_pages_sections_transitions_and_ip_actions',
    str_contains($admin, 'Busiest pages')
        && str_contains($admin, 'Busiest sections')
        && str_contains($admin, 'Navigation matrix')
        && str_contains($admin, 'Top interactions')
        && str_contains($admin, 'Most active IPs')
        && str_contains($admin, 'block_selected_ips')
        && str_contains($admin, 'delete_selected')
        && str_contains($admin, 'block_and_delete_selected')
        && str_contains($admin, 'Block selected IPs + delete selected events')
        && str_contains($admin, 'feedback_unblock')
        && str_contains($admin, 'Full or partial IP')
        && str_contains($admin, 'Session hash prefix')
        && str_contains($admin, 'session_hex'),
    'Administrator page must expose traffic hot spots, navigation movement, raw events, deletion, partial-IP filtering and full-site blocking.'
);

$record(
    'bulk_block_and_delete_blocks_before_deleting',
    str_contains($admin, "in_array(\$action, ['block_selected_ips', 'block_and_delete_selected'], true)")
        && str_contains($admin, "if (\$action === 'block_and_delete_selected')")
        && str_contains($admin, 'Block first. If any blacklist operation fails')
        && str_contains($admin, "DELETE FROM ue_access_events WHERE id IN ("),
    'Combined bulk action must block selected IPs first and only then delete the selected telemetry rows.'
);

$record(
    'summary_cards_are_width_constrained',
    str_contains($admin, 'grid-template-columns:repeat(2,minmax(0,1fr))')
        && str_contains($admin, 'align-items:start')
        && str_contains($admin, '.access-matrix-grid .ui-section{margin:0;min-width:0;max-width:100%;overflow:hidden}')
        && str_contains($admin, '.access-matrix-grid .ui-section__body{min-width:0;max-width:100%;overflow-x:auto}')
        && str_contains($admin, '.access-matrix-grid table{width:100%;max-width:100%;table-layout:auto}')
        && str_contains($admin, '.access-matrix-grid td:first-child')
        && str_contains($admin, 'overflow-wrap:anywhere'),
    'Two-column activity summary cards must stay within the page while retaining natural table column sizing and wrapping long URL cells.'
);

$record(
    'summary_cards_are_top_ten_with_stable_page_columns',
    str_contains($admin, 'GROUP BY a.page_key ORDER BY hits DESC,a.page_key LIMIT 10')
        && str_contains($admin, 'GROUP BY a.request_path ORDER BY hits DESC,a.request_path LIMIT 10')
        && str_contains($admin, 'GROUP BY a.referrer_path,a.request_path ORDER BY hits DESC LIMIT 10')
        && str_contains($admin, 'GROUP BY a.page_key,a.section_key ORDER BY hits DESC,a.page_key LIMIT 10')
        && str_contains($admin, 'GROUP BY a.page_key,a.action_key,a.target_path ORDER BY hits DESC,a.page_key LIMIT 10')
        && str_contains($admin, 'access-matrix-sections-table')
        && str_contains($admin, 'access-matrix-interactions-table')
        && str_contains($admin, 'min-width:130px;white-space:nowrap'),
    'All five two-column summary cards must show at most ten rows, and Page columns in Sections/Interactions must remain readable instead of collapsing vertically.'
);

$record(
    'activity_log_defaults_to_page_activity_without_hiding_requests',
    str_contains($admin, "(string)(\$_GET['event'] ?? 'page_activity')")
        && str_contains($admin, "'page_activity' => 'Page activity'")
        && str_contains($admin, "'page_view' => 'Browser-confirmed page loads'")
        && str_contains($admin, "'server_page' => 'Server-only page requests'")
        && str_contains($admin, "a.event_type IN (\"page_view\",\"server_page\")")
        && str_contains($admin, "COUNT(DISTINCT CASE WHEN a.event_type IN (\"page_view\",\"server_page\") THEN a.ip_address END) unique_ips")
        && str_contains($admin, "\$rawWhereSql")
        && str_contains($admin, "'SELECT COUNT(*) c FROM ue_access_events a' . \$rawWhereSql")
        && str_contains($admin, "<h2>Activity log</h2>")
        && str_contains($admin, "catalog_stat_card('Page activity'")
        && str_contains($admin, "catalog_stat_card('Sessions'"),
    'The default Access Matrix activity log and headline IP/session totals must cover both browser-confirmed loads and server-only page requests, while detailed telemetry remains separately selectable.'
);

$record(
    'raw_activity_is_compact_linked_and_actionable',
    str_contains($admin, 'function access_matrix_time(')
        && str_contains($admin, "substr(\$value, 0, 19)")
        && str_contains($admin, 'function access_matrix_logged_link(')
        && str_contains($admin, '.access-matrix-table{min-width:1040px;table-layout:auto}')
        && str_contains($admin, '.access-matrix-agent-text')
        && str_contains($admin, 'text-overflow:ellipsis')
        && str_contains($admin, 'name="block_event_id"')
        && str_contains($admin, '>XX</button>')
        && str_contains($admin, "access_matrix_logged_link((string)\$row['request_path'], (string)\$row['page_key'])")
        && str_contains($admin, "access_matrix_logged_link((string)(\$row['referrer_path'] ?? ''))")
        && str_contains($admin, "access_matrix_logged_link((string)(\$row['target_path'] ?? ''))")
        && str_contains($admin, "Section: ' . access_matrix_logged_link(")
        && str_contains($admin, "Action: ' . access_matrix_logged_link("),
    'Raw events must use compact local date/time, clickable logged paths and a one-row full-site blacklist action.'
);

$record(
    'page_activity_populates_page_link_and_navigation_cards',
    str_contains($admin, '<h2>Busiest pages</h2>')
        && str_contains($admin, '<h2>Busiest links</h2>')
        && str_contains($admin, '<h2>Navigation matrix</h2>')
        && substr_count($admin, 'a.event_type IN ("page_view","server_page")') >= 4
        && str_contains($admin, "'event' => 'page_activity'")
        && str_contains($admin, 'GROUP BY a.request_path')
        && str_contains($admin, "access_matrix_logged_link((string)\$row['request_path'])")
        && str_contains($admin, 'destination_path'),
    'Busiest pages, exact links and navigation must use the same confirmed-or-server-only page activity scope as the headline/activity log.'
);

$record(
    'browser_only_cards_are_labelled',
    str_contains($admin, 'Browser JavaScript only; server-only/crawler requests cannot report visible sections.')
        && str_contains($admin, 'Browser JavaScript actions only; ordinary same-site navigation is intentionally not duplicated here.'),
    'Section and interaction cards must explain why crawler/server-only traffic cannot populate them.'
);

$record(
    'activity_map_uses_detailed_local_geoip',
    str_contains($admin, 'CatalogGeoIpLocationResolver')
        && str_contains($admin, 'catalog_world_map_attributes(')
        && str_contains($admin, 'Approximate city/region locations from the local GeoIP database'),
    'Access Matrix map rows must expose local city/region coordinates with country fallback.'
);

$record(
    'activity_card_metrics_drill_down_to_hits_and_ips',
    str_contains($admin, "'show_ips' => 1")
        && str_contains($admin, 'id="matching-ips"')
        && str_contains($admin, 'IP addresses contributing to the selected card total.')
        && str_contains($admin, 'id="raw-events"')
        && str_contains($admin, "'page_exact'")
        && str_contains($admin, "'path_exact'")
        && str_contains($admin, "'section_exact'")
        && str_contains($admin, "'action_exact'")
        && str_contains($admin, "'referrer_exact'")
        && str_contains($admin, "'destination_exact'")
        && str_contains($admin, 'access-matrix-metric-link')
        && str_contains($admin, '<th>Hits</th><th>IPs</th>'),
    'Hits on every activity summary card must open the matching raw events, while IP totals open the exact contributing IP list.'
);

$record(
    'download_logs_can_promote_abusive_ips_to_site_block',
    str_contains($downloadLogs, 'block_selected_site_ips')
        && str_contains($downloadLogs, 'Block selected IPs from entire site')
        && str_contains($downloadLogs, 'CatalogSiteBlocklist'),
    'Selected IPs noticed in Download Logs must be promotable from transfer-only blocking to the full-site blocklist.'
);

$record(
    'site_activity_and_blacklist_are_in_admin_navigation',
    str_contains($navigation, "'Site Activity Logs' => \$root . 'access-matrix.php'")
        && str_contains($navigation, "'Site Blacklist' => \$root . 'site-blacklist.php'"),
    'Whole-site activity logs and blacklist administration must both be obvious in administrator navigation.'
);

$record(
    'dedicated_site_blacklist_admin_exists',
    str_contains($siteBlacklist, "catalog_require_admin_page('Site Blacklist')")
        && str_contains($siteBlacklist, 'CatalogSiteBlocklist')
        && str_contains($siteBlacklist, 'Removal requests')
        && str_contains($siteBlacklist, 'Approve + unblock')
        && str_contains($siteBlacklist, 'Full or partial IP'),
    'Administrators need a dedicated blacklist page for blocking/unblocking addresses and reviewing removal requests.'
);

$record(
    'global_html_injects_matrix_client',
    str_contains($transform, "'catalog-access-matrix.js'")
        && str_contains($transform, "'catalog/assets/'")
        && str_contains($transform, "\$assetPrefix = \$federation"),
    'Cross-cutting response transform must inject telemetry with a valid asset path on root, catalog and federation HTML pages.'
);

$syntaxFailures = [];
foreach ([
    'migrations/202609080002_access_matrix_site_blocklist.php',
    'migrations/202609080003_geoip_city_location.php',
    'src/Infrastructure/Downloads/CatalogGeoIpLocationResolver.php',
    'src/Infrastructure/Telemetry/CatalogAccessEventRecorder.php',
    'src/Infrastructure/Security/CatalogSiteBlocklist.php',
    'src/Infrastructure/Security/CatalogPublicAccessGuard.php',
    'lib/CatalogSupportCore.php',
    'src/Presentation/Http/CatalogPageResponseTransform.php',
    'access-event.php',
    'access-matrix.php',
    'blacklisted.php',
    'site-blacklist.php',
    'download-logs.php',
] as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
