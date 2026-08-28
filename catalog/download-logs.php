<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Download Logs.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Security\CatalogTransferBlocklist;

function download_logs_choice(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function download_logs_search(string $value): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > 200 ? mb_substr($value, 0, 200, 'UTF-8') : $value;
}

function download_logs_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return http_build_query($query);
}

function download_logs_country_flag(string $countryCode): string
{
    $countryCode = strtoupper(trim($countryCode));
    if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1 || !function_exists('mb_chr')) {
        return $countryCode;
    }
    return mb_chr(127397 + ord($countryCode[0]), 'UTF-8')
        . mb_chr(127397 + ord($countryCode[1]), 'UTF-8');
}

function download_logs_sort_heading(
    string $label,
    string $key,
    string $sort,
    string $direction,
    bool $enabled = true
): string {
    if (!$enabled) {
        return catalog_h($label);
    }
    $active = $sort === $key;
    $nextDirection = $active && $direction === 'asc' ? 'desc' : 'asc';
    $indicator = $active ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="download-logs.php?'
        . catalog_h(download_logs_query(['sort' => $key, 'dir' => $nextDirection, 'p' => 1]))
        . '">' . catalog_h($label . $indicator) . '</a>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Download Logs')) {
        exit;
    }

    $tables = [];
    foreach (['ue_download_audit', 'ue_generated_package_audit'] as $table) {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $statement->execute([$table]);
        $tables[$table] = (int)$statement->fetchColumn() === 1;
    }
    $available = $tables['ue_download_audit'] && $tables['ue_generated_package_audit'];
    $blocklistAvailable = false;
    $blocklistTable = $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_transfer_blocked_ips"'
    );
    $blocklistAvailable = (int)$blocklistTable->fetchColumn() === 1;
    $blocklist = $blocklistAvailable ? new CatalogTransferBlocklist($db) : null;
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('download_logs_admin');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $logView = download_logs_choice((string)($_POST['log_view'] ?? 'downloads'), ['downloads', 'generations'], 'downloads');
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        if (count($ids) > 1000) {
            throw new RuntimeException('Select no more than 1,000 log records at once.');
        }

        if ($action === 'delete_selected') {
            if (!$available || $ids === []) {
                throw new RuntimeException('Select one or more log records to delete.');
            }
            $table = $logView === 'generations' ? 'ue_generated_package_audit' : 'ue_download_audit';
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
            $statement->execute($ids);
            $message = $statement->rowCount() . ' selected log record(s) permanently deleted.';
        } elseif (in_array($action, ['block_selected_ips', 'unblock_selected_ips'], true)) {
            if (!$blocklistAvailable || !$blocklist instanceof CatalogTransferBlocklist) {
                throw new RuntimeException('Run the pending database migration before managing blocked IPs.');
            }
            if (!$available || $ids === []) {
                throw new RuntimeException('Select one or more log records first.');
            }
            $table = $logView === 'generations' ? 'ue_generated_package_audit' : 'ue_download_audit';
            $ipColumn = $logView === 'generations' ? 'request_ip' : 'ip_address';
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare(
                'SELECT DISTINCT INET6_NTOA(' . $ipColumn . ') ip FROM ' . $table
                . ' WHERE id IN (' . $placeholders . ') AND ' . $ipColumn . ' IS NOT NULL'
            );
            $statement->execute($ids);
            $ips = array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            foreach ($ips as $selectedIp) {
                if ($action === 'block_selected_ips') {
                    $blocklist->block($selectedIp, (int)($_SESSION['user']['id'] ?? 0), 'Blocked from Download Logs.');
                } else {
                    $blocklist->unblock($selectedIp);
                }
            }
            $message = count($ips) . ' IP address(es) ' . ($action === 'block_selected_ips' ? 'blocked from transfers.' : 'removed from the transfer blocklist.');
        } elseif ($action === 'block_ip') {
            if (!$blocklistAvailable || !$blocklist instanceof CatalogTransferBlocklist) {
                throw new RuntimeException('Run the pending database migration before managing blocked IPs.');
            }
            $blocklist->block(
                (string)($_POST['ip_address'] ?? ''),
                (int)($_SESSION['user']['id'] ?? 0),
                (string)($_POST['note'] ?? '')
            );
            $message = 'IP address added to the transfer blocklist.';
        } elseif ($action === 'unblock_ip') {
            if (!$blocklistAvailable || !$blocklist instanceof CatalogTransferBlocklist) {
                throw new RuntimeException('Run the pending database migration before managing blocked IPs.');
            }
            $removed = $blocklist->unblock((string)($_POST['ip_address'] ?? ''));
            $message = $removed > 0 ? 'IP address removed from the transfer blocklist.' : 'IP address was not blocked.';
        } else {
            throw new RuntimeException('Choose a valid Download Logs action.');
        }
    }
    $countryAvailable = false;
    if ($available) {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() '
            . 'AND TABLE_NAME IN ("ue_download_audit","ue_generated_package_audit") '
            . 'AND COLUMN_NAME IN ("country_code","country_name")'
        );
        $statement->execute();
        $countryAvailable = (int)$statement->fetchColumn() === 4;
    }

    $view = download_logs_choice((string)($_GET['view'] ?? 'downloads'), ['downloads', 'generations'], 'downloads');
    $status = download_logs_choice(
        (string)($_GET['status'] ?? 'all'),
        ['all', 'started', 'completed', 'interrupted', 'failed', 'queued', 'running', 'cancelled'],
        'all'
    );
    $type = download_logs_choice(
        (string)($_GET['type'] ?? 'all'),
        ['all', 'individual_file', 'generated_package'],
        'all'
    );
    $sort = download_logs_choice((string)($_GET['sort'] ?? 'time'), ['time', 'country'], 'time');
    $direction = download_logs_choice((string)($_GET['dir'] ?? 'desc'), ['asc', 'desc'], 'desc');
    if (!$countryAvailable && $sort === 'country') {
        $sort = 'time';
    }
    $gameId = max(0, (int)($_GET['game_id'] ?? 0));
    $ip = trim((string)($_GET['ip'] ?? ''));
    $search = download_logs_search((string)($_GET['q'] ?? ''));
    $perPage = (int)($_GET['per_page'] ?? 100);
    if (!in_array($perPage, [50, 100, 250, 500], true)) {
        $perPage = 100;
    }
    $page = max(1, (int)($_GET['p'] ?? 1));

    $games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name,id');
    $summary = [
        'downloads' => 0,
        'completed' => 0,
        'problem' => 0,
        'bytes' => 0,
        'generations' => 0,
    ];
    $rows = [];
    $total = 0;
    $pages = 1;
    $blockedRows = $blocklist instanceof CatalogTransferBlocklist ? $blocklist->all() : [];
    $blockedLookup = [];
    foreach ($blockedRows as $blockedRow) {
        $blockedLookup[strtolower((string)$blockedRow['ip'])] = true;
    }

    if ($available) {
        $downloadSummary = catalog_one(
            $db,
            'SELECT COUNT(*) downloads,'
            . 'SUM(status="completed") completed,'
            . 'SUM(status IN ("interrupted","failed")) problem,'
            . 'COALESCE(SUM(bytes_sent),0) bytes '
            . 'FROM ue_download_audit'
        ) ?: [];
        $generationSummary = catalog_one(
            $db,
            'SELECT COUNT(*) generations FROM ue_generated_package_audit'
        ) ?: [];
        $summary = [
            'downloads' => (int)($downloadSummary['downloads'] ?? 0),
            'completed' => (int)($downloadSummary['completed'] ?? 0),
            'problem' => (int)($downloadSummary['problem'] ?? 0),
            'bytes' => (int)($downloadSummary['bytes'] ?? 0),
            'generations' => (int)($generationSummary['generations'] ?? 0),
        ];

        $where = [];
        $args = [];
        if ($status !== 'all') {
            $where[] = 'a.status=?';
            $args[] = $status;
        }
        if ($gameId > 0) {
            $where[] = 'a.game_id=?';
            $args[] = $gameId;
        }
        if ($ip !== '') {
            $packed = @inet_pton($ip);
            if (!is_string($packed)) {
                throw new RuntimeException('Enter a valid IPv4 or IPv6 address.');
            }
            $where[] = ($view === 'downloads' ? 'a.ip_address' : 'a.request_ip') . '=?';
            $args[] = $packed;
        }

        if ($view === 'downloads') {
            if ($type !== 'all') {
                $where[] = 'a.download_type=?';
                $args[] = $type;
            }
            if ($search !== '') {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $searchColumns = [
                    'a.download_name LIKE ?',
                    'a.package_format LIKE ?',
                    'a.user_agent LIKE ?',
                    'a.error_message LIKE ?',
                ];
                array_push($args, $like, $like, $like, $like);
                if ($countryAvailable) {
                    $searchColumns[] = 'a.country_name LIKE ?';
                    $searchColumns[] = 'a.country_code LIKE ?';
                    array_push($args, $like, $like);
                }
                $where[] = '(' . implode(' OR ', $searchColumns) . ')';
            }
            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $total = catalog_count($db, 'SELECT COUNT(*) c FROM ue_download_audit a' . $whereSql, $args);
            $pages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;
            $orderSql = $sort === 'country'
                ? ' ORDER BY (a.country_name IS NULL OR a.country_name="") ASC,a.country_name ' . strtoupper($direction) . ',a.started_at DESC,a.id DESC'
                : ' ORDER BY a.started_at ' . strtoupper($direction) . ',a.id ' . strtoupper($direction);
            $statement = $db->prepare(
                'SELECT a.*,INET6_NTOA(a.ip_address) ip_text,g.name game_name,f.original_name file_name '
                . 'FROM ue_download_audit a '
                . 'LEFT JOIN ue_games g ON g.id=a.game_id '
                . 'LEFT JOIN ue_files f ON f.id=a.file_id '
                . $whereSql
                . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
        } else {
            if ($search !== '') {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $searchColumns = [
                    'a.package_name LIKE ?',
                    'a.package_format LIKE ?',
                    'a.user_agent LIKE ?',
                    'a.error_message LIKE ?',
                    'a.artifact_name LIKE ?',
                ];
                array_push($args, $like, $like, $like, $like, $like);
                if ($countryAvailable) {
                    $searchColumns[] = 'a.country_name LIKE ?';
                    $searchColumns[] = 'a.country_code LIKE ?';
                    array_push($args, $like, $like);
                }
                $where[] = '(' . implode(' OR ', $searchColumns) . ')';
            }
            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $total = catalog_count($db, 'SELECT COUNT(*) c FROM ue_generated_package_audit a' . $whereSql, $args);
            $pages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;
            $orderSql = $sort === 'country'
                ? ' ORDER BY (a.country_name IS NULL OR a.country_name="") ASC,a.country_name ' . strtoupper($direction) . ',a.queued_at DESC,a.id DESC'
                : ' ORDER BY a.queued_at ' . strtoupper($direction) . ',a.id ' . strtoupper($direction);
            $statement = $db->prepare(
                'SELECT a.*,INET6_NTOA(a.request_ip) ip_text,g.name game_name,f.original_name file_name '
                . 'FROM ue_generated_package_audit a '
                . 'LEFT JOIN ue_games g ON g.id=a.game_id '
                . 'LEFT JOIN ue_files f ON f.id=a.file_id '
                . $whereSql
                . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
        }
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    catalog_head('Download Logs');
    echo '<style>'
        . '.download-log-cards{grid-template-columns:repeat(5,minmax(130px,1fr));margin-bottom:14px}'
        . '.download-log-tabs,.download-log-toolbar,.download-log-pages,.download-log-actions,.download-block-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.download-log-tabs,.download-log-toolbar,.download-log-actions{margin-bottom:12px}'
        . '.download-log-toolbar .search{min-width:280px;flex:1}'
        . '.download-log-table{min-width:1320px}'
        . '.download-log-select{width:42px;text-align:center}'
        . '.download-blocklist{margin:14px 0}'
        . '.download-blocklist table{min-width:760px}'
        . '.download-block-actions .grow{flex:1;min-width:260px}'
        . '.download-log-pill{display:inline-block;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700}'
        . '.download-log-pill-completed{color:#a7f3d0;border-color:rgba(50,213,131,.75)}'
        . '.download-log-pill-failed,.download-log-pill-interrupted,.download-log-pill-cancelled{color:#fecdd3;border-color:rgba(255,107,122,.75)}'
        . '.download-log-pill-started,.download-log-pill-running,.download-log-pill-queued{color:#bfdbfe;border-color:rgba(96,165,250,.75)}'
        . '.download-log-pages{justify-content:space-between;margin-top:12px}'
        . '.download-log-agent,.download-log-error{max-width:360px;overflow-wrap:anywhere}'
        . '.download-country{text-align:center;white-space:nowrap}'
        . '.download-country-flag{font-size:1.35rem;line-height:1;cursor:help}'
        . '.download-country-empty{color:var(--muted)}'
        . '@media(max-width:1000px){.download-log-cards{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'Download Logs',
        'Administrator reporting records for generated package requests and actual individual/generated-package transfers. IP addresses and their GeoIP country snapshot are stored with each audit entry; this page does not perform GeoIP lookups while rendering logs.',
        [
            'Download Administration' => 'download-admin.php',
            'Package Settings' => 'download-package-settings.php',
            'Download Settings' => 'downloads-settings.php',
        ]
    );

    if ($message !== '') {
        echo CatalogUi::alert('success', $message);
    }

    if (!$available) {
        echo CatalogUi::alert(
            'warning',
            'Download audit storage is not installed. Run php catalog/bin/migrate.php migrate followed by php catalog/bin/migrate.php verify.',
            'Database migration required'
        );
    } elseif (!$countryAvailable) {
        echo CatalogUi::alert(
            'warning',
            'Country audit columns are not installed yet. Run php catalog/bin/migrate.php migrate followed by php catalog/bin/migrate.php verify.',
            'GeoIP migration required'
        );
    }

    echo '<div class="grid download-log-cards">';
    catalog_stat_card('Download attempts', $summary['downloads']);
    catalog_stat_card('Completed', $summary['completed']);
    catalog_stat_card('Interrupted / failed', $summary['problem']);
    catalog_stat_card('Bytes sent', catalog_bytes($summary['bytes']));
    catalog_stat_card('Package generations', $summary['generations']);
    echo '</div>';

    echo '<section class="ui-section download-blocklist"><div class="ui-section__header"><div><h2>Blocked transfer IPs</h2>'
        . '<p>Blocked addresses can still browse the website. Only download and upload transfers are denied.</p></div></div>'
        . '<div class="ui-section__body">';
    if (!$blocklistAvailable) {
        echo CatalogUi::alert('warning', 'Run the pending database migration to enable the transfer blocklist.', 'Blocked IP storage unavailable');
    } else {
        echo '<form method="post" class="download-block-actions">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('download_logs_admin')) . '">'
            . '<input type="hidden" name="action" value="block_ip">'
            . '<label>IP <input name="ip_address" required placeholder="IPv4 or IPv6"></label>'
            . '<label class="grow">Note <input name="note" maxlength="500" placeholder="Optional reason"></label>'
            . '<button type="submit">Block transfers</button></form>';
        if ($blockedRows === []) {
            echo '<p class="muted">No IP addresses are currently blocked from transfers.</p>';
        } else {
            echo '<div class="table-wrap"><table><thead><tr><th>IP</th><th>Note</th><th>Blocked</th><th>Action</th></tr></thead><tbody>';
            foreach ($blockedRows as $blockedRow) {
                echo '<tr><td class="mono">' . catalog_h((string)$blockedRow['ip']) . '</td>'
                    . '<td>' . catalog_h((string)$blockedRow['note']) . '</td>'
                    . '<td class="mono small">' . catalog_h((string)$blockedRow['created_at']) . '</td>'
                    . '<td><form method="post" onsubmit="return confirm(\'Remove this IP from the transfer blocklist?\')">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('download_logs_admin')) . '">'
                    . '<input type="hidden" name="action" value="unblock_ip">'
                    . '<input type="hidden" name="ip_address" value="' . catalog_h((string)$blockedRow['ip']) . '">'
                    . '<button class="secondary" type="submit">Unblock</button></form></td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }
    echo '</div></section>';

    echo '<div class="download-log-tabs">'
        . '<a class="button' . ($view === 'downloads' ? ' primary' : '') . '" href="download-logs.php?' . catalog_h(download_logs_query(['view' => 'downloads', 'p' => 1, 'status' => 'all'])) . '">Downloads</a>'
        . '<a class="button' . ($view === 'generations' ? ' primary' : '') . '" href="download-logs.php?' . catalog_h(download_logs_query(['view' => 'generations', 'p' => 1, 'status' => 'all', 'type' => null])) . '">Package generations</a>'
        . '</div>';

    echo '<form method="get" class="download-log-toolbar">'
        . '<input type="hidden" name="view" value="' . catalog_h($view) . '">'
        . '<input type="hidden" name="sort" value="' . catalog_h($sort) . '">'
        . '<input type="hidden" name="dir" value="' . catalog_h($direction) . '">';
    if ($view === 'downloads') {
        echo '<label>Type <select name="type">';
        foreach (['all' => 'All', 'individual_file' => 'Individual file', 'generated_package' => 'Generated package'] as $value => $label) {
            echo '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<label>Status <select name="status"><option value="all">All</option>';
    $statuses = $view === 'downloads'
        ? ['started', 'completed', 'interrupted', 'failed']
        : ['queued', 'running', 'completed', 'failed', 'cancelled'];
    foreach ($statuses as $value) {
        echo '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . catalog_h(ucfirst($value)) . '</option>';
    }
    echo '</select></label><label>Game <select name="game_id"><option value="0">All games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>'
        . '<label>IP <input name="ip" value="' . catalog_h($ip) . '" placeholder="Exact IPv4 or IPv6"></label>'
        . '<label class="search">Search <input type="search" name="q" value="' . catalog_h($search) . '" placeholder="File, package, country, format, error or user agent"></label>'
        . '<label>Rows <select name="per_page">';
    foreach ([50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($perPage === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button></form>';

    if (!$available || !$rows) {
        echo CatalogUi::emptyState(
            'No matching log records',
            $available ? 'No download activity matches the selected filters.' : 'Apply migration 202607310007 to begin recording activity.'
        );
    } else {
        echo '<form method="post" class="download-log-bulk-form" onsubmit="if(this.elements.action.value===\'delete_selected\'){return confirm(\'Permanently delete the selected log records?\');}return true;">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('download_logs_admin')) . '">'
            . '<input type="hidden" name="log_view" value="' . catalog_h($view) . '">'
            . '<div class="download-log-actions">'
            . '<label><input type="checkbox" onclick="document.querySelectorAll(\'.download-log-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<select name="action" required><option value="">Choose action</option>'
            . '<option value="delete_selected">Delete selected logs</option>'
            . '<option value="block_selected_ips">Block selected IPs</option>'
            . '<option value="unblock_selected_ips">Unblock selected IPs</option></select>'
            . '<button type="submit">Apply to selected</button></div>';

        if ($view === 'downloads') {
        echo '<div class="table-wrap"><table class="download-log-table"><thead><tr>'
            . '<th class="download-log-select"></th><th>' . download_logs_sort_heading('Started', 'time', $sort, $direction) . '</th><th>Status</th><th>File / package</th><th>Game</th><th>IP</th>'
            . '<th class="download-country">' . download_logs_sort_heading('Country', 'country', $sort, $direction, $countryAvailable) . '</th>'
            . '<th>Transferred</th><th>Job</th><th>User agent / error</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $rowStatus = strtolower((string)$row['status']);
            $requested = isset($row['bytes_requested']) ? (int)$row['bytes_requested'] : 0;
            $sent = (int)$row['bytes_sent'];
            $countryCode = strtoupper(trim((string)($row['country_code'] ?? '')));
            $countryName = trim((string)($row['country_name'] ?? ''));
            $ipText = trim((string)($row['ip_text'] ?? ''));
            echo '<tr><td class="download-log-select"><input class="download-log-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td><td class="mono small">' . catalog_h((string)$row['started_at']) . '</td>';
            echo '<td><span class="download-log-pill download-log-pill-' . catalog_h($rowStatus) . '">' . catalog_h($rowStatus) . '</span></td>';
            echo '<td><strong>' . catalog_h((string)$row['download_name']) . '</strong>';
            if ((int)($row['file_id'] ?? 0) > 0) {
                echo '<br><a class="small" href="file-info.php?id=' . (int)$row['file_id'] . '">File #' . (int)$row['file_id'] . '</a>';
            }
            if ((string)($row['package_format'] ?? '') !== '') {
                echo '<br><span class="mono small muted">' . catalog_h((string)$row['package_format']) . '</span>';
            }
            echo '</td><td>' . catalog_h((string)($row['game_name'] ?? '')) . '</td>';
            echo '<td class="mono">' . catalog_h($ipText)
                . ($ipText !== '' && isset($blockedLookup[strtolower($ipText)]) ? '<br><span class="dep missing">blocked</span>' : '')
                . '</td>';
            echo '<td class="download-country">';
            if ($countryCode !== '' && $countryName !== '') {
                echo '<span class="download-country-flag" role="img" aria-label="' . catalog_h($countryName) . '" title="' . catalog_h($countryName) . '">'
                    . catalog_h(download_logs_country_flag($countryCode)) . '</span>';
            } else {
                echo '<span class="download-country-empty" title="Country not recorded">—</span>';
            }
            echo '</td>';
            echo '<td>' . catalog_h(catalog_bytes($sent)) . ' / ' . catalog_h(catalog_bytes($requested)) . '</td>';
            echo '<td>' . ((int)($row['job_id'] ?? 0) > 0 ? '<a href="background-jobs.php?q=' . (int)$row['job_id'] . '">#' . (int)$row['job_id'] . '</a>' : '—') . '</td>';
            echo '<td class="download-log-agent"><span class="small">' . catalog_h((string)$row['user_agent']) . '</span>';
            if ((string)($row['error_message'] ?? '') !== '') {
                echo '<br><span class="download-log-error dep missing">' . catalog_h((string)$row['error_message']) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        } else {
        echo '<div class="table-wrap"><table class="download-log-table"><thead><tr>'
            . '<th class="download-log-select"></th><th>' . download_logs_sort_heading('Queued', 'time', $sort, $direction) . '</th><th>Status</th><th>Package</th><th>Version</th><th>Format</th><th>Game / file</th>'
            . '<th>IP</th><th class="download-country">' . download_logs_sort_heading('Country', 'country', $sort, $direction, $countryAvailable) . '</th>'
            . '<th>Artifact</th><th>Job</th><th>User agent / error</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $rowStatus = strtolower((string)$row['status']);
            $countryCode = strtoupper(trim((string)($row['country_code'] ?? '')));
            $countryName = trim((string)($row['country_name'] ?? ''));
            $ipText = trim((string)($row['ip_text'] ?? ''));
            echo '<tr><td class="download-log-select"><input class="download-log-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td><td class="mono small">' . catalog_h((string)$row['queued_at']) . '</td>';
            echo '<td><span class="download-log-pill download-log-pill-' . catalog_h($rowStatus) . '">' . catalog_h($rowStatus) . '</span></td>';
            echo '<td><strong>' . catalog_h((string)$row['package_name']) . '</strong><br><span class="small muted">Dependencies: ' . (!empty($row['include_dependencies']) ? 'yes' : 'no') . '</span></td>';
            echo '<td class="mono">' . catalog_h((string)$row['package_version']) . '</td>';
            echo '<td class="mono">' . catalog_h((string)$row['package_format']) . '</td>';
            echo '<td>' . catalog_h((string)($row['game_name'] ?? '')) . '<br><a class="small" href="file-info.php?id=' . (int)$row['file_id'] . '">File #' . (int)$row['file_id'] . '</a></td>';
            echo '<td class="mono">' . catalog_h($ipText)
                . ($ipText !== '' && isset($blockedLookup[strtolower($ipText)]) ? '<br><span class="dep missing">blocked</span>' : '')
                . '</td>';
            echo '<td class="download-country">';
            if ($countryCode !== '' && $countryName !== '') {
                echo '<span class="download-country-flag" role="img" aria-label="' . catalog_h($countryName) . '" title="' . catalog_h($countryName) . '">'
                    . catalog_h(download_logs_country_flag($countryCode)) . '</span>';
            } else {
                echo '<span class="download-country-empty" title="Country not recorded">—</span>';
            }
            echo '</td>';
            echo '<td>' . catalog_h((string)($row['artifact_name'] ?? ''));
            if (isset($row['artifact_size'])) {
                echo '<br><span class="small muted">' . catalog_h(catalog_bytes((int)$row['artifact_size'])) . '</span>';
            }
            echo '</td><td><a href="background-jobs.php?q=' . (int)$row['job_id'] . '">#' . (int)$row['job_id'] . '</a></td>';
            echo '<td class="download-log-agent"><span class="small">' . catalog_h((string)$row['user_agent']) . '</span>';
            if ((string)($row['error_message'] ?? '') !== '') {
                echo '<br><span class="download-log-error dep missing">' . catalog_h((string)$row['error_message']) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        }
        echo '</form>';
    }

    if ($available && $total > 0) {
        echo '<div class="download-log-pages"><span>Showing ' . (($page - 1) * $perPage + 1) . '–' . min($total, $page * $perPage) . ' of ' . $total . ' records.</span><span>';
        if ($page > 1) {
            echo '<a class="button" href="download-logs.php?' . catalog_h(download_logs_query(['p' => $page - 1])) . '">Previous</a> ';
        }
        echo 'Page ' . $page . ' of ' . $pages;
        if ($page < $pages) {
            echo ' <a class="button" href="download-logs.php?' . catalog_h(download_logs_query(['p' => $page + 1])) . '">Next</a>';
        }
        echo '</span></div>';
    }

    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Download Logs error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'Download Logs unavailable');
    echo '<p><a class="button" href="download-admin.php">Back to Download Administration</a></p>';
    catalog_foot();
}
