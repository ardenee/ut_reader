<?php
/**
 * Administrator access-matrix reporting.
 *
 * Shows raw page/section/interaction events, busiest pages/sections, navigation
 * transitions and IP activity. Administrators can delete telemetry and manage
 * the full-site IP blocklist from the same workflow.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeoIpCountryResolver;
use UnrealDb\Catalog\Infrastructure\Security\CatalogSiteBlocklist;

function access_matrix_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function access_matrix_text(string $value, int $limit = 200): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
}

function access_matrix_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === 'all') {
            unset($query[$key]);
        }
    }
    return http_build_query($query);
}

function access_matrix_time(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    try {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone('Europe/Dublin');
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), $utc);
        return $parsed instanceof DateTimeImmutable
            ? $parsed->setTimezone($local)->format('Y-m-d H:i:s')
            : substr($value, 0, 19);
    } catch (Throwable) {
        return substr($value, 0, 19);
    }
}

function access_matrix_time_html(mixed $value): string
{
    $value = access_matrix_time($value);
    if ($value === '') {
        return '';
    }

    return '<span class="access-matrix-date">' . catalog_h(substr($value, 0, 10)) . '</span>'
        . '<br><span class="access-matrix-clock">' . catalog_h(substr($value, 11, 8)) . '</span>';
}

function access_matrix_logged_link(mixed $path, ?string $label = null, string $class = ''): string
{
    $path = trim((string)$path);
    $label = $label === null ? $path : trim($label);
    if ($path === '' || !str_starts_with($path, '/')) {
        return $label !== '' ? catalog_h($label) : '—';
    }
    $classAttribute = trim($class) !== '' ? ' class="' . catalog_h(trim($class)) . '"' : '';
    return '<a' . $classAttribute . ' href="' . catalog_h($path) . '">' . catalog_h($label !== '' ? $label : $path) . '</a>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Access Matrix')) {
        exit;
    }

    $eventsAvailable = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_access_events"'
    )->fetchColumn() === 1;
    $siteBlockAvailable = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_site_blocked_ips"'
    )->fetchColumn() === 1;
    $feedbackAvailable = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_site_block_feedback"'
    )->fetchColumn() === 1;

    $siteBlocklist = $siteBlockAvailable ? new CatalogSiteBlocklist($db, $config) : null;
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('access_matrix_admin');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $blockEventId = max(0, (int)($_POST['block_event_id'] ?? 0));
        if ($blockEventId > 0) {
            $action = 'block_event_ip';
        }
        $userId = max(0, (int)($_SESSION['user']['id'] ?? 0));

        if ($action === 'delete_selected') {
            $ids = is_array($_POST['ids'] ?? null) ? array_values(array_unique(array_map('intval', $_POST['ids']))) : [];
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
            if (!$eventsAvailable || $ids === []) {
                throw new RuntimeException('Select one or more access events to delete.');
            }
            if (count($ids) > 1000) {
                throw new RuntimeException('Select no more than 1,000 events at once.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare('DELETE FROM ue_access_events WHERE id IN (' . $placeholders . ')');
            $statement->execute($ids);
            $message = $statement->rowCount() . ' access event(s) deleted.';
        } elseif ($action === 'delete_older') {
            $days = max(0, min(3650, (int)($_POST['days'] ?? 30)));
            if ($days === 0) {
                $statement = $db->prepare('DELETE FROM ue_access_events');
                $statement->execute();
                $message = $statement->rowCount() . ' access event(s) deleted. Access telemetry is now empty.';
            } else {
                $statement = $db->prepare(
                    'DELETE FROM ue_access_events WHERE occurred_at<DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL '
                    . $days . ' DAY)'
                );
                $statement->execute();
                $message = $statement->rowCount() . ' access event(s) older than ' . $days . ' days deleted.';
            }
        } elseif ($action === 'block_event_ip') {
            if (!$siteBlocklist instanceof CatalogSiteBlocklist || !$eventsAvailable) {
                throw new RuntimeException('Run the pending access-matrix migration first.');
            }
            $event = catalog_one(
                $db,
                'SELECT INET6_NTOA(ip_address) ip FROM ue_access_events WHERE id=? AND ip_address IS NOT NULL',
                [$blockEventId]
            );
            $eventIp = trim((string)($event['ip'] ?? ''));
            if ($eventIp === '') {
                throw new RuntimeException('The selected access event has no IP address to block.');
            }
            $siteBlocklist->block($eventIp, $userId, 'Blocked from Site Activity Logs event #' . $blockEventId . '.');
            $message = $eventIp . ' blocked from the entire site.';
        } elseif ($action === 'block_selected_ips') {
            if (!$siteBlocklist instanceof CatalogSiteBlocklist || !$eventsAvailable) {
                throw new RuntimeException('Run the pending access-matrix migration first.');
            }
            $ids = is_array($_POST['ids'] ?? null) ? array_values(array_unique(array_map('intval', $_POST['ids']))) : [];
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
            if ($ids === []) {
                throw new RuntimeException('Select one or more access events first.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare(
                'SELECT DISTINCT INET6_NTOA(ip_address) ip FROM ue_access_events '
                . 'WHERE id IN (' . $placeholders . ') AND ip_address IS NOT NULL'
            );
            $statement->execute($ids);
            $ips = array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            foreach ($ips as $selectedIp) {
                $siteBlocklist->block($selectedIp, $userId, 'Blocked from Access Matrix activity.');
            }
            $message = count($ips) . ' IP address(es) blocked from the site.';
        } elseif ($action === 'block_ip') {
            if (!$siteBlocklist instanceof CatalogSiteBlocklist) {
                throw new RuntimeException('Run the pending access-matrix migration first.');
            }
            $siteBlocklist->block(
                (string)($_POST['ip_address'] ?? ''),
                $userId,
                (string)($_POST['note'] ?? '')
            );
            $message = 'IP address blocked from the entire site.';
        } elseif ($action === 'unblock_ip') {
            if (!$siteBlocklist instanceof CatalogSiteBlocklist) {
                throw new RuntimeException('Run the pending access-matrix migration first.');
            }
            $removed = $siteBlocklist->unblock((string)($_POST['ip_address'] ?? ''));
            $message = $removed > 0 ? 'IP address removed from the site blocklist.' : 'IP address was not blocked.';
        } elseif (in_array($action, ['feedback_resolve', 'feedback_unblock'], true)) {
            if (!$feedbackAvailable || !$siteBlocklist instanceof CatalogSiteBlocklist) {
                throw new RuntimeException('Run the pending access-matrix migration first.');
            }
            $feedbackId = max(0, (int)($_POST['feedback_id'] ?? 0));
            $feedback = $feedbackId > 0
                ? catalog_one(
                    $db,
                    'SELECT id,INET6_NTOA(ip_address) ip FROM ue_site_block_feedback WHERE id=?',
                    [$feedbackId]
                )
                : null;
            if (!$feedback) {
                throw new RuntimeException('Removal request not found.');
            }
            if ($action === 'feedback_unblock') {
                $siteBlocklist->unblock((string)$feedback['ip']);
            }
            $status = $action === 'feedback_unblock' ? 'approved' : 'resolved';
            $statement = $db->prepare(
                'UPDATE ue_site_block_feedback SET status=?,resolution_note=?,resolved_at=CURRENT_TIMESTAMP(6) WHERE id=?'
            );
            $statement->execute([
                $status,
                access_matrix_text((string)($_POST['resolution_note'] ?? ''), 500),
                $feedbackId,
            ]);
            $message = $action === 'feedback_unblock'
                ? 'Removal request approved and IP unblocked.'
                : 'Removal request marked resolved.';
        } else {
            throw new RuntimeException('Choose a valid Access Matrix action.');
        }
    }

    $eventType = access_matrix_choice(
        (string)($_GET['event'] ?? 'all'),
        ['all', 'page_view', 'server_page', 'section', 'interaction'],
        'all'
    );
    $days = access_matrix_choice((string)($_GET['days'] ?? '7'), ['1', '7', '30', '90', 'all'], '7');
    $ip = access_matrix_text((string)($_GET['ip'] ?? ''), 80);
    $pageSearch = access_matrix_text((string)($_GET['page_q'] ?? ''), 190);
    $pageExact = access_matrix_text((string)($_GET['page_exact'] ?? ''), 190);
    $pathExact = access_matrix_text((string)($_GET['path_exact'] ?? ''), 500);
    $sectionExact = access_matrix_text((string)($_GET['section_exact'] ?? ''), 190);
    $actionExact = access_matrix_text((string)($_GET['action_exact'] ?? ''), 190);
    $targetExact = access_matrix_text((string)($_GET['target_exact'] ?? ''), 500);
    $referrerExact = access_matrix_text((string)($_GET['referrer_exact'] ?? ''), 500);
    $destinationExact = access_matrix_text((string)($_GET['destination_exact'] ?? ''), 500);
    $showIps = (string)($_GET['show_ips'] ?? '0') === '1';
    $session = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)($_GET['session'] ?? '')) ?? '');
    $session = substr($session, 0, 64);
    $search = access_matrix_text((string)($_GET['q'] ?? ''), 200);
    $perPage = (int)($_GET['per_page'] ?? 100);
    if (!in_array($perPage, [50, 100, 250, 500], true)) {
        $perPage = 100;
    }
    $page = max(1, (int)($_GET['p'] ?? 1));

    $where = [];
    $args = [];
    if ($days !== 'all') {
        $where[] = 'a.occurred_at>=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL ' . (int)$days . ' DAY)';
    }
    if ($eventType !== 'all') {
        $where[] = 'a.event_type=?';
        $args[] = $eventType;
    }
    if ($ip !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ip) . '%';
        $where[] = 'INET6_NTOA(a.ip_address) LIKE ? ESCAPE "\\\\"';
        $args[] = $like;
    }
    if ($pageSearch !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pageSearch) . '%';
        $where[] = '(a.page_key LIKE ? ESCAPE "\\\\" OR a.request_path LIKE ? ESCAPE "\\\\")';
        array_push($args, $like, $like);
    }
    foreach ([
        ['a.page_key', $pageExact],
        ['a.request_path', $pathExact],
        ['a.section_key', $sectionExact],
        ['a.action_key', $actionExact],
        ['a.target_path', $targetExact],
        ['a.referrer_path', $referrerExact],
    ] as [$column, $value]) {
        if ($value !== '') {
            $where[] = $column . '=?';
            $args[] = $value;
        }
    }
    if ($destinationExact !== '') {
        $where[] = 'a.request_path=?';
        $args[] = $destinationExact;
    }
    if ($session !== '') {
        $where[] = 'LOWER(HEX(a.session_hash)) LIKE ?';
        $args[] = $session . '%';
    }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(a.section_key LIKE ? ESCAPE "\\\\" OR a.action_key LIKE ? ESCAPE "\\\\" '
            . 'OR a.target_path LIKE ? ESCAPE "\\\\" OR a.user_agent LIKE ? ESCAPE "\\\\")';
        array_push($args, $like, $like, $like, $like);
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $summary = ['events' => 0, 'page_views' => 0, 'server_pages' => 0, 'interactions' => 0, 'unique_ips' => 0];
    $rows = [];
    $total = 0;
    $pages = 1;
    $topPages = [];
    $topLinks = [];
    $topSections = [];
    $topActions = [];
    $transitions = [];
    $topIps = [];
    $matchingIps = [];

    if ($eventsAvailable) {
        $summaryRow = catalog_one(
            $db,
            'SELECT COUNT(*) events,SUM(a.event_type="page_view") page_views,'
            . 'SUM(a.event_type="server_page") server_pages,'
            . 'SUM(a.event_type="interaction") interactions,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a' . $whereSql,
            $args
        ) ?: [];
        $summary = [
            'events' => (int)($summaryRow['events'] ?? 0),
            'page_views' => (int)($summaryRow['page_views'] ?? 0),
            'server_pages' => (int)($summaryRow['server_pages'] ?? 0),
            'interactions' => (int)($summaryRow['interactions'] ?? 0),
            'unique_ips' => (int)($summaryRow['unique_ips'] ?? 0),
        ];

        $total = $summary['events'];
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $statement = $db->prepare(
            'SELECT a.*,INET6_NTOA(a.ip_address) ip_text,LOWER(HEX(a.session_hash)) session_hex,u.username '
            . 'FROM ue_access_events a LEFT JOIN ue_users u ON u.id=a.user_id'
            . $whereSql
            . ' ORDER BY a.occurred_at DESC,a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $topPages = catalog_all(
            $db,
            'SELECT a.page_key,MIN(a.request_path) sample_path,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="page_view" GROUP BY a.page_key ORDER BY hits DESC,a.page_key LIMIT 20',
            $args
        );
        $topLinks = catalog_all(
            $db,
            'SELECT a.request_path,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="page_view" AND a.request_path<>"" '
            . 'GROUP BY a.request_path ORDER BY hits DESC,a.request_path LIMIT 30',
            $args
        );
        $topSections = catalog_all(
            $db,
            'SELECT a.page_key,a.section_key,MIN(a.request_path) sample_path,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="section" AND a.section_key IS NOT NULL '
            . 'GROUP BY a.page_key,a.section_key ORDER BY hits DESC,a.page_key LIMIT 20',
            $args
        );
        $topActions = catalog_all(
            $db,
            'SELECT a.page_key,a.action_key,a.target_path,MIN(a.request_path) sample_path,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="interaction" AND a.action_key IS NOT NULL '
            . 'GROUP BY a.page_key,a.action_key,a.target_path ORDER BY hits DESC,a.page_key LIMIT 20',
            $args
        );
        $transitions = catalog_all(
            $db,
            'SELECT a.referrer_path source_path,a.request_path destination_path,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="page_view" AND a.referrer_path IS NOT NULL AND a.referrer_path<>"" '
            . 'GROUP BY a.referrer_path,a.request_path ORDER BY hits DESC LIMIT 30',
            $args
        );
        $topIps = catalog_all(
            $db,
            'SELECT INET6_NTOA(a.ip_address) ip,COUNT(*) events,COUNT(DISTINCT a.session_hash) sessions,'
            . 'SUM(a.event_type="page_view") page_views,'
            . 'SUM(a.event_type="server_page") server_pages,'
            . 'SUM(a.event_type="interaction") interactions,'
            . 'MIN(a.occurred_at) first_seen,MAX(a.occurred_at) last_seen '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.ip_address IS NOT NULL GROUP BY a.ip_address ORDER BY events DESC LIMIT 30',
            $args
        );
        if ($showIps) {
            $matchingIps = catalog_all(
                $db,
                'SELECT INET6_NTOA(a.ip_address) ip,COUNT(*) hits,COUNT(DISTINCT a.session_hash) sessions,'
                . 'MIN(a.occurred_at) first_seen,MAX(a.occurred_at) last_seen '
                . 'FROM ue_access_events a'
                . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
                . 'a.ip_address IS NOT NULL GROUP BY a.ip_address ORDER BY hits DESC,a.ip_address LIMIT 500',
                $args
            );
        }
    }

    $geoIpResolver = new CatalogGeoIpCountryResolver($db);
    foreach ($rows as $index => $row) {
        $country = $geoIpResolver->resolve((string)($row['ip_text'] ?? ''));
        $rows[$index]['map_country_code'] = $country['country_code'];
        $rows[$index]['map_country_name'] = $country['country_name'];
    }

    $blockedRows = $siteBlocklist instanceof CatalogSiteBlocklist ? $siteBlocklist->all() : [];
    $blockedLookup = [];
    foreach ($blockedRows as $blockedRow) {
        $blockedLookup[strtolower((string)$blockedRow['ip'])] = true;
    }
    $feedbackRows = $feedbackAvailable
        ? catalog_all(
            $db,
            'SELECT id,INET6_NTOA(ip_address) ip,email,message,status,created_at,resolved_at,resolution_note '
            . 'FROM ue_site_block_feedback ORDER BY (status="open") DESC,created_at DESC LIMIT 100'
        )
        : [];

    catalog_head('Access Matrix');
    echo '<style>'
        . '.access-matrix-stats{grid-template-columns:repeat(5,minmax(140px,1fr));margin-bottom:14px}'
        . '.access-matrix-filter{display:flex;gap:9px;align-items:end;flex-wrap:wrap;margin-bottom:14px}'
        . '.access-matrix-filter .grow{flex:1;min-width:220px}'
        . '.access-matrix-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}'
        . '.access-matrix-grid .ui-section{margin:0}'
        . '.access-matrix-table{min-width:1040px;table-layout:auto}'
        . '.access-matrix-table .am-check,.access-matrix-table .am-time,.access-matrix-table .am-type,.access-matrix-table .am-ip{width:1%;white-space:nowrap}'
        . '.access-matrix-table .am-page{width:auto;min-width:300px;overflow-wrap:anywhere}'
        . '.access-matrix-table .am-route{width:auto;min-width:240px;overflow-wrap:anywhere}'
        . '.access-matrix-table .am-agent{width:28ch;min-width:28ch;max-width:28ch}'
        . '.access-matrix-agent-text{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . '.access-matrix-ip-line{display:flex;align-items:center;justify-content:space-between;gap:8px}'
        . '.access-matrix-ip-value{display:inline-flex;align-items:center;gap:2px}'
        . '.access-matrix-ip-actions{display:inline-flex;gap:5px;align-items:center;margin-left:auto}'
        . '.access-matrix-ip-actions .ui-button{min-width:30px;padding:2px 7px;line-height:1.2}'
        . '.access-matrix-date{display:inline-block}'
        . '.access-matrix-clock{display:inline-block;color:var(--muted)}'
        . '.access-country-flag{display:inline-block;width:20px;height:15px;object-fit:cover;border-radius:2px;vertical-align:-2px;cursor:help}'
        . '.access-matrix-actions,.access-block-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}'
        . '.access-block-actions .grow{flex:1;min-width:240px}'

        . '.access-matrix-metric-link{font-weight:700;text-decoration:none}'
        . '.access-matrix-metric-link:hover{text-decoration:underline}'
        . '.access-matrix-pages{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:12px}'
        . '.access-matrix-collapsible>summary{list-style:none;cursor:pointer}'
        . '.access-matrix-collapsible>summary::-webkit-details-marker{display:none}'
        . '.access-matrix-collapsible>summary .ui-section__header{margin:0}'
        . '.access-matrix-collapsible>summary .ui-section__header::after{content:"Expand";margin-left:auto;color:var(--muted);font-size:.9rem}'
        . '.access-matrix-collapsible[open]>summary .ui-section__header::after{content:"Collapse"}'
        . '@media(max-width:1000px){.access-matrix-grid{grid-template-columns:1fr}.access-matrix-stats{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'Site Activity Logs',
        'Whole-site page, section and interaction telemetry for understanding busy parts of the site, visitor navigation and crawler-like activity.',
        ['Logging Settings' => 'logging-settings.php', 'Download Logs' => 'download-logs.php', 'Site Blacklist' => 'site-blacklist.php', 'Public Access' => 'public-access-settings.php']
    );

    if ($message !== '') {
        echo CatalogUi::alert('success', $message);
    }
    if (!$eventsAvailable || !$siteBlockAvailable || !$feedbackAvailable) {
        echo CatalogUi::alert(
            'warning',
            'Access Matrix storage is not fully installed. Run the pending database migration before relying on telemetry or site blocking.',
            'Migration required'
        );
    }

    echo '<div class="grid access-matrix-stats">';
    catalog_stat_card('Events', $summary['events']);
    catalog_stat_card('Browser page views', $summary['page_views']);
    catalog_stat_card('Server renders', $summary['server_pages']);
    catalog_stat_card('Interactions', $summary['interactions']);
    catalog_stat_card('Unique IPs', $summary['unique_ips']);
    echo '</div>';

    echo '<form class="access-matrix-filter" method="get">'
        . '<label>Window <select name="days">';
    foreach (['1' => '24 hours', '7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($days === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label><label>Event <select name="event">';
    foreach (['all' => 'All', 'page_view' => 'Browser page views', 'server_page' => 'Server renders', 'section' => 'Sections', 'interaction' => 'Interactions'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($eventType === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>'
        . '<label>IP <input name="ip" value="' . catalog_h($ip) . '" placeholder="Full or partial IP"></label>'
        . '<label class="grow">Page <input name="page_q" value="' . catalog_h($pageSearch) . '" placeholder="Page or path"></label>'
        . '<label>Session <input class="mono" name="session" value="' . catalog_h($session) . '" placeholder="Session hash prefix"></label>'
        . '<label class="grow">Section / action <input name="q" value="' . catalog_h($search) . '" placeholder="Section, button, target or user agent"></label>'
        . '<label>Rows <select name="per_page">';
    foreach ([50,100,250,500] as $value) {
        echo '<option value="' . $value . '"' . ($perPage === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button></form>';

    echo '<div class="access-matrix-grid">';
    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Busiest pages</h2></div></div><div class="ui-section__body">';
    if ($topPages === []) echo '<p class="muted">No page-view data.</p>';
    else {
        echo '<table><thead><tr><th>Page</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($topPages as $row) {
            $pageFilters = ['event' => 'page_view', 'page_exact' => (string)$row['page_key'], 'p' => 1];
            echo '<tr><td class="mono">' . access_matrix_logged_link((string)($row['sample_path'] ?? ''), (string)$row['page_key']) . '</td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($pageFilters + ['show_ips' => null])) . '#raw-events">' . (int)$row['hits'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($pageFilters + ['show_ips' => 1])) . '#matching-ips">' . (int)$row['unique_ips'] . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Busiest links</h2><p>Exact logged URLs, including useful query parameters such as file IDs.</p></div></div><div class="ui-section__body">';
    if ($topLinks === []) echo '<p class="muted">No browser link data.</p>';
    else {
        echo '<table><thead><tr><th>Link</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($topLinks as $row) {
            $linkFilters = ['event' => 'page_view', 'path_exact' => (string)$row['request_path'], 'p' => 1];
            echo '<tr><td class="mono small">' . access_matrix_logged_link((string)$row['request_path']) . '</td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($linkFilters + ['show_ips' => null])) . '#raw-events">' . (int)$row['hits'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($linkFilters + ['show_ips' => 1])) . '#matching-ips">' . (int)$row['unique_ips'] . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Navigation matrix</h2><p>Referring page → destination page.</p></div></div><div class="ui-section__body">';
    if ($transitions === []) echo '<p class="muted">No same-site navigation transitions recorded.</p>';
    else {
        echo '<table><thead><tr><th>From</th><th>To</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($transitions as $row) {
            $transitionFilters = [
                'event' => 'page_view',
                'referrer_exact' => (string)$row['source_path'],
                'destination_exact' => (string)$row['destination_path'],
                'p' => 1,
            ];
            echo '<tr><td class="mono small">' . access_matrix_logged_link((string)$row['source_path']) . '</td><td class="mono small">' . access_matrix_logged_link((string)$row['destination_path']) . '</td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($transitionFilters + ['show_ips' => null])) . '#raw-events">' . (int)$row['hits'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($transitionFilters + ['show_ips' => 1])) . '#matching-ips">' . (int)$row['unique_ips'] . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Busiest sections</h2></div></div><div class="ui-section__body">';
    if ($topSections === []) echo '<p class="muted">No section-view data.</p>';
    else {
        echo '<table><thead><tr><th>Page</th><th>Section</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($topSections as $row) {
            $sectionFilters = [
                'event' => 'section',
                'page_exact' => (string)$row['page_key'],
                'section_exact' => (string)$row['section_key'],
                'p' => 1,
            ];
            echo '<tr><td class="mono small">' . access_matrix_logged_link((string)($row['sample_path'] ?? ''), (string)$row['page_key']) . '</td><td>' . catalog_h((string)$row['section_key']) . '</td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($sectionFilters + ['show_ips' => null])) . '#raw-events">' . (int)$row['hits'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($sectionFilters + ['show_ips' => 1])) . '#matching-ips">' . (int)$row['unique_ips'] . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Top interactions</h2></div></div><div class="ui-section__body">';
    if ($topActions === []) echo '<p class="muted">No interaction data.</p>';
    else {
        echo '<table><thead><tr><th>Page</th><th>Action</th><th>Target</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($topActions as $row) {
            $actionFilters = [
                'event' => 'interaction',
                'page_exact' => (string)$row['page_key'],
                'action_exact' => (string)$row['action_key'],
                'target_exact' => (string)($row['target_path'] ?? ''),
                'p' => 1,
            ];
            echo '<tr><td class="mono small">' . access_matrix_logged_link((string)($row['sample_path'] ?? ''), (string)$row['page_key']) . '</td><td>' . catalog_h((string)$row['action_key']) . '</td><td class="mono small">' . access_matrix_logged_link((string)($row['target_path'] ?? '')) . '</td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($actionFilters + ['show_ips' => null])) . '#raw-events">' . (int)$row['hits'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($actionFilters + ['show_ips' => 1])) . '#matching-ips">' . (int)$row['unique_ips'] . '</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';
    echo '</div>';

    if ($showIps) {
        echo '<section class="ui-section" id="matching-ips"><div class="ui-section__header"><div><h2>Matching IPs</h2>'
            . '<p>IP addresses contributing to the selected card total.</p></div></div><div class="ui-section__body">';
        if ($matchingIps === []) {
            echo '<p class="muted">No matching IP addresses.</p>';
        } else {
            echo '<table><thead><tr><th>IP</th><th>Hits</th><th>Sessions</th><th>First</th><th>Last</th><th></th></tr></thead><tbody>';
            foreach ($matchingIps as $matchIp) {
                $matchIpText = (string)$matchIp['ip'];
                echo '<tr><td class="mono"><a href="access-matrix.php?' . catalog_h(access_matrix_query(['ip' => $matchIpText, 'show_ips' => null, 'p' => 1])) . '#raw-events">' . catalog_h($matchIpText) . '</a></td>'
                    . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query(['ip' => $matchIpText, 'show_ips' => null, 'p' => 1])) . '#raw-events">' . (int)$matchIp['hits'] . '</a></td>'
                    . '<td>' . (int)$matchIp['sessions'] . '</td>'
                    . '<td class="mono small">' . catalog_h(access_matrix_time($matchIp['first_seen'])) . '</td>'
                    . '<td class="mono small">' . catalog_h(access_matrix_time($matchIp['last_seen'])) . '</td>'
                    . '<td><a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['ip' => $matchIpText, 'show_ips' => null, 'p' => 1])) . '#raw-events">View activity</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></section>';
    }

    echo '<details class="ui-section access-matrix-collapsible"><summary><div class="ui-section__header"><div><h2>Most active IPs</h2><p>Useful for spotting crawler-like navigation before blocking an address.</p></div></div></summary><div class="ui-section__body">';
    if ($topIps === []) echo '<p class="muted">No IP activity.</p>';
    else {
        echo '<table><thead><tr><th>IP</th><th>Sessions</th><th>Events</th><th>Browser pages</th><th>Server renders</th><th>Clicks</th><th>First</th><th>Last</th><th></th></tr></thead><tbody>';
        foreach ($topIps as $row) {
            $ipText = (string)$row['ip'];
            $ipBase = ['ip' => $ipText, 'p' => 1, 'show_ips' => null];
            echo '<tr><td class="mono"><a href="access-matrix.php?' . catalog_h(access_matrix_query($ipBase + ['event' => 'all'])) . '#raw-events">' . catalog_h($ipText) . '</a>'
                . (isset($blockedLookup[strtolower($ipText)]) ? ' <span class="dep missing">blocked</span>' : '') . '</td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($ipBase + ['event' => 'all'])) . '#raw-events">' . (int)$row['sessions'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($ipBase + ['event' => 'all'])) . '#raw-events">' . (int)$row['events'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($ipBase + ['event' => 'page_view'])) . '#raw-events">' . (int)$row['page_views'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($ipBase + ['event' => 'server_page'])) . '#raw-events">' . (int)$row['server_pages'] . '</a></td>'
                . '<td><a class="access-matrix-metric-link" href="access-matrix.php?' . catalog_h(access_matrix_query($ipBase + ['event' => 'interaction'])) . '#raw-events">' . (int)$row['interactions'] . '</a></td>'
                . '<td class="mono small">' . catalog_h(access_matrix_time($row['first_seen'])) . '</td><td class="mono small">' . catalog_h(access_matrix_time($row['last_seen'])) . '</td>'
                . '<td><a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['ip' => $ipText, 'p' => 1])) . '">View activity</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></details>';

    echo '<details class="ui-section access-matrix-collapsible"><summary><div class="ui-section__header"><div><h2>Site blocklist</h2><p>These addresses are redirected to the removal-request page and cannot browse the public site. Logged-in administrators are exempt.</p></div></div></summary><div class="ui-section__body">';
    if (!$siteBlocklist instanceof CatalogSiteBlocklist) {
        echo '<p class="muted">Site blocklist unavailable until migration.</p>';
    } else {
        echo '<form method="post" class="access-block-actions">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
            . '<input type="hidden" name="action" value="block_ip">'
            . '<label>IP <input name="ip_address" required placeholder="IPv4 or IPv6"></label>'
            . '<label class="grow">Reason <input name="note" maxlength="500" placeholder="Crawler, scraping, abuse, etc."></label>'
            . '<button type="submit">Block site access</button></form>';
        if ($blockedRows === []) {
            echo '<p class="muted">No IP addresses are blocked from the full site.</p>';
        } else {
            echo '<table><thead><tr><th>IP</th><th>Reason</th><th>Blocked</th><th></th></tr></thead><tbody>';
            foreach ($blockedRows as $blockedRow) {
                echo '<tr><td class="mono">' . catalog_h((string)$blockedRow['ip']) . '</td><td>' . catalog_h((string)$blockedRow['note']) . '</td>'
                    . '<td class="mono small">' . catalog_h(access_matrix_time($blockedRow['created_at'])) . '</td><td>'
                    . '<form method="post" onsubmit="return confirm(\'Restore site access for this IP?\')">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
                    . '<input type="hidden" name="action" value="unblock_ip"><input type="hidden" name="ip_address" value="' . catalog_h((string)$blockedRow['ip']) . '">'
                    . '<button class="secondary" type="submit">Unblock</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
    }
    echo '</div></details>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Block removal requests</h2></div></div><div class="ui-section__body">';
    if ($feedbackRows === []) {
        echo '<p class="muted">No removal requests.</p>';
    } else {
        echo '<table><thead><tr><th>Time</th><th>IP</th><th>Email</th><th>Request</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($feedbackRows as $feedback) {
            echo '<tr><td class="mono small">' . catalog_h(access_matrix_time($feedback['created_at'])) . '</td>'
                . '<td class="mono">' . catalog_h((string)$feedback['ip']) . '</td>'
                . '<td>' . catalog_h((string)($feedback['email'] ?? '')) . '</td>'
                . '<td style="max-width:420px;overflow-wrap:anywhere">' . nl2br(catalog_h((string)$feedback['message'])) . '</td>'
                . '<td>' . catalog_h((string)$feedback['status']) . '</td><td>';
            if ((string)$feedback['status'] === 'open') {
                echo '<form method="post" style="margin-bottom:6px">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
                    . '<input type="hidden" name="feedback_id" value="' . (int)$feedback['id'] . '">'
                    . '<input type="hidden" name="action" value="feedback_unblock">'
                    . '<input name="resolution_note" maxlength="500" placeholder="Optional note">'
                    . '<button type="submit">Approve + unblock</button></form>'
                    . '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
                    . '<input type="hidden" name="feedback_id" value="' . (int)$feedback['id'] . '">'
                    . '<input type="hidden" name="action" value="feedback_resolve">'
                    . '<button class="secondary" type="submit">Close request</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section" id="raw-events"><div class="ui-section__header"><div><h2>Raw events</h2><p>' . number_format($total) . ' matching event(s).</p></div></div><div class="ui-section__body">';
    if (!$eventsAvailable || $rows === []) {
        echo '<p class="muted">No matching access events.</p>';
    } else {
        echo '<form method="post" onsubmit="if(this.elements.action.value===\'delete_selected\'){return confirm(\'Permanently delete selected access events?\');}return true;">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
            . '<div class="access-matrix-actions"><label><input type="checkbox" onclick="document.querySelectorAll(\'.access-matrix-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<select name="action" required><option value="">Choose action</option><option value="delete_selected">Delete selected</option><option value="block_selected_ips">Block selected IPs from site</option></select>'
            . '<button type="submit">Apply</button></div>'
            . '<div class="table-wrap" data-world-map-source="access-matrix-raw-events" data-world-map-title="Raw event locations" data-world-map-storage-key="unrealdb.accessMatrix.rawEvents.worldMapOpen" data-world-map-note="Country-level approximation from the local GeoIP country database." data-world-map-entry-singular="visible raw event" data-world-map-entry-plural="visible raw events"><table class="access-matrix-table"><thead><tr><th class="am-check"></th><th class="am-time">Time</th><th class="am-type">Type</th><th class="am-page">Page / section / action</th><th class="am-ip">IP / user</th><th class="am-route">Referrer / target</th><th class="am-agent">User agent</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $ipText = trim((string)($row['ip_text'] ?? ''));
            $countryCode = strtoupper(trim((string)($row['map_country_code'] ?? '')));
            $countryName = trim((string)($row['map_country_name'] ?? ''));
            $mapAttributes = $ipText !== '' && preg_match('/^[A-Z]{2}$/', $countryCode) === 1
                ? ' data-world-map-ip="' . catalog_h($ipText) . '" data-world-map-country-code="' . catalog_h($countryCode)
                    . '" data-world-map-country-name="' . catalog_h($countryName !== '' ? $countryName : $countryCode) . '"'
                : '';
            echo '<tr' . $mapAttributes . '><td class="am-check"><input class="access-matrix-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td>'
                . '<td class="mono small am-time">' . access_matrix_time_html($row['occurred_at']) . '</td>'
                . '<td class="am-type"><span class="dep">' . catalog_h((string)$row['event_type']) . '</span></td>'
                . '<td class="am-page"><strong class="mono small">' . access_matrix_logged_link((string)$row['request_path'], (string)$row['page_key']) . '</strong>'
                . '<br><span class="mono small muted">' . access_matrix_logged_link((string)$row['request_path']) . '</span>';
            if ((string)($row['section_key'] ?? '') !== '') {
                echo '<br><span>Section: ' . access_matrix_logged_link(
                    (string)$row['request_path'],
                    (string)$row['section_key']
                ) . '</span>';
            }
            if ((string)($row['action_key'] ?? '') !== '') {
                $actionTarget = trim((string)($row['target_path'] ?? '')) !== ''
                    ? (string)$row['target_path']
                    : (string)$row['request_path'];
                echo '<br><span>Action: ' . access_matrix_logged_link(
                    $actionTarget,
                    (string)$row['action_key']
                ) . '</span>';
            }
            $sessionHex = trim((string)($row['session_hex'] ?? ''));
            $sessionLink = $sessionHex !== ''
                ? '<br><a class="small mono" href="access-matrix.php?' . catalog_h(access_matrix_query([
                    'session' => substr($sessionHex, 0, 16),
                    'ip' => null,
                    'page_q' => null,
                    'q' => null,
                    'event' => 'all',
                    'p' => 1,
                ])) . '">session ' . catalog_h(substr($sessionHex, 0, 12)) . '…</a>'
                : '';
            $isBlockedIp = $ipText !== '' && isset($blockedLookup[strtolower($ipText)]);
            $countryFlagHtml = preg_match('/^[A-Z]{2}$/', $countryCode) === 1
                ? '<img class="access-country-flag" src="country-flag.php?code=' . rawurlencode(strtolower($countryCode))
                    . '" alt="" title="' . catalog_h($countryCode) . '" loading="lazy" width="20" height="15"> '
                : '';
            echo '</td><td class="mono am-ip"><div class="access-matrix-ip-line"><span class="access-matrix-ip-value">'
                . $countryFlagHtml . catalog_h($ipText) . '</span>';
            if ($ipText !== '') {
                echo '<span class="access-matrix-ip-actions">';
                if (!$isBlockedIp) {
                    echo '<button class="ui-button ui-button--danger ui-button--sm" type="submit" name="block_event_id" value="' . (int)$row['id']
                        . '" formnovalidate title="Blacklist IP" aria-label="Blacklist ' . catalog_h($ipText)
                        . '" onclick="return confirm(\'Block ' . catalog_h($ipText) . ' from the entire site?\')">XX</button>';
                } else {
                    echo '<a class="ui-button ui-button--secondary ui-button--sm" href="site-blacklist.php?q=' . rawurlencode($ipText)
                        . '" title="View blacklist" aria-label="View blacklist for ' . catalog_h($ipText) . '">View</a>';
                }
                echo '</span>';
            }
            echo '</div>'
                . ($isBlockedIp ? '<span class="dep missing">site blocked</span>' : '')
                . ((string)($row['username'] ?? '') !== '' ? '<br><span class="small">' . catalog_h((string)$row['username']) . '</span>' : '')
                . $sessionLink;
            $userAgent = (string)$row['user_agent'];
            echo '</td><td class="mono small am-route">From: ' . access_matrix_logged_link((string)($row['referrer_path'] ?? ''))
                . '<br>To: ' . access_matrix_logged_link((string)($row['target_path'] ?? '')) . '</td>'
                . '<td class="am-agent small"><span class="access-matrix-agent-text" title="' . catalog_h($userAgent) . '">' . catalog_h($userAgent) . '</span></td></tr>';
        }
        echo '</tbody></table></div></form>';

        $previous = $page > 1 ? '<a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['p' => $page - 1])) . '">Previous</a>' : '';
        $next = $page < $pages ? '<a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['p' => $page + 1])) . '">Next</a>' : '';
        echo '<div class="access-matrix-pages"><span>' . $previous . '</span><span class="muted">Page ' . $page . ' of ' . $pages . '</span><span>' . $next . '</span></div>';
    }
    echo '<form method="post" style="margin-top:14px" onsubmit="var d=parseInt(this.elements.days.value||\'30\',10);return confirm(d===0?\'Delete ALL access telemetry? This cannot be undone.\':\'Delete access telemetry older than \'+d+\' day(s)?\');">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
        . '<input type="hidden" name="action" value="delete_older">'
        . '<label>Delete events older than <input type="number" name="days" value="30" min="0" max="3650" style="width:90px"> days</label> '
        . '<span class="small muted">Use 0 to delete all access telemetry.</span> '
        . '<button class="secondary" type="submit">Delete events</button></form>';
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB Access Matrix] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Access Matrix error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Access Matrix unavailable');
    catalog_foot();
}
