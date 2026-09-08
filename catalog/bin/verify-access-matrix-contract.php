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
    'server_and_browser_page_tracking_are_separate',
    str_contains($recorder, "'event_type' => 'server_page'")
        && str_contains($recorder, "['page_view', 'section', 'interaction']")
        && str_contains($client, "send({event_type: 'page_view'});")
        && str_contains($support, 'CatalogAccessEventRecorder')
        && str_contains($support, 'recordPageView();'),
    'PHP renders must record server_page while browser page_view events cover cached pages without double-counting the main page-view metric.'
);

$record(
    'interaction_tracking_avoids_form_values',
    str_contains($client, 'closest(\'a[href],button,input[type="submit"],input[type="button"]\')')
        && str_contains($client, "event_type: 'section'")
        && str_contains($client, "event_type: 'interaction'")
        && !str_contains($client, 'FormData(')
        && !str_contains($client, '.value) body.set'),
    'Client telemetry must record only coarse page/section/action metadata, never form-field contents.'
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
        && str_contains($admin, 'feedback_unblock')
        && str_contains($admin, 'Full or partial IP')
        && str_contains($admin, 'Session hash prefix')
        && str_contains($admin, 'session_hex'),
    'Administrator page must expose traffic hot spots, navigation movement, raw events, deletion, partial-IP filtering and full-site blocking.'
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
        && str_contains($admin, '>Blacklist IP</button>')
        && str_contains($admin, "access_matrix_logged_link((string)\$row['request_path'], (string)\$row['page_key'])")
        && str_contains($admin, "access_matrix_logged_link((string)(\$row['referrer_path'] ?? ''))")
        && str_contains($admin, "access_matrix_logged_link((string)(\$row['target_path'] ?? ''))"),
    'Raw events must use second-precision time, compact columns, clickable logged paths and a one-row full-site blacklist action.'
);

$record(
    'exact_logged_links_have_their_own_card',
    str_contains($admin, '<h2>Busiest links</h2>')
        && str_contains($admin, 'Exact logged URLs, including useful query parameters such as file IDs.')
        && str_contains($admin, 'GROUP BY a.request_path')
        && str_contains($admin, "access_matrix_logged_link((string)\$row['request_path'])")
        && str_contains($admin, 'destination_path'),
    'Site Activity Logs must report exact request URLs including useful query parameters instead of only grouping by generic PHP page name.'
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
    str_contains($transform, "'catalog-access-matrix.js'"),
    'Cross-cutting page response transform must inject access telemetry on catalog HTML pages.'
);

$syntaxFailures = [];
foreach ([
    'migrations/202609080002_access_matrix_site_blocklist.php',
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
