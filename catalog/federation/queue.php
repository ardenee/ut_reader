<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../lib/FederationState.php';

function ft_tab(mixed $value): string
{
    $tab = strtolower(trim((string)$value));
    return in_array($tab, ['active', 'waiting', 'failed', 'completed', 'cancelled'], true) ? $tab : 'active';
}

function ft_statuses(string $tab): array
{
    return match ($tab) {
        'waiting' => ['downloaded'],
        'failed' => ['failed'],
        'completed' => ['imported'],
        'cancelled' => ['cancelled'],
        default => ['queued', 'running'],
    };
}

try {
    $db = catalog_db(catalog_config());
    $tab = ft_tab($_REQUEST['tab'] ?? 'active');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_transfers');
        $jobId = (int)($_POST['job_id'] ?? 0);
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $visible = federation_visible_transfer_job_sql($db, 'j');
        $job = catalog_one($db, 'SELECT j.* FROM ue_federation_transfer_jobs j WHERE j.id=? AND ' . $visible, [$jobId]);
        if (!$job) {
            throw new RuntimeException('Transfer job not found or excluded by policy.');
        }
        if ($action === 'cancel') {
            if (!in_array((string)$job['status'], ['queued', 'failed'], true)) {
                throw new RuntimeException('Only queued or failed transfers can be cancelled here.');
            }
            $db->prepare('UPDATE ue_federation_transfer_jobs SET status="cancelled",finished_at=NOW(),last_error="Cancelled by administrator." WHERE id=?')->execute([$jobId]);
            fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'JOB_CANCEL', 'Transfer cancelled by administrator.');
            $_SESSION['fed_transfers_flash'] = 'Transfer #' . $jobId . ' cancelled.';
        } elseif ($action === 'retry') {
            if (!in_array((string)$job['status'], ['failed', 'cancelled'], true)) {
                throw new RuntimeException('Only failed or cancelled transfers can be retried.');
            }
            $db->prepare('UPDATE ue_federation_transfer_jobs SET status="queued",bytes_done=0,incoming_path=NULL,downloaded_md5=NULL,downloaded_sha1=NULL,started_at=NULL,finished_at=NULL,last_error=NULL WHERE id=?')->execute([$jobId]);
            fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'JOB_RETRY', 'Transfer reset to queued.');
            $_SESSION['fed_transfers_flash'] = 'Transfer #' . $jobId . ' reset to queued.';
            $tab = 'active';
        } else {
            throw new RuntimeException('Unknown transfer action.');
        }
        header('Location: queue.php?tab=' . rawurlencode($tab));
        exit;
    }

    if (!catalog_require_admin_page('Federation Transfers')) {
        exit;
    }

    $visible = federation_visible_transfer_job_sql($db, 'j');
    $counts = [];
    foreach (['active', 'waiting', 'failed', 'completed', 'cancelled'] as $key) {
        $statuses = ft_statuses($key);
        $quoted = implode(',', array_map([$db, 'quote'], $statuses));
        $counts[$key] = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status IN (' . $quoted . ') AND ' . $visible)['c'] ?? 0);
    }

    catalog_head('Federation Transfers');
    catalog_flash($_SESSION['fed_transfers_flash'] ?? null);
    unset($_SESSION['fed_transfers_flash']);
    catalog_page_header(
        'Federation Transfers',
        'Monitor Parent pulls, Child downloads, imports, failures, retries and cancellations.',
        federation_main_links()
    );
    echo '<div class="card"><p>' . catalog_h(federation_base_game_policy_label($db)) . '</p><p class="page-links">';
    foreach (['active' => 'Active', 'waiting' => 'Waiting for import', 'failed' => 'Failed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $key => $label) {
        echo '<a class="button" href="queue.php?tab=' . $key . '">' . $label . ' (' . $counts[$key] . ')</a> ';
    }
    echo '</p></div>';

    $statuses = ft_statuses($tab);
    $quoted = implode(',', array_map([$db, 'quote'], $statuses));
    $jobs = catalog_all(
        $db,
        'SELECT j.*,p.site_name peer_name,p.peer_role
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         WHERE j.status IN (' . $quoted . ') AND ' . $visible . '
         ORDER BY COALESCE(j.started_at,j.created_at) DESC,j.id DESC LIMIT 500'
    );

    echo '<div class="card"><h2>' . catalog_h(ucfirst($tab)) . ' Transfers</h2>';
    if (!$jobs) {
        echo '<p class="muted">No matching policy-visible transfers.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Status</th><th>Request item</th><th>Remote file</th><th>Progress</th><th>Local file</th><th>Message</th><th>Created</th><th>Action</th></tr>';
        foreach ($jobs as $job) {
            $local = !empty($job['local_file_id']) ? '<a href="../file-info.php?id=' . (int)$job['local_file_id'] . '">file ' . (int)$job['local_file_id'] . '</a>' : '';
            $action = '';
            if ((string)$job['status'] === 'queued') {
                $action = '<form method="post" onsubmit="return confirm(\'Cancel this queued transfer?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_transfers')) . '"><input type="hidden" name="job_id" value="' . (int)$job['id'] . '"><input type="hidden" name="action" value="cancel"><button>Cancel</button></form>';
            } elseif (in_array((string)$job['status'], ['failed', 'cancelled'], true)) {
                $action = '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_transfers')) . '"><input type="hidden" name="job_id" value="' . (int)$job['id'] . '"><input type="hidden" name="action" value="retry"><button>Retry</button></form>';
            }
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td>' . catalog_h($job['status']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$job['bytes_done']) . ' / ' . catalog_bytes((int)$job['bytes_total'])) . '</td><td>' . $local . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td class="nowrap">' . catalog_h($job['created_at']) . '</td><td>' . $action . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div><div class="card"><p><a class="button" href="diagnostics.php?tab=worker">Worker controls</a> <a class="button" href="diagnostics.php?tab=logs">Transfer logs</a></p></div>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation transfers error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
