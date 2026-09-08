<?php
/**
 * Administrator management for the full-site IP blacklist and removal requests.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeoIpCountryResolver;
use UnrealDb\Catalog\Infrastructure\Security\CatalogSiteBlocklist;

function site_blacklist_time(mixed $value): string
{
    $value = trim((string)$value);
    return $value === '' ? '' : substr($value, 0, 19);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Site Blacklist')) {
        exit;
    }

    $available = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ("ue_site_blocked_ips","ue_site_block_feedback")'
    )->fetchColumn() === 2;

    $message = '';
    $blocklist = $available ? new CatalogSiteBlocklist($db, $config) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('site_blacklist_admin');
        if (!$blocklist instanceof CatalogSiteBlocklist) {
            throw new RuntimeException('Run the pending Access Matrix migration first.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $userId = max(0, (int)($_SESSION['user']['id'] ?? 0));

        if ($action === 'block') {
            $blocklist->block(
                (string)($_POST['ip_address'] ?? ''),
                $userId,
                (string)($_POST['note'] ?? '')
            );
            $message = 'IP address blocked from the entire site.';
        } elseif ($action === 'unblock') {
            $removed = $blocklist->unblock((string)($_POST['ip_address'] ?? ''));
            $message = $removed > 0
                ? 'IP address removed from the site blacklist.'
                : 'IP address was not present in the site blacklist.';
        } elseif (in_array($action, ['feedback_close', 'feedback_approve'], true)) {
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

            if ($action === 'feedback_approve') {
                $blocklist->unblock((string)$feedback['ip']);
            }

            $status = $action === 'feedback_approve' ? 'approved' : 'resolved';
            $statement = $db->prepare(
                'UPDATE ue_site_block_feedback SET status=?,resolution_note=?,resolved_at=CURRENT_TIMESTAMP(6) WHERE id=?'
            );
            $statement->execute([
                $status,
                mb_substr(trim((string)($_POST['resolution_note'] ?? '')), 0, 500, 'UTF-8'),
                $feedbackId,
            ]);
            $message = $action === 'feedback_approve'
                ? 'Removal request approved and IP unblocked.'
                : 'Removal request closed.';
        } else {
            throw new RuntimeException('Choose a valid blacklist action.');
        }
    }

    $filter = trim((string)($_GET['q'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    if (!in_array($status, ['all', 'open', 'approved', 'resolved'], true)) {
        $status = 'all';
    }

    $blockedRows = $blocklist instanceof CatalogSiteBlocklist ? $blocklist->all() : [];
    if ($filter !== '') {
        $needle = mb_strtolower($filter, 'UTF-8');
        $blockedRows = array_values(array_filter(
            $blockedRows,
            static function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower((string)$row['ip'], 'UTF-8'), $needle)
                    || str_contains(mb_strtolower((string)$row['note'], 'UTF-8'), $needle);
            }
        ));
    }

    $geoIpResolver = new CatalogGeoIpCountryResolver($db);
    foreach ($blockedRows as $index => $row) {
        $country = $geoIpResolver->resolve((string)($row['ip'] ?? ''));
        $blockedRows[$index]['map_country_code'] = $country['country_code'];
        $blockedRows[$index]['map_country_name'] = $country['country_name'];
    }

    $feedbackWhere = [];
    $feedbackArgs = [];
    if ($status !== 'all') {
        $feedbackWhere[] = 'status=?';
        $feedbackArgs[] = $status;
    }
    if ($filter !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter) . '%';
        $feedbackWhere[] = '(INET6_NTOA(ip_address) LIKE ? ESCAPE "\\\\" OR email LIKE ? ESCAPE "\\\\" OR message LIKE ? ESCAPE "\\\\")';
        array_push($feedbackArgs, $like, $like, $like);
    }
    $feedbackSql = $feedbackWhere !== [] ? ' WHERE ' . implode(' AND ', $feedbackWhere) : '';
    $feedbackRows = $available
        ? catalog_all(
            $db,
            'SELECT id,INET6_NTOA(ip_address) ip,email,message,status,created_at,resolved_at,resolution_note '
            . 'FROM ue_site_block_feedback' . $feedbackSql
            . ' ORDER BY (status="open") DESC,created_at DESC LIMIT 250',
            $feedbackArgs
        )
        : [];

    catalog_head('Site Blacklist');
    echo '<style>'
        . '.site-blacklist-toolbar,.site-blacklist-add{display:flex;gap:9px;align-items:end;flex-wrap:wrap;margin-bottom:14px}'
        . '.site-blacklist-toolbar .grow,.site-blacklist-add .grow{flex:1;min-width:260px}'
        . '.site-blacklist-table td,.site-blacklist-table th{vertical-align:top}'
        . '.site-blacklist-ip{width:1%;white-space:nowrap}'
        . '.site-blacklist-time{width:1%;white-space:nowrap}'
        . '.site-blacklist-actions{width:1%;white-space:nowrap}'
        . '.site-blacklist-message{max-width:520px;overflow-wrap:anywhere}'
        . '</style>';

    catalog_page_header(
        'Site Blacklist',
        'Full-site IP blocks. Blocked public visitors are redirected to the blacklist page and may submit a removal request.',
        [
            'Site Activity Logs' => 'access-matrix.php',
            'Logging Settings' => 'logging-settings.php',
            'Download Logs' => 'download-logs.php',
            'Public Access' => 'public-access-settings.php',
        ]
    );

    if ($message !== '') {
        echo CatalogUi::alert('success', $message);
    }
    if (!$available) {
        echo CatalogUi::alert(
            'warning',
            'Run migration 202609080002_access_matrix_site_blocklist before using full-site blocking.',
            'Migration required'
        );
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Blocked IP addresses</h2>'
        . '<p>These addresses cannot browse the public site. Logged-in administrators remain exempt.</p></div></div>'
        . '<div class="ui-section__body">';

    echo '<form method="post" class="site-blacklist-add">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('site_blacklist_admin')) . '">'
        . '<input type="hidden" name="action" value="block">'
        . '<label>IP <input name="ip_address" required placeholder="IPv4 or IPv6"></label>'
        . '<label class="grow">Reason <input name="note" maxlength="500" placeholder="Crawler, scraping, abuse, etc."></label>'
        . '<button type="submit">Block site access</button></form>';

    echo '<form method="get" class="site-blacklist-toolbar">'
        . '<label class="grow">Filter <input type="search" name="q" value="' . catalog_h($filter) . '" placeholder="Full or partial IP, reason or removal request"></label>'
        . '<label>Request status <select name="status">';
    foreach (['all' => 'All', 'open' => 'Open', 'approved' => 'Approved', 'resolved' => 'Resolved'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button></form>';

    if ($blockedRows === []) {
        echo '<p class="muted">No matching site-blocked IP addresses.</p>';
    } else {
        echo '<div class="table-wrap" data-world-map-source="site-blacklist" data-world-map-title="Blacklisted IP locations" data-world-map-storage-key="unrealdb.siteBlacklist.worldMapOpen" data-world-map-note="Country-level approximation from the local GeoIP country database." data-world-map-entry-singular="blocked IP" data-world-map-entry-plural="blocked IPs"><table class="site-blacklist-table"><thead><tr><th>IP</th><th>Reason</th><th>Blocked</th><th>Action</th></tr></thead><tbody>';
        foreach ($blockedRows as $row) {
            $ipText = trim((string)($row['ip'] ?? ''));
            $countryCode = strtoupper(trim((string)($row['map_country_code'] ?? '')));
            $countryName = trim((string)($row['map_country_name'] ?? ''));
            $mapAttributes = $ipText !== '' && preg_match('/^[A-Z]{2}$/', $countryCode) === 1
                ? ' data-world-map-ip="' . catalog_h($ipText) . '" data-world-map-country-code="' . catalog_h($countryCode)
                    . '" data-world-map-country-name="' . catalog_h($countryName !== '' ? $countryName : $countryCode) . '"'
                : '';
            echo '<tr' . $mapAttributes . '><td class="mono site-blacklist-ip">' . catalog_h((string)$row['ip']) . '</td>'
                . '<td>' . catalog_h((string)$row['note']) . '</td>'
                . '<td class="mono small site-blacklist-time">' . catalog_h(site_blacklist_time($row['created_at'])) . '</td>'
                . '<td class="site-blacklist-actions"><form method="post" onsubmit="return confirm(\'Restore site access for this IP?\')">'
                . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('site_blacklist_admin')) . '">'
                . '<input type="hidden" name="action" value="unblock">'
                . '<input type="hidden" name="ip_address" value="' . catalog_h((string)$row['ip']) . '">'
                . '<button class="secondary" type="submit">Unblock</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Removal requests</h2>'
        . '<p>Requests submitted by blocked visitors from blacklisted.php.</p></div></div><div class="ui-section__body">';
    if ($feedbackRows === []) {
        echo '<p class="muted">No matching removal requests.</p>';
    } else {
        echo '<div class="table-wrap"><table class="site-blacklist-table"><thead><tr><th>Time</th><th>IP</th><th>Email</th><th>Request</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($feedbackRows as $row) {
            echo '<tr><td class="mono small site-blacklist-time">' . catalog_h(site_blacklist_time($row['created_at'])) . '</td>'
                . '<td class="mono site-blacklist-ip">' . catalog_h((string)$row['ip']) . '</td>'
                . '<td>' . catalog_h((string)($row['email'] ?? '')) . '</td>'
                . '<td class="site-blacklist-message">' . nl2br(catalog_h((string)$row['message'])) . '</td>'
                . '<td>' . catalog_h((string)$row['status']) . '</td><td class="site-blacklist-actions">';
            if ((string)$row['status'] === 'open') {
                echo '<form method="post" style="margin-bottom:6px">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('site_blacklist_admin')) . '">'
                    . '<input type="hidden" name="action" value="feedback_approve">'
                    . '<input type="hidden" name="feedback_id" value="' . (int)$row['id'] . '">'
                    . '<input name="resolution_note" maxlength="500" placeholder="Optional note">'
                    . '<button type="submit">Approve + unblock</button></form>'
                    . '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('site_blacklist_admin')) . '">'
                    . '<input type="hidden" name="action" value="feedback_close">'
                    . '<input type="hidden" name="feedback_id" value="' . (int)$row['id'] . '">'
                    . '<button class="secondary" type="submit">Close</button></form>';
            } else {
                echo '<span class="muted small">' . catalog_h((string)($row['resolution_note'] ?? '')) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Site Blacklist error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'Site Blacklist unavailable');
    catalog_foot();
}
