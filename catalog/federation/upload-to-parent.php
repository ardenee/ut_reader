<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_upload_parent');
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $fileIds = array_values(array_unique(array_map('intval', $_POST['file_ids'] ?? [])));
        $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
        if (!$parent) {
            throw new RuntimeException('Active parent peer not found.');
        }
        if (!$fileIds) {
            throw new RuntimeException('Select at least one file to queue.');
        }

        $insert = $db->prepare('INSERT INTO ue_federation_transfer_jobs(peer_id,direction,remote_file_id,local_file_id,status,speed_limit_kbps,wait_after_seconds,bytes_total) VALUES(?,"upload_to_parent",?,?,"queued",?,?,?)');
        $queued = 0;
        foreach ($fileIds as $fileId) {
            $file = catalog_one($db, 'SELECT id,file_size FROM ue_files WHERE id=? AND scan_status="verified"', [$fileId]);
            if (!$file) {
                continue;
            }
            $exists = catalog_one($db, 'SELECT id FROM ue_federation_transfer_jobs WHERE peer_id=? AND direction="upload_to_parent" AND local_file_id=? AND status IN ("queued","running","downloaded") LIMIT 1', [$peerId, $fileId]);
            if ($exists) {
                continue;
            }
            $insert->execute([$peerId, $fileId, $fileId, (int)fed_setting($db, 'max_upload_kbps', '0'), (int)fed_setting($db, 'delay_between_uploads_seconds', '5'), (int)$file['file_size']]);
            $queued++;
        }
        fed_log($db, $peerId, null, 'INFO', 'UPLOAD_TO_PARENT_QUEUE', 'Queued ' . $queued . ' upload-to-parent job(s).');
        $_SESSION['fed_upload_parent_flash'] = 'Queued ' . $queued . ' upload-to-parent job(s).';
        header('Location: upload-to-parent.php');
        exit;
    }

    if (!catalog_require_admin_page('Upload to Parent')) {
        exit;
    }

    catalog_head('Upload to Parent');
    catalog_flash($_SESSION['fed_upload_parent_flash'] ?? null);
    unset($_SESSION['fed_upload_parent_flash']);

    catalog_page_header('Upload Files to Parent', 'Child-side page. Queue verified local files for controlled upload to the parent. Parent receives uploads into federation incoming, then imports them with the normal import runner.', catalog_federation_links() + ['Bulk Worker' => 'worker-run.php', 'Queue' => 'queue.php']);
	
    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    if (!$parents) {
        echo '<div class="card"><p class="muted">No active parent peer configured.</p></div>';
        catalog_foot();
        exit;
    }

    $files = catalog_all($db, 'SELECT f.*, g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.scan_status="verified" ORDER BY g.name, f.package_name, f.original_name LIMIT 1000');
    echo '<div class="card"><h2>Queue uploads</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_upload_parent')) . '">';
    echo '<p><label>Parent<br><select name="peer_id">';
    foreach ($parents as $parent) {
        echo '<option value="' . (int)$parent['id'] . '">' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
    }
    echo '</select></label></p>';

    if (!$files) {
        echo '<p class="muted">No verified local files found.</p>';
    } else {
        echo '<table><tr><th>Upload</th><th>Game</th><th>Package</th><th>File</th><th>GUID</th><th>MD5</th><th>Size</th></tr>';
        foreach ($files as $file) {
            echo '<tr><td><input type="checkbox" name="file_ids[]" value="' . (int)$file['id'] . '"></td><td>' . catalog_h($file['game_name']) . '</td><td class="mono">' . catalog_h($file['package_name']) . '</td><td>' . catalog_h($file['original_name']) . '</td><td class="mono small">' . catalog_h($file['package_guid']) . '</td><td class="mono small">' . catalog_h($file['md5']) . '</td><td>' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td></tr>';
        }
        echo '</table><p><button>Queue selected uploads to parent</button></p>';
    }
    echo '</form></div>';

    $jobs = catalog_all($db, 'SELECT j.*, p.site_name peer_name, f.package_name, f.original_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id LEFT JOIN ue_files f ON f.id=j.local_file_id WHERE j.direction="upload_to_parent" ORDER BY j.created_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent upload-to-parent jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No upload-to-parent jobs yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Parent</th><th>File</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h(($job['package_name'] ?? '') . ' / ' . ($job['original_name'] ?? '')) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h(catalog_bytes((int)$job['bytes_done']) . ' / ' . catalog_bytes((int)$job['bytes_total'])) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Upload to parent error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
