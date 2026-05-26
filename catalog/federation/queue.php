<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function fq_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function fq_csrf(): string
{
    $_SESSION['fed_queue_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_queue_csrf'];
}

function fq_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_queue_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function fq_status_card(PDO $db, string $direction, string $title): void
{
    $counts = catalog_all($db, 'SELECT status, COUNT(*) c FROM ue_federation_transfer_jobs WHERE direction=? GROUP BY status ORDER BY status', [$direction]);
    echo '<div class="stat"><h2>' . catalog_h($title) . '</h2>';
    if (!$counts) {
        echo '<p class="muted">No jobs</p>';
    } else {
        foreach ($counts as $row) {
            echo '<p><span class="mono">' . catalog_h($row['status']) . '</span>: ' . (int)$row['c'] . '</p>';
        }
    }
    echo '</div>';
}

function fq_job_action(PDO $db, int $jobId, string $action): string
{
    $job = catalog_one($db, 'SELECT * FROM ue_federation_transfer_jobs WHERE id=?', [$jobId]);
    if (!$job) {
        throw new RuntimeException('Job not found.');
    }

    if ($action === 'retry') {
        if (!in_array((string)$job['status'], ['failed','cancelled'], true)) {
            throw new RuntimeException('Only failed/cancelled jobs can be retried.');
        }
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="queued", bytes_done=0, incoming_path=NULL, downloaded_md5=NULL, downloaded_sha1=NULL, started_at=NULL, finished_at=NULL, last_error=NULL WHERE id=?')->execute([$jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'JOB_RETRY', 'Job reset to queued.');
        return 'Job #' . $jobId . ' reset to queued.';
    }

    if ($action === 'cancel') {
        if (!in_array((string)$job['status'], ['queued','failed'], true)) {
            throw new RuntimeException('Only queued/failed jobs can be cancelled from this page.');
        }
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="cancelled", finished_at=NOW(), last_error="Cancelled by admin." WHERE id=?')->execute([$jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'JOB_CANCEL', 'Job cancelled by admin.');
        return 'Job #' . $jobId . ' cancelled.';
    }

    throw new RuntimeException('Unknown queue action.');
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!fq_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        fq_check_csrf();
        $_SESSION['fed_queue_flash'] = fq_job_action($db, (int)($_POST['job_id'] ?? 0), (string)($_POST['action'] ?? ''));
        header('Location: queue.php');
        exit;
    }

    catalog_head('Federation Queue');

    if (!fq_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_queue_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['fed_queue_flash']) . '</strong></div>';
        unset($_SESSION['fed_queue_flash']);
    }

    echo '<div class="card"><h1>Federation Queue Overview</h1><p class="muted">One place to review parent pulls, child downloads, downloaded files waiting for import, completed imports, failures, retries, and cancellations.</p><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="worker-run.php">Bulk worker</a> <a class="button" href="transfer-run.php">Run one transfer</a> <a class="button" href="import-run.php">Import one download</a> <a class="button" href="parent-pull.php">Parent pull</a> <a class="button" href="approved-downloads.php">Approved downloads</a> <a class="button" href="logs.php">Logs</a></p></div>';

    echo '<div class="card"><h2>Queue counts</h2><div class="grid">';
    fq_status_card($db, 'parent_pull_from_child', 'Parent pulls from children');
    fq_status_card($db, 'download_from_parent', 'Child downloads from parent');
    fq_status_card($db, 'upload_to_parent', 'Uploads to parent');
    echo '</div></div>';

    $waitingImport = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.status="downloaded" AND j.incoming_path IS NOT NULL AND j.incoming_path<>"" ORDER BY j.finished_at ASC, j.id ASC LIMIT 100');
    echo '<div class="card"><h2>Downloaded files waiting for import</h2>';
    if (!$waitingImport) {
        echo '<p class="muted">No downloaded files are waiting for import.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Remote item</th><th>Remote file</th><th>Incoming</th><th>Bytes</th><th>Hashes</th><th>Finished</th></tr>';
        foreach ($waitingImport as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td class="mono small">' . catalog_h($job['incoming_path']) . '</td><td>' . catalog_h(catalog_bytes((int)$job['bytes_done'])) . '</td><td class="mono small">MD5 ' . catalog_h($job['downloaded_md5']) . '<br>SHA1 ' . catalog_h($job['downloaded_sha1']) . '</td><td>' . catalog_h($job['finished_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $active = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.status IN ("queued","running") ORDER BY FIELD(j.status,"running","queued"), j.created_at ASC LIMIT 200');
    echo '<div class="card"><h2>Queued / running transfer jobs</h2>';
    if (!$active) {
        echo '<p class="muted">No queued or running transfer jobs.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Status</th><th>Remote item</th><th>Remote file</th><th>Progress</th><th>Speed limit</th><th>Created</th><th>Action</th></tr>';
        foreach ($active as $job) {
            $action = '';
            if ((string)$job['status'] === 'queued') {
                $action = '<form method="post" onsubmit="return confirm(\'Cancel queued job?\')"><input type="hidden" name="csrf" value="' . catalog_h(fq_csrf()) . '"><input type="hidden" name="job_id" value="' . (int)$job['id'] . '"><input type="hidden" name="action" value="cancel"><button>Cancel</button></form>';
            }
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td>' . catalog_h($job['status']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h(catalog_bytes((int)$job['bytes_done']) . ' / ' . catalog_bytes((int)$job['bytes_total'])) . '</td><td>' . (int)$job['speed_limit_kbps'] . ' KB/s</td><td>' . catalog_h($job['created_at']) . '</td><td>' . $action . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $failed = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.status IN ("failed","cancelled") ORDER BY j.finished_at DESC, j.id DESC LIMIT 100');
    echo '<div class="card"><h2>Failed / cancelled jobs</h2>';
    if (!$failed) {
        echo '<p class="muted">No failed or cancelled jobs.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Status</th><th>Remote item</th><th>Remote file</th><th>Error</th><th>Finished</th><th>Action</th></tr>';
        foreach ($failed as $job) {
            $retry = '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(fq_csrf()) . '"><input type="hidden" name="job_id" value="' . (int)$job['id'] . '"><input type="hidden" name="action" value="retry"><button>Retry</button></form>';
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td>' . catalog_h($job['status']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['finished_at']) . '</td><td>' . $retry . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $recent = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id ORDER BY j.created_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent jobs</h2>';
    if (!$recent) {
        echo '<p class="muted">No federation transfer jobs yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Status</th><th>Remote item</th><th>Remote file</th><th>Local file</th><th>Message</th><th>Created</th></tr>';
        foreach ($recent as $job) {
            $local = !empty($job['local_file_id']) ? '<a href="../file-info.php?id=' . (int)$job['local_file_id'] . '" target="_blank">file ' . (int)$job['local_file_id'] . '</a>' : '';
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td>' . catalog_h($job['status']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . $local . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation queue error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
