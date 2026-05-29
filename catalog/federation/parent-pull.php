<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

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

function pp_queue_form(PDO $db, array $rows, string $buttonText, string $emptyText): void
{
    if (!$rows) {
        echo '<p class="muted">' . catalog_h($emptyText) . '</p>';
        return;
    }

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(pp_csrf()) . '">';
    echo '<table><tr><th>Queue</th><th>Game</th><th>Package</th><th>File</th><th>GUID</th><th>MD5</th><th>Size</th><th>Reason</th></tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><input type="checkbox" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td>';
        echo '<td>' . catalog_h($row['remote_game_name']) . '</td>';
        echo '<td class="mono">' . catalog_h($row['package_name']) . '</td>';
        echo '<td>' . catalog_h($row['original_name']) . '</td>';
        echo '<td class="mono small">' . catalog_h($row['package_guid']) . '</td>';
        echo '<td class="mono small">' . catalog_h($row['md5']) . '</td>';
        echo '<td>' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td>';
        echo '<td class="small">' . catalog_h($row['reason'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</table><p><button>' . catalog_h($buttonText) . '</button></p></form>';
}

function pp_missing_dependencies(PDO $db, int $peerId): array
{
    return catalog_all($db, '
        SELECT DISTINCT pf.*, CONCAT("Missing dependency: ", d.required_package) AS reason
        FROM ue_federation_peer_files pf
        JOIN ue_dependencies d ON d.status="missing" AND d.required_package=pf.package_name
        LEFT JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified"
        WHERE pf.peer_id=? AND local.id IS NULL
        ORDER BY pf.remote_game_name, pf.package_name, pf.original_name
        LIMIT 500
    ', [$peerId]);
}

function pp_parent_missing_other(PDO $db, int $peerId): array
{
    return catalog_all($db, '
        SELECT pf.*, "Parent does not have this package GUID" AS reason
        FROM ue_federation_peer_files pf
        LEFT JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified"
        LEFT JOIN ue_dependencies d ON d.status="missing" AND d.required_package=pf.package_name
        WHERE pf.peer_id=? AND local.id IS NULL AND d.id IS NULL
        ORDER BY pf.remote_game_name, pf.package_name, pf.original_name
        LIMIT 500
    ', [$peerId]);
}

function pp_shared_files(PDO $db, int $peerId): array
{
    return catalog_all($db, '
        SELECT pf.*, CONCAT("Local file ID ", local.id, CASE WHEN local.md5<>pf.md5 THEN " / MD5 differs" ELSE " / same GUID" END) AS reason
        FROM ue_federation_peer_files pf
        JOIN ue_files local ON local.package_guid=pf.package_guid AND local.scan_status="verified"
        WHERE pf.peer_id=?
        ORDER BY pf.remote_game_name, pf.package_name, pf.original_name
        LIMIT 300
    ', [$peerId]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
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
            $exists = catalog_one($db, 'SELECT id FROM ue_federation_transfer_jobs WHERE peer_id=? AND direction="parent_pull_from_child" AND remote_file_id=? AND status IN ("queued","running","downloaded") LIMIT 1', [(int)$pf['peer_id'], (int)$pf['remote_file_id']]);
            if ($exists) {
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

    if (!catalog_require_admin_page('Parent Pull')) {
        exit;
    }

    catalog_head('Parent Pull');
    catalog_flash($_SESSION['fed_parent_pull_flash'] ?? null);
    unset($_SESSION['fed_parent_pull_flash']);

    catalog_page_header('Parent Pull From Children', 'Parent/master view. Each child is handled separately. Default priority is missing dependency files first, then other files the parent does not have, then files both sites already have for review.', catalog_federation_links() + ['Peer Inventory' => 'peer-inventory.php', 'Run Transfer Queue' => 'transfer-run.php', 'Import Downloaded Files' => 'import-run.php']);

    $peers = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="child" AND is_active=1 ORDER BY site_name');
    if (!$peers) {
        echo '<div class="card"><h2>No active children</h2><p class="muted">No active child peers configured.</p></div>';
    } else {
        foreach ($peers as $peer) {
            echo '<div class="card"><h2>Child: ' . catalog_h($peer['site_name']) . '</h2>';
            echo '<table><tr><th>URL</th><td class="mono path">' . catalog_h($peer['site_url']) . '</td></tr><tr><th>Last seen</th><td>' . catalog_h($peer['last_seen_at']) . '</td></tr></table>';
            echo '</div>';

            echo '<div class="card"><h3>1. Missing dependencies available from this child</h3><p class="muted">Default/high-priority parent pull list. These are files matching this parent site\'s current missing dependency package names.</p>';
            pp_queue_form($db, pp_missing_dependencies($db, (int)$peer['id']), 'Queue selected missing-dependency files', 'No missing-dependency matches from this child.');
            echo '</div>';

            echo '<div class="card"><h3>2. Other child files this parent does not have</h3><p class="muted">Manual archive-building list. These are child files whose package GUID is not currently verified in the parent catalog.</p>';
            pp_queue_form($db, pp_parent_missing_other($db, (int)$peer['id']), 'Queue selected archive-building files', 'No other missing-local files from this child.');
            echo '</div>';

            $shared = pp_shared_files($db, (int)$peer['id']);
            echo '<div class="card"><h3>3. Files both parent and child already have</h3><p class="muted">Review section only. Useful for hash/GUID comparison. You can still queue files manually here if you need a copy from the child.</p>';
            pp_queue_form($db, $shared, 'Queue selected shared files anyway', 'No shared files found with this child.');
            echo '</div>';
        }
    }

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
