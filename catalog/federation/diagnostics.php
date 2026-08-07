<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders or processes the federation interface for Federation Diagnostics.
 * Why: It keeps parent/child federation administration, inventory, requests, and transfer workflows separate from
 *      general catalog pages.
 * Role: Federation UI/administration entry point backed by shared federation services.
 * Audit: Federation-specific route; consolidate shared behavior into services rather than merging distinct
 *        parent/child screens blindly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationWorker.php';
require_once __DIR__ . '/../lib/FederationStreamingWorker.php';
require_once __DIR__ . '/../lib/FederationDependencyDownloads.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../lib/FederationState.php';

use UnrealDb\Catalog\Application\Federation\CatalogFederationHistoryPageService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoFederationHistoryPageQuery;

function diagnostics_tab(mixed $value): string
{
    $tab = strtolower(trim((string)$value));
    return in_array($tab, ['logs', 'cleanup', 'conflicts', 'worker', 'connections'], true) ? $tab : 'logs';
}

function diagnostics_worker(PDO $db, array $config): array
{
    $limit = max(1, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1));
    $result = [
        'inventory_sync' => federation_sync_due_inventories($db),
        'approved_downloads' => federation_queue_approved_dependency_downloads($db),
        'transfers' => [],
        'imports' => [],
    ];
    for ($i = 0; $i < $limit; $i++) {
        $run = federation_streaming_run_one_transfer($db, $config);
        $result['transfers'][] = $run;
        if (!empty($run['skipped'])) break;
    }
    for ($i = 0; $i < $limit; $i++) {
        $run = federation_worker_run_one_import($db, $config);
        $result['imports'][] = $run;
        if (!empty($run['skipped'])) break;
    }
    return $result;
}

