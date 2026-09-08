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
            $days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
            $statement = $db->prepare(
                'DELETE FROM ue_access_events WHERE occurred_at<DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL ? DAY)'
            );
            $statement->execute([$days]);
            $message = $statement->rowCount() . ' access event(s) older than ' . $days . ' days deleted.';
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
    $topSections = [];
    $topActions = [];
    $transitions = [];
    $topIps = [];

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
            'SELECT a.*,INET6_NTOA(a.ip_address) ip_text,u.username '
            . 'FROM ue_access_events a LEFT JOIN ue_users u ON u.id=a.user_id'
            . $whereSql
            . ' ORDER BY a.occurred_at DESC,a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $topPages = catalog_all(
            $db,
            'SELECT a.page_key,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="page_view" GROUP BY a.page_key ORDER BY hits DESC,a.page_key LIMIT 20',
            $args
        );
        $topSections = catalog_all(
            $db,
            'SELECT a.page_key,a.section_key,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="section" AND a.section_key IS NOT NULL '
            . 'GROUP BY a.page_key,a.section_key ORDER BY hits DESC,a.page_key LIMIT 20',
            $args
        );
        $topActions = catalog_all(
            $db,
            'SELECT a.page_key,a.action_key,a.target_path,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="interaction" AND a.action_key IS NOT NULL '
            . 'GROUP BY a.page_key,a.action_key,a.target_path ORDER BY hits DESC,a.page_key LIMIT 20',
            $args
        );
        $transitions = catalog_all(
            $db,
            'SELECT a.referrer_path source_path,a.page_key destination,COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.event_type="page_view" AND a.referrer_path IS NOT NULL AND a.referrer_path<>"" '
            . 'GROUP BY a.referrer_path,a.page_key ORDER BY hits DESC LIMIT 30',
            $args
        );
        $topIps = catalog_all(
            $db,
            'SELECT INET6_NTOA(a.ip_address) ip,COUNT(*) events,'
            . 'SUM(a.event_type="page_view") page_views,'
            . 'SUM(a.event_type="server_page") server_pages,'
            . 'SUM(a.event_type="interaction") interactions,'
            . 'MIN(a.occurred_at) first_seen,MAX(a.occurred_at) last_seen '
            . 'FROM ue_access_events a'
            . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
            . 'a.ip_address IS NOT NULL GROUP BY a.ip_address ORDER BY events DESC LIMIT 30',
            $args
        );
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
        . '.access-matrix-table{min-width:1180px}'
        . '.access-matrix-actions,.access-block-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}'
        . '.access-block-actions .grow{flex:1;min-width:240px}'
        . '.access-matrix-agent{max-width:320px;overflow-wrap:anywhere}'
        . '.access-matrix-pages{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:12px}'
        . '@media(max-width:1000px){.access-matrix-grid{grid-template-columns:1fr}.access-matrix-stats{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'Access Matrix',
        'First-party page, section and interaction telemetry for understanding busy parts of the site, visitor navigation and crawler-like activity.',
        ['Download Logs' => 'download-logs.php', 'Public Access' => 'public-access-settings.php']
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
            echo '<tr><td class="mono">' . catalog_h((string)$row['page_key']) . '</td><td>' . (int)$row['hits'] . '</td><td>' . (int)$row['unique_ips'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Navigation matrix</h2><p>Referring page → destination page.</p></div></div><div class="ui-section__body">';
    if ($transitions === []) echo '<p class="muted">No same-site navigation transitions recorded.</p>';
    else {
        echo '<table><thead><tr><th>From</th><th>To</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($transitions as $row) {
            echo '<tr><td class="mono small">' . catalog_h((string)$row['source_path']) . '</td><td class="mono small">' . catalog_h((string)$row['destination']) . '</td><td>' . (int)$row['hits'] . '</td><td>' . (int)$row['unique_ips'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Busiest sections</h2></div></div><div class="ui-section__body">';
    if ($topSections === []) echo '<p class="muted">No section-view data.</p>';
    else {
        echo '<table><thead><tr><th>Page</th><th>Section</th><th>Hits</th><th>IPs</th></tr></thead><tbody>';
        foreach ($topSections as $row) {
            echo '<tr><td class="mono small">' . catalog_h((string)$row['page_key']) . '</td><td>' . catalog_h((string)$row['section_key']) . '</td><td>' . (int)$row['hits'] . '</td><td>' . (int)$row['unique_ips'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Top interactions</h2></div></div><div class="ui-section__body">';
    if ($topActions === []) echo '<p class="muted">No interaction data.</p>';
    else {
        echo '<table><thead><tr><th>Page</th><th>Action</th><th>Target</th><th>Hits</th></tr></thead><tbody>';
        foreach ($topActions as $row) {
            echo '<tr><td class="mono small">' . catalog_h((string)$row['page_key']) . '</td><td>' . catalog_h((string)$row['action_key']) . '</td><td class="mono small">' . catalog_h((string)($row['target_path'] ?? '')) . '</td><td>' . (int)$row['hits'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Most active IPs</h2><p>Useful for spotting crawler-like navigation before blocking an address.</p></div></div><div class="ui-section__body">';
    if ($topIps === []) echo '<p class="muted">No IP activity.</p>';
    else {
        echo '<table><thead><tr><th>IP</th><th>Events</th><th>Browser pages</th><th>Server renders</th><th>Clicks</th><th>First</th><th>Last</th><th></th></tr></thead><tbody>';
        foreach ($topIps as $row) {
            $ipText = (string)$row['ip'];
            echo '<tr><td class="mono">' . catalog_h($ipText)
                . (isset($blockedLookup[strtolower($ipText)]) ? ' <span class="dep missing">blocked</span>' : '') . '</td>'
                . '<td>' . (int)$row['events'] . '</td><td>' . (int)$row['page_views'] . '</td><td>' . (int)$row['server_pages'] . '</td><td>' . (int)$row['interactions'] . '</td>'
                . '<td class="mono small">' . catalog_h((string)$row['first_seen']) . '</td><td class="mono small">' . catalog_h((string)$row['last_seen']) . '</td>'
                . '<td><a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['ip' => $ipText, 'p' => 1])) . '">View activity</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Site blocklist</h2><p>These addresses are redirected to the removal-request page and cannot browse the public site. Logged-in administrators are exempt.</p></div></div><div class="ui-section__body">';
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
                    . '<td class="mono small">' . catalog_h((string)$blockedRow['created_at']) . '</td><td>'
                    . '<form method="post" onsubmit="return confirm(\'Restore site access for this IP?\')">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
                    . '<input type="hidden" name="action" value="unblock_ip"><input type="hidden" name="ip_address" value="' . catalog_h((string)$blockedRow['ip']) . '">'
                    . '<button class="secondary" type="submit">Unblock</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Block removal requests</h2></div></div><div class="ui-section__body">';
    if ($feedbackRows === []) {
        echo '<p class="muted">No removal requests.</p>';
    } else {
        echo '<table><thead><tr><th>Time</th><th>IP</th><th>Email</th><th>Request</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($feedbackRows as $feedback) {
            echo '<tr><td class="mono small">' . catalog_h((string)$feedback['created_at']) . '</td>'
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

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Raw events</h2><p>' . number_format($total) . ' matching event(s).</p></div></div><div class="ui-section__body">';
    if (!$eventsAvailable || $rows === []) {
        echo '<p class="muted">No matching access events.</p>';
    } else {
        echo '<form method="post" onsubmit="if(this.elements.action.value===\'delete_selected\'){return confirm(\'Permanently delete selected access events?\');}return true;">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
            . '<div class="access-matrix-actions"><label><input type="checkbox" onclick="document.querySelectorAll(\'.access-matrix-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<select name="action" required><option value="">Choose action</option><option value="delete_selected">Delete selected</option><option value="block_selected_ips">Block selected IPs from site</option></select>'
            . '<button type="submit">Apply</button></div>'
            . '<div class="table-wrap"><table class="access-matrix-table"><thead><tr><th></th><th>Time</th><th>Type</th><th>Page / section / action</th><th>IP / user</th><th>Referrer / target</th><th>User agent</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $ipText = trim((string)($row['ip_text'] ?? ''));
            echo '<tr><td><input class="access-matrix-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td>'
                . '<td class="mono small">' . catalog_h((string)$row['occurred_at']) . '</td>'
                . '<td><span class="dep">' . catalog_h((string)$row['event_type']) . '</span></td>'
                . '<td><strong class="mono small">' . catalog_h((string)$row['page_key']) . '</strong>'
                . '<br><span class="mono small muted">' . catalog_h((string)$row['request_path']) . '</span>';
            if ((string)($row['section_key'] ?? '') !== '') echo '<br><span>Section: ' . catalog_h((string)$row['section_key']) . '</span>';
            if ((string)($row['action_key'] ?? '') !== '') echo '<br><span>Action: ' . catalog_h((string)$row['action_key']) . '</span>';
            echo '</td><td class="mono">' . catalog_h($ipText)
                . (isset($blockedLookup[strtolower($ipText)]) ? '<br><span class="dep missing">site blocked</span>' : '')
                . ((string)($row['username'] ?? '') !== '' ? '<br><span class="small">' . catalog_h((string)$row['username']) . '</span>' : '')
                . '</td><td class="mono small">From: ' . catalog_h((string)($row['referrer_path'] ?? ''))
                . '<br>To: ' . catalog_h((string)($row['target_path'] ?? '')) . '</td>'
                . '<td class="access-matrix-agent small">' . catalog_h((string)$row['user_agent']) . '</td></tr>';
        }
        echo '</tbody></table></div></form>';

        $previous = $page > 1 ? '<a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['p' => $page - 1])) . '">Previous</a>' : '';
        $next = $page < $pages ? '<a class="button secondary" href="access-matrix.php?' . catalog_h(access_matrix_query(['p' => $page + 1])) . '">Next</a>' : '';
        echo '<div class="access-matrix-pages"><span>' . $previous . '</span><span class="muted">Page ' . $page . ' of ' . $pages . '</span><span>' . $next . '</span></div>';
    }
    echo '<form method="post" style="margin-top:14px" onsubmit="return confirm(\'Delete old access telemetry?\')">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('access_matrix_admin')) . '">'
        . '<input type="hidden" name="action" value="delete_older">'
        . '<label>Delete events older than <input type="number" name="days" value="30" min="1" max="3650" style="width:90px"> days</label> '
        . '<button class="secondary" type="submit">Delete old events</button></form>';
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
