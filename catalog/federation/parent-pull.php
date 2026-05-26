<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function pp_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function pp_csrf(): string
{
    $_SESSION['fed_parent_pull_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_parent_pull_csrf'];
}

function pp_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_parent_pull_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!pp_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        pp_check_csrf();
        $peerFileIds = array_values(array_unique(array_map('intval', $_POST['peer_file_ids'] ?? [])));
        if (!$peerFileIds) {
            throw new RuntimeException('Select at least one child file to queue.');
        }

        $insert = $db->prepare('INSERT INTO ue_federation_transfer_jobs(peer_id,direction,remote_file_id,status,speed_limit_kbps,wait_after_seconds,bytes_total) VALUES(?,"parent_pull_from_child",?,"queued",?,?,?)');
        $queued = 0;
        foreach ($peerFileIds as $peerFileId) {
            $pf = catalog_one($db, 'SELECT * FROM ue_federation_peer_files WHERE id=?', [$peerFileId]);
            if (!$pf || empty($pf['remote_file_id'])) {
                continue;
            }
            $insert->execute([(int)$pf['peer_id'], (int)$pf['remote_file_id'], (int)fed_setting($db, 'max_download_kbps', '0'), (int)fed_setting($db, 'delay_between_downloads_seconds', '5'), (int)$pf['file_size']]);
            $queued++;
        }
        fed_log($db, null, null, 'INFO', 'PARENT_PULL_QUEUE', 'Queued ' . $queued . ' parent pull job(s).');
        $_SESSION['fed_parent_pull_flash'] = 'Queued ' . $queued . ' parent pull job(s).';
        header('Location: parent-pull.php');
        exit;
    }

    catalog_head('Parent Pull');

    if (!pp_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_parent_pull_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['fed_parent_pull_flash']) . '</strong></div>';
        unset($_SESSION['fed_parent_pull_flash']);
    }

    echo '<div class="card"><h1>Parent Pull From Children</h1><p class="muted">Queue files from child inventories. Default view shows files this parent does not already have. The actual download runner pulls one queued job at a time.</p><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="peer-inventory.php">Peer inventory</a> <a class="button" href="transfer-run.php">Run transfer queue</a> <a class="button" href="logs.php">Logs</a></p></div>';

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="child" AND is_active=1 ORDER BY site_name');
    echo '<div class="card"><h2>Missing locally, available from children</h2>';
    if (!$peers) {
        echo '<p class="muted">No active child peers configured.</p>';
    } else {
        foreach ($peers as $peer) {
            $rows = catalog_all($db, 'SELECT pf.* FROM ue_federation_peer_files pf LEFT JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified" WHERE pf.peer_id=? AND local.id IS NULL ORDER BY pf.remote_game_name, pf.package_name, pf.original_name LIMIT 500', [(int)$peer['id']]);
            echo '<h3>' . catalog_h($peer['site_name']) . '</h3>';
            if (!$rows) {
                echo '<p class="muted">No missing-local files from this child.</p>';
                continue;
            }
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(pp_csrf()) . '"><table><tr><th>Queue</th><th>Game</th><th>Package</th><th>File</th><th>GUID</th><th>MD5</th><th>Size</th></tr>';
            foreach ($rows as $row) {
                echo '<tr><td><input type="checkbox" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td><td>' . catalog_h($row['remote_game_name']) . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono small">' . catalog_h($row['package_guid']) . '</td><td class="mono small">' . catalog_h($row['md5']) . '</td><td>' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td></tr>';
            }
            echo '</table><p><button>Queue selected from ' . catalog_h($peer['site_name']) . '</button></p></form>';
        }
    }
    echo '</div>';

    $jobs = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.direction="parent_pull_from_child" ORDER BY j.created_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent parent pull jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No parent pull jobs yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Error</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Parent pull error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