/** @param array<string,mixed> $page */
function diagnostics_log_links(array $filters, array $page): string
{
    $link = static function (string $label, string $move, string $cursor = '') use ($filters): string {
        $query = $filters + ['tab' => 'logs', 'log_move' => $move];
        if ($cursor !== '') {
            $query['log_cursor'] = $cursor;
        }
        return '<a class="button" href="diagnostics.php?' . catalog_h(http_build_query($query)) . '">' . catalog_h($label) . '</a>';
    };
    $html = '<p class="page-links">' . $link('Newest', 'first');
    if (!empty($page['has_previous']) && (string)($page['previous_cursor'] ?? '') !== '') {
        $html .= ' ' . $link('Newer', 'previous', (string)$page['previous_cursor']);
    }
    if (!empty($page['has_next']) && (string)($page['next_cursor'] ?? '') !== '') {
        $html .= ' ' . $link('Older', 'next', (string)$page['next_cursor']);
    }
    return $html . ' ' . $link('Oldest', 'last') . '</p>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $historyPageQuery = new PdoFederationHistoryPageQuery($db);
    federation_reconcile_site_role($db);
    $tab = diagnostics_tab($_REQUEST['tab'] ?? 'logs');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) throw new RuntimeException('Admin required.');
        catalog_check_csrf('fed_diagnostics');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if ($action === 'run_worker') {
            $_SESSION['fed_diagnostics_result'] = diagnostics_worker($db, $config);
            $_SESSION['fed_diagnostics_flash'] = 'Federation worker run completed.';
            $tab = 'worker';
        } elseif ($action === 'prune') {
            $days = max(1, min(3650, (int)($_POST['days'] ?? 30)));
            $nonceTtl = max(300, (int)(fed_setting($db, 'api_nonce_ttl_seconds', '300') ?: 300));
            $nonce = $db->prepare('DELETE FROM ue_federation_nonces WHERE created_at<DATE_SUB(NOW(),INTERVAL ? SECOND)');
            $nonce->execute([$nonceTtl]);
            $logs = $db->prepare('DELETE FROM ue_federation_transfer_logs WHERE created_at<DATE_SUB(NOW(),INTERVAL ? DAY)');
            $logs->execute([$days]);
            $jobs = $db->prepare('DELETE FROM ue_federation_transfer_jobs WHERE status IN ("imported","cancelled","failed") AND COALESCE(finished_at,created_at)<DATE_SUB(NOW(),INTERVAL ? DAY)');
            $jobs->execute([$days]);
            $_SESSION['fed_diagnostics_flash'] = 'Removed ' . $nonce->rowCount() . ' nonce(s), ' . $logs->rowCount() . ' log row(s), and ' . $jobs->rowCount() . ' old transfer job(s).';
            $tab = 'cleanup';
        } elseif ($action === 'clear_inventory') {
            $days = max(1, min(3650, (int)($_POST['days'] ?? 90)));
            $stmt = $db->prepare('DELETE FROM ue_federation_peer_files WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL ? DAY)');
            $stmt->execute([$days]);
            $_SESSION['fed_diagnostics_flash'] = 'Removed ' . $stmt->rowCount() . ' stale cached inventory row(s).';
            $tab = 'cleanup';
        } else {
            throw new RuntimeException('Unknown diagnostics action.');
        }
        header('Location: diagnostics.php?tab=' . rawurlencode($tab));
        exit;
    }

    if (!catalog_require_admin_page('Federation Diagnostics')) exit;
    catalog_head('Federation Diagnostics');
    catalog_flash($_SESSION['fed_diagnostics_flash'] ?? null);
    unset($_SESSION['fed_diagnostics_flash']);
    catalog_page_header('Federation Diagnostics', 'Logs, cleanup, conflicts, worker controls and connection health.', federation_main_links());

    echo '<div class="card"><p class="page-links">';
    foreach (['logs'=>'Logs','cleanup'=>'Cleanup','conflicts'=>'Conflicts','worker'=>'Worker','connections'=>'Connection Diagnostics'] as $key=>$label) {
        echo '<a class="button" href="diagnostics.php?tab=' . $key . '">' . $label . '</a> ';
    }
    echo '</p></div>';

    if ($tab === 'logs') {
        $level = strtoupper(trim((string)($_GET['level'] ?? '')));
        $peerId = (int)($_GET['peer_id'] ?? 0);
        $event = trim((string)($_GET['event'] ?? ''));
        $pageSize = CatalogFederationHistoryPageService::normalizePageSize((int)($_GET['page_size'] ?? 100));
        $where=[]; $args=[];
        if ($level !== '') { $where[]='l.level=?'; $args[]=$level; }
        if ($peerId > 0) { $where[]='l.peer_id=?'; $args[]=$peerId; }
        if ($event !== '') { $where[]='l.event LIKE ?'; $args[]='%'.$event.'%'; }
        $context = 'federation-diagnostics-logs|level=' . $level . '|peer=' . $peerId . '|event=' . strtolower($event);
        $page = $historyPageQuery->fetch(
            $config,
            $context,
            'SELECT l.*,p.site_name peer_name,l.created_at cursor_created_at,l.id cursor_id
             FROM ue_federation_transfer_logs l
             LEFT JOIN ue_federation_peers p ON p.id=l.peer_id',
            implode(' AND ', $where),
            $args,
            ['l.created_at', 'l.id'],
            ['cursor_created_at', 'cursor_id'],
            ['DESC', 'DESC'],
            $pageSize,
            (string)($_GET['log_cursor'] ?? ''),
            (string)($_GET['log_move'] ?? 'first')
        );
        $rows = $page['rows'];
        $peers = catalog_all($db, 'SELECT id,site_name,peer_role FROM ue_federation_peers ORDER BY peer_role,site_name');
        echo '<div class="card"><h2>Log filters</h2><form method="get"><input type="hidden" name="tab" value="logs"><label>Level <select name="level"><option value="">All</option>';
        foreach (['INFO','WARN','ERROR'] as $v) echo '<option'.($level===$v?' selected':'').'>' . $v . '</option>';
        echo '</select></label> <label>Peer <select name="peer_id"><option value="0">All</option>';
        foreach ($peers as $p) echo '<option value="'.(int)$p['id'].'"'.((int)$p['id']===$peerId?' selected':'').'>' . catalog_h($p['peer_role'].' - '.$p['site_name']) . '</option>';
        echo '</select></label> <label>Event <input name="event" value="'.catalog_h($event).'"></label> <label>Rows <select name="page_size">';
        foreach ([50,100,250,500] as $option) echo '<option value="'.$option.'"'.($pageSize===$option?' selected':'').'>'.$option.'</option>';
        echo '</select></label> <button>Filter</button></form></div>';
        $filters = ['level' => $level, 'peer_id' => $peerId, 'event' => $event, 'page_size' => $pageSize];
        echo '<div class="card"><h2>Federation Logs</h2>';
        echo diagnostics_log_links($filters, $page);
        if (!$rows) echo '<p class="muted">No matching logs on this page.</p>';
        else {
            echo '<table><tr><th>Time</th><th>Level</th><th>Peer</th><th>Job</th><th>Event</th><th>Details</th></tr>';
            foreach ($rows as $row) echo '<tr><td class="nowrap">'.catalog_h($row['created_at']).'</td><td>'.catalog_h($row['level']).'</td><td>'.catalog_h($row['peer_name']??'').'</td><td class="mono">'.catalog_h($row['transfer_job_id']??'').'</td><td class="mono">'.catalog_h($row['event']).'</td><td class="path">'.catalog_h($row['details']).'</td></tr>';
            echo '</table>';
        }
        echo diagnostics_log_links($filters, $page);
        echo '</div>';
    } elseif ($tab === 'cleanup') {
        $nonceCount = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_nonces');
        $logCount = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_logs');
        $oldJobs = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status IN ("imported","cancelled","failed")');
        $stale = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL 90 DAY)');
        echo '<div class="grid">';
        catalog_stat_card('Stored nonces',$nonceCount); catalog_stat_card('Log rows',$logCount); catalog_stat_card('Historical transfer jobs',$oldJobs); catalog_stat_card('Inventory rows older than 90 days',$stale);
        echo '</div>';
        echo '<div class="card"><h2>Prune old records</h2><form method="post" onsubmit="return confirm(\'Delete old federation history?\')"><input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('fed_diagnostics')).'"><input type="hidden" name="action" value="prune"><label>Older than <input type="number" name="days" min="1" max="3650" value="30"> days</label> <button>Run cleanup</button></form></div>';
        echo '<div class="card"><h2>Clear stale inventory cache</h2><form method="post" onsubmit="return confirm(\'Delete stale cached inventory rows?\')"><input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('fed_diagnostics')).'"><input type="hidden" name="action" value="clear_inventory"><label>Older than <input type="number" name="days" min="1" max="3650" value="90"> days</label> <button>Clear stale cache</button></form></div>';
    } elseif ($tab === 'worker') {
        $queued = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"');
        $downloaded = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"');
        $failed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"');
        echo '<div class="grid">'; catalog_stat_card('Queued',$queued); catalog_stat_card('Waiting import',$downloaded); catalog_stat_card('Failed',$failed,'',$failed?'warning':''); catalog_stat_card('Files per run',(int)fed_setting($db,'max_files_per_transfer_run','1')); echo '</div>';
        if (isset($_SESSION['fed_diagnostics_result'])) { echo '<div class="card"><h2>Last result</h2><pre class="mono">'.catalog_h(json_encode($_SESSION['fed_diagnostics_result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></div>'; unset($_SESSION['fed_diagnostics_result']); }
        echo '<div class="card"><h2>Run Worker</h2><form method="post"><input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('fed_diagnostics')).'"><button name="action" value="run_worker">Run federation worker now</button></form><p><a class="button" href="queue.php">Open Transfers</a></p></div>';
    } elseif ($tab === 'connections') {
        $peers = catalog_all($db, 'SELECT p.*,(SELECT COUNT(*) FROM ue_federation_peer_files pf WHERE pf.peer_id=p.id) inventory_rows,(SELECT MAX(last_seen_at) FROM ue_federation_peer_files pf WHERE pf.peer_id=p.id) inventory_updated,(SELECT MAX(created_at) FROM ue_federation_transfer_logs l WHERE l.peer_id=p.id) last_log FROM ue_federation_peers p ORDER BY p.peer_role,p.site_name');
        echo '<div class="card"><h2>Connection Diagnostics</h2>';
        if (!$peers) echo '<p class="muted">No established connections.</p>';
        else { echo '<table><tr><th>Role</th><th>Peer</th><th>URL</th><th>Active</th><th>Last contact</th><th>Cached inventory</th><th>Inventory updated</th><th>Last log</th><th>Actions</th></tr>'; foreach ($peers as $p) echo '<tr><td>'.catalog_h($p['peer_role']).'</td><td>'.catalog_h($p['site_name']).'</td><td class="mono path">'.catalog_h($p['site_url']).'</td><td>'.((int)$p['is_active']?'yes':'no').'</td><td>'.catalog_h($p['last_seen_at']??'never').'</td><td>'.(int)$p['inventory_rows'].'</td><td>'.catalog_h($p['inventory_updated']??'never').'</td><td>'.catalog_h($p['last_log']??'never').'</td><td><a href="connections.php">Test/manage</a> · <a href="inventories.php?peer_id='.(int)$p['id'].'">Inventory</a></td></tr>'; echo '</table>'; }
        echo '</div>';
    } else {
        require __DIR__ . '/_diagnostics-conflicts.php';
    }
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) catalog_head('Federation diagnostics error');
    echo '<div class="card"><h1>Error</h1><p>'.catalog_h($error->getMessage()).'</p></div>';
    catalog_foot();
}
