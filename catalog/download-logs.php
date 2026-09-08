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

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeoIpLocationResolver;
use UnrealDb\Catalog\Infrastructure\Security\CatalogSiteBlocklist;
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

function download_logs_time(mixed $value): string
{
    $value = trim((string)$value);
    return $value === '' ? '' : substr($value, 0, 19);
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

/**
 * @return array{where_sql:string,args:list<mixed>}
 */
function download_logs_filter_clause(
    string $view,
    string $status,
    string $type,
    int $gameId,
    string $ip,
    string $search,
    bool $countryAvailable
): array {
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
        $ipColumn = $view === 'downloads' ? 'a.ip_address' : 'a.request_ip';
        $packed = @inet_pton($ip);
        if (is_string($packed)) {
            $where[] = $ipColumn . '=?';
            $args[] = $packed;
        } else {
            $ipLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ip) . '%';
            $where[] = 'INET6_NTOA(' . $ipColumn . ') LIKE ? ESCAPE "\\\\"';
            $args[] = $ipLike;
        }
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
    } elseif ($search !== '') {
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

    return [
        'where_sql' => $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '',
        'args' => $args,
    ];
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
    $siteBlockAvailable = (int)$db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_site_blocked_ips"'
    )->fetchColumn() === 1;
    $siteBlocklist = $siteBlockAvailable ? new CatalogSiteBlocklist($db, $config) : null;
    $message = '';

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('download_logs_admin');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $logView = download_logs_choice((string)($_POST['log_view'] ?? 'downloads'), ['downloads', 'generations'], 'downloads');
        $blockLogId = max(0, (int)($_POST['block_log_id'] ?? 0));
        if ($blockLogId > 0) {
            $action = 'block_log_site_ip';
        }
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        if (count($ids) > 1000) {
            throw new RuntimeException('Select no more than 1,000 log records at once.');
        }

        if ($action === 'block_log_site_ip') {
            if (!$siteBlocklist instanceof CatalogSiteBlocklist) {
                throw new RuntimeException('Run the pending Access Matrix migration before blocking full site access.');
            }
            if (!$available || $blockLogId < 1) {
                throw new RuntimeException('The selected download log record is unavailable.');
            }
            $table = $logView === 'generations' ? 'ue_generated_package_audit' : 'ue_download_audit';
            $ipColumn = $logView === 'generations' ? 'request_ip' : 'ip_address';
            $statement = $db->prepare(
                'SELECT INET6_NTOA(' . $ipColumn . ') ip FROM ' . $table
                . ' WHERE id=? AND ' . $ipColumn . ' IS NOT NULL LIMIT 1'
            );
            $statement->execute([$blockLogId]);
            $logIp = trim((string)$statement->fetchColumn());
            if ($logIp === '' || @inet_pton($logIp) === false) {
                $message = 'The selected log record does not contain a valid IP address.';
            } else {
                $siteBlocklist->block(
                $logIp,
                (int)($_SESSION['user']['id'] ?? 0),
                'Blocked from Download Logs record #' . $blockLogId . '.'
                );
                $message = $logIp . ' blocked from the entire site.';
            }
        } elseif ($action === 'delete_selected') {
            if (!$available || $ids === []) {
                throw new RuntimeException('Select one or more log records to delete.');
            }
            $table = $logView === 'generations' ? 'ue_generated_package_audit' : 'ue_download_audit';
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')');
            $statement->execute($ids);
            $message = $statement->rowCount() . ' selected log record(s) permanently deleted.';
        } elseif ($action === 'delete_all_matching') {
            if (!$available) {
                throw new RuntimeException('Download audit storage is unavailable.');
            }
            $filterStatus = download_logs_choice(
                (string)($_POST['filter_status'] ?? 'all'),
                ['all', 'started', 'completed', 'interrupted', 'failed', 'queued', 'running', 'cancelled'],
                'all'
            );
            $filterType = download_logs_choice(
                (string)($_POST['filter_type'] ?? 'all'),
                ['all', 'individual_file', 'generated_package'],
                'all'
            );
            $filterGameId = max(0, (int)($_POST['filter_game_id'] ?? 0));
            $filterIp = trim((string)($_POST['filter_ip'] ?? ''));
            $filterSearch = download_logs_search((string)($_POST['filter_q'] ?? ''));
            $filter = download_logs_filter_clause(
                $logView,
                $filterStatus,
                $filterType,
                $filterGameId,
                $filterIp,
                $filterSearch,
                $countryAvailable
            );
            $table = $logView === 'generations' ? 'ue_generated_package_audit' : 'ue_download_audit';
            $statement = $db->prepare('DELETE a FROM ' . $table . ' a' . $filter['where_sql']);
            $statement->execute($filter['args']);
            $message = $statement->rowCount() . ' matching log record(s) permanently deleted.';
        } elseif ($action === 'block_selected_site_ips') {
            if (!$siteBlocklist instanceof CatalogSiteBlocklist) {
                throw new RuntimeException('Run the pending Access Matrix migration before blocking full site access.');
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
                $siteBlocklist->block(
                    $selectedIp,
                    (int)($_SESSION['user']['id'] ?? 0),
                    'Blocked from Download Logs activity.'
                );
            }
            $message = count($ips) . ' IP address(es) blocked from the entire site.';
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
            $manualIp = trim((string)($_POST['ip_address'] ?? ''));
            if (@inet_pton($manualIp) === false) {
                $message = 'Enter a valid IPv4 or IPv6 address.';
            } else {
                $blocklist->block(
                    $manualIp,
                    (int)($_SESSION['user']['id'] ?? 0),
                    (string)($_POST['note'] ?? '')
                );
                $message = 'IP address added to the transfer blocklist.';
            }
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
    $siteBlockedRows = $siteBlocklist instanceof CatalogSiteBlocklist ? $siteBlocklist->all() : [];
    $siteBlockedLookup = [];
    foreach ($siteBlockedRows as $siteBlockedRow) {
        $siteBlockedLookup[strtolower((string)$siteBlockedRow['ip'])] = true;
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

        $filter = download_logs_filter_clause(
            $view,
            $status,
            $type,
            $gameId,
            $ip,
            $search,
            $countryAvailable
        );
        $whereSql = $filter['where_sql'];
        $args = $filter['args'];

        if ($view === 'downloads') {
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

        $geoIpResolver = new CatalogGeoIpLocationResolver($db);
        foreach ($rows as $index => $row) {
            $rows[$index]['map_location'] = $geoIpResolver->resolve((string)($row['ip_text'] ?? ''));
        }
    }

    catalog_head('Download Logs');
    echo '<style>'
        . '.download-log-cards{grid-template-columns:repeat(5,minmax(130px,1fr));margin-bottom:14px}'
        . '.download-log-tabs,.download-log-toolbar,.download-log-pages,.download-log-actions,.download-block-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.download-log-tabs,.download-log-toolbar,.download-log-actions{margin-bottom:12px}'
        . '.download-log-delete-all{display:flex;gap:9px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin:-2px 0 12px}'
        . '.download-log-toolbar .search{min-width:280px;flex:1}'
        . '.download-log-table{min-width:1080px;table-layout:auto}'
        . '.download-log-select{width:36px;text-align:center;white-space:nowrap}'
        . '.download-log-time,.download-log-status,.download-log-ip,.download-log-country,.download-log-transfer,.download-log-job,.download-log-version,.download-log-format{width:1%;white-space:nowrap}'
        . '.download-log-file{width:auto;min-width:260px;overflow-wrap:anywhere}'
        . '.download-log-game{width:1%;min-width:120px;white-space:normal}'
        . '.download-log-agent{width:30ch;min-width:30ch;max-width:30ch}'
        . '.download-log-agent-text{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        . '.download-log-ip-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin-top:5px}'
        . '.download-log-error{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;overflow-wrap:anywhere;margin-top:4px}'
        . '.download-log-artifact{min-width:180px;overflow-wrap:anywhere}'
        . '.download-blocklist{margin:14px 0}'
        . '.download-blocklist table{min-width:760px}'
        . '.download-block-actions .grow{flex:1;min-width:260px}'
        . '.download-log-pill{display:inline-block;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700}'
        . '.download-log-pill-completed{color:#a7f3d0;border-color:rgba(50,213,131,.75)}'
        . '.download-log-pill-failed,.download-log-pill-interrupted,.download-log-pill-cancelled{color:#fecdd3;border-color:rgba(255,107,122,.75)}'
        . '.download-log-pill-started,.download-log-pill-running,.download-log-pill-queued{color:#bfdbfe;border-color:rgba(96,165,250,.75)}'
        . '.download-log-pages{justify-content:space-between;margin-top:12px}'

        . '.download-country{text-align:center;white-space:nowrap}'
        . '.download-country-flag{display:inline-block;width:20px;height:15px;object-fit:cover;border-radius:2px;vertical-align:-2px;cursor:help}'
        . '.download-country-empty{color:var(--muted)}'
        . '@media(max-width:1000px){.download-log-cards{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'Download Logs',
        'Administrator reporting records for generated package requests and actual individual/generated-package transfers. Visible IP rows are enriched from the local GeoIP database for approximate city/region map placement.',
        [
            'Download Administration' => 'download-admin.php',
            'Package Settings' => 'download-package-settings.php',
            'Download Settings' => 'downloads-settings.php',
            'Site Activity Logs' => 'access-matrix.php',
            'Site Blacklist' => 'site-blacklist.php',
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
                    . '<td class="mono small">' . catalog_h(download_logs_time($blockedRow['created_at'])) . '</td>'
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
        . '<label>IP <input name="ip" value="' . catalog_h($ip) . '" placeholder="Full or partial IP"></label>'
        . '<label class="search">Search <input type="search" name="q" value="' . catalog_h($search) . '" placeholder="File, package, country, format, error or user agent"></label>'
        . '<label>Rows <select name="per_page">';
    foreach ([50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($perPage === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button></form>';

    if ($available && $total > 0) {
        $matchingLabel = $view === 'generations' ? 'package-generation log' : 'download log';
        echo '<form method="post" class="download-log-delete-all" onsubmit="return confirm(\'Permanently delete ALL '
            . number_format($total) . ' matching ' . catalog_h($matchingLabel) . ' record(s)? This is not limited to the current page and cannot be undone.\')">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('download_logs_admin')) . '">'
            . '<input type="hidden" name="action" value="delete_all_matching">'
            . '<input type="hidden" name="log_view" value="' . catalog_h($view) . '">'
            . '<input type="hidden" name="filter_status" value="' . catalog_h($status) . '">'
            . '<input type="hidden" name="filter_type" value="' . catalog_h($type) . '">'
            . '<input type="hidden" name="filter_game_id" value="' . (int)$gameId . '">'
            . '<input type="hidden" name="filter_ip" value="' . catalog_h($ip) . '">'
            . '<input type="hidden" name="filter_q" value="' . catalog_h($search) . '">'
            . '<span class="muted small">' . number_format($total) . ' matching record(s) across all pages.</span> '
            . '<button class="danger" type="submit">Delete all ' . number_format($total) . ' matching</button>'
            . '</form>';
    }

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
            . '<option value="block_selected_ips">Block selected IPs from transfers</option>'
            . '<option value="block_selected_site_ips">Block selected IPs from entire site</option>'
            . '<option value="unblock_selected_ips">Unblock selected transfer IPs</option></select>'
            . '<button type="submit">Apply to selected</button></div>';

        if ($view === 'downloads') {
        echo '<div class="table-wrap" data-world-map-source="download-logs" data-world-map-title="Download locations" data-world-map-storage-key="unrealdb.downloadLogs.worldMapOpen" data-world-map-note="Approximate city/region locations from the local GeoIP database, with country fallback when detailed coordinates are unavailable." data-world-map-entry-singular="visible download" data-world-map-entry-plural="visible downloads"><table class="download-log-table"><thead><tr>'
            . '<th class="download-log-select"></th>'
            . '<th class="download-log-time">' . download_logs_sort_heading('Started', 'time', $sort, $direction) . '</th>'
            . '<th class="download-log-status">Status</th>'
            . '<th class="download-log-file">File / package</th>'
            . '<th class="download-log-game">Game</th>'
            . '<th class="download-log-ip">IP</th>'
            . '<th class="download-log-country">' . download_logs_sort_heading('Country', 'country', $sort, $direction, $countryAvailable) . '</th>'
            . '<th class="download-log-transfer">Transferred</th>'
            . '<th class="download-log-job">Job</th>'
            . '<th class="download-log-agent">User agent / error</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $rowStatus = strtolower((string)$row['status']);
            $requested = isset($row['bytes_requested']) ? (int)$row['bytes_requested'] : 0;
            $sent = (int)$row['bytes_sent'];
            $ipText = trim((string)($row['ip_text'] ?? ''));
            $mapLocation = is_array($row['map_location'] ?? null) ? $row['map_location'] : [];
            if (trim((string)($mapLocation['country_code'] ?? '')) === '') {
                $mapLocation['country_code'] = (string)($row['country_code'] ?? '');
                $mapLocation['country_name'] = (string)($row['country_name'] ?? '');
            }
            $countryCode = strtoupper(trim((string)($mapLocation['country_code'] ?? '')));
            $countryName = trim((string)($mapLocation['country_name'] ?? ''));
            $mapAttributes = catalog_world_map_attributes($ipText, $mapLocation);
            echo '<tr' . $mapAttributes . '><td class="download-log-select"><input class="download-log-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td><td class="mono small download-log-time">' . catalog_h(download_logs_time($row['started_at'])) . '</td>';
            echo '<td class="download-log-status"><span class="download-log-pill download-log-pill-' . catalog_h($rowStatus) . '">' . catalog_h($rowStatus) . '</span></td>';
            echo '<td class="download-log-file"><strong>' . catalog_h((string)$row['download_name']) . '</strong>';
            if ((int)($row['file_id'] ?? 0) > 0) {
                echo '<br><a class="small" href="file-info.php?id=' . (int)$row['file_id'] . '">File #' . (int)$row['file_id'] . '</a>';
            }
            if ((string)($row['package_format'] ?? '') !== '') {
                echo '<br><span class="mono small muted">' . catalog_h((string)$row['package_format']) . '</span>';
            }
            echo '</td><td class="download-log-game">' . catalog_h((string)($row['game_name'] ?? '')) . '</td>';
            $transferBlocked = $ipText !== '' && isset($blockedLookup[strtolower($ipText)]);
            $siteBlocked = $ipText !== '' && isset($siteBlockedLookup[strtolower($ipText)]);
            echo '<td class="mono download-log-ip">' . catalog_h($ipText)
                . ($transferBlocked ? '<br><span class="dep package_only">transfer blocked</span>' : '')
                . ($siteBlocked ? '<br><span class="dep missing">site blocked</span>' : '');
            if ($ipText !== '') {
                echo '<div class="download-log-ip-actions">';
                if (!$siteBlocked && $siteBlocklist instanceof CatalogSiteBlocklist) {
                    echo '<button class="ui-button ui-button--danger ui-button--sm" type="submit" name="block_log_id" value="' . (int)$row['id']
                        . '" formnovalidate onclick="return confirm(\'Block ' . catalog_h($ipText) . ' from the entire site?\')">Blacklist IP</button>';
                } elseif ($siteBlocked) {
                    echo '<a class="ui-button ui-button--secondary ui-button--sm" href="site-blacklist.php?q=' . rawurlencode($ipText) . '">View blacklist</a>';
                }
                echo '</div>';
            }
            echo '</td>';
            echo '<td class="download-country">';
            if ($countryCode !== '' && $countryName !== '') {
                echo '<img class="download-country-flag" src="country-flag.php?code=' . rawurlencode(strtolower($countryCode))
                    . '" alt="" title="' . catalog_h($countryCode) . '" loading="lazy" width="20" height="15">';
            } else {
                echo '<span class="download-country-empty" title="Country not recorded">—</span>';
            }
            echo '</td>';
            echo '<td class="download-log-transfer">' . catalog_h(catalog_bytes($sent)) . ' / ' . catalog_h(catalog_bytes($requested)) . '</td>';
            echo '<td class="download-log-job">' . ((int)($row['job_id'] ?? 0) > 0 ? '<a href="background-jobs.php?q=' . (int)$row['job_id'] . '">#' . (int)$row['job_id'] . '</a>' : '—') . '</td>';
            $userAgent = (string)$row['user_agent'];
            echo '<td class="download-log-agent"><span class="small download-log-agent-text" title="' . catalog_h($userAgent) . '">' . catalog_h($userAgent) . '</span>';
            if ((string)($row['error_message'] ?? '') !== '') {
                echo '<br><span class="download-log-error dep missing">' . catalog_h((string)$row['error_message']) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        } else {
        echo '<div class="table-wrap" data-world-map-source="download-logs-generations" data-world-map-title="Package generation locations" data-world-map-storage-key="unrealdb.downloadLogs.generationWorldMapOpen" data-world-map-note="Approximate city/region locations from the local GeoIP database, with country fallback when detailed coordinates are unavailable." data-world-map-entry-singular="visible package generation" data-world-map-entry-plural="visible package generations"><table class="download-log-table"><thead><tr>'
            . '<th class="download-log-select"></th>'
            . '<th class="download-log-time">' . download_logs_sort_heading('Queued', 'time', $sort, $direction) . '</th>'
            . '<th class="download-log-status">Status</th>'
            . '<th class="download-log-file">Package</th>'
            . '<th class="download-log-version">Version</th>'
            . '<th class="download-log-format">Format</th>'
            . '<th class="download-log-game">Game / file</th>'
            . '<th class="download-log-ip">IP</th>'
            . '<th class="download-log-country">' . download_logs_sort_heading('Country', 'country', $sort, $direction, $countryAvailable) . '</th>'
            . '<th class="download-log-artifact">Artifact</th>'
            . '<th class="download-log-job">Job</th>'
            . '<th class="download-log-agent">User agent / error</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $rowStatus = strtolower((string)$row['status']);
            $ipText = trim((string)($row['ip_text'] ?? ''));
            $mapLocation = is_array($row['map_location'] ?? null) ? $row['map_location'] : [];
            if (trim((string)($mapLocation['country_code'] ?? '')) === '') {
                $mapLocation['country_code'] = (string)($row['country_code'] ?? '');
                $mapLocation['country_name'] = (string)($row['country_name'] ?? '');
            }
            $countryCode = strtoupper(trim((string)($mapLocation['country_code'] ?? '')));
            $countryName = trim((string)($mapLocation['country_name'] ?? ''));
            $mapAttributes = catalog_world_map_attributes($ipText, $mapLocation);
            echo '<tr' . $mapAttributes . '><td class="download-log-select"><input class="download-log-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td><td class="mono small download-log-time">' . catalog_h(download_logs_time($row['queued_at'])) . '</td>';
            echo '<td class="download-log-status"><span class="download-log-pill download-log-pill-' . catalog_h($rowStatus) . '">' . catalog_h($rowStatus) . '</span></td>';
            echo '<td class="download-log-file"><strong>' . catalog_h((string)$row['package_name']) . '</strong><br><span class="small muted">Dependencies: ' . (!empty($row['include_dependencies']) ? 'yes' : 'no') . '</span></td>';
            echo '<td class="mono download-log-version">' . catalog_h((string)$row['package_version']) . '</td>';
            echo '<td class="mono download-log-format">' . catalog_h((string)$row['package_format']) . '</td>';
            echo '<td class="download-log-game">' . catalog_h((string)($row['game_name'] ?? '')) . '<br><a class="small" href="file-info.php?id=' . (int)$row['file_id'] . '">File #' . (int)$row['file_id'] . '</a></td>';
            $transferBlocked = $ipText !== '' && isset($blockedLookup[strtolower($ipText)]);
            $siteBlocked = $ipText !== '' && isset($siteBlockedLookup[strtolower($ipText)]);
            echo '<td class="mono download-log-ip">' . catalog_h($ipText)
                . ($transferBlocked ? '<br><span class="dep package_only">transfer blocked</span>' : '')
                . ($siteBlocked ? '<br><span class="dep missing">site blocked</span>' : '');
            if ($ipText !== '') {
                echo '<div class="download-log-ip-actions">';
                if (!$siteBlocked && $siteBlocklist instanceof CatalogSiteBlocklist) {
                    echo '<button class="ui-button ui-button--danger ui-button--sm" type="submit" name="block_log_id" value="' . (int)$row['id']
                        . '" formnovalidate onclick="return confirm(\'Block ' . catalog_h($ipText) . ' from the entire site?\')">Blacklist IP</button>';
                } elseif ($siteBlocked) {
                    echo '<a class="ui-button ui-button--secondary ui-button--sm" href="site-blacklist.php?q=' . rawurlencode($ipText) . '">View blacklist</a>';
                }
                echo '</div>';
            }
            echo '</td>';
            echo '<td class="download-country">';
            if ($countryCode !== '' && $countryName !== '') {
                echo '<img class="download-country-flag" src="country-flag.php?code=' . rawurlencode(strtolower($countryCode))
                    . '" alt="" title="' . catalog_h($countryCode) . '" loading="lazy" width="20" height="15">';
            } else {
                echo '<span class="download-country-empty" title="Country not recorded">—</span>';
            }
            echo '</td>';
            echo '<td class="download-log-artifact">' . catalog_h((string)($row['artifact_name'] ?? ''));
            if (isset($row['artifact_size'])) {
                echo '<br><span class="small muted">' . catalog_h(catalog_bytes((int)$row['artifact_size'])) . '</span>';
            }
            echo '</td><td class="download-log-job"><a href="background-jobs.php?q=' . (int)$row['job_id'] . '">#' . (int)$row['job_id'] . '</a></td>';
            $userAgent = (string)$row['user_agent'];
            echo '<td class="download-log-agent"><span class="small download-log-agent-text" title="' . catalog_h($userAgent) . '">' . catalog_h($userAgent) . '</span>';
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
