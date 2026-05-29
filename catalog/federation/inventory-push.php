<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function build_inventory_payload(PDO $db): array
{
    $identity = fed_ensure_identity($db);
    $files = catalog_all($db, 'SELECT f.*, g.name game_name, p.engine_key profile_engine FROM ue_files f JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 WHERE f.scan_status="verified" ORDER BY f.id');
    $out = [];
    foreach ($files as $file) {
        $out[] = [
            'file_id' => (int)$file['id'],
            'game_id' => (int)$file['game_id'],
            'game_name' => (string)$file['game_name'],
            'engine_key' => (string)($file['profile_engine'] ?? ''),
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'extension' => (string)$file['extension'],
            'file_size' => (int)$file['file_size'],
            'md5' => (string)$file['md5'],
            'sha1' => (string)$file['sha1'],
            'package_guid' => (string)$file['package_guid'],
            'is_compressed' => (int)($file['is_compressed'] ?? 0),
            'compression_flags' => (int)($file['compression_flags'] ?? 0),
            'import_count' => (int)$file['import_count'],
            'export_count' => (int)$file['export_count'],
        ];
    }

    return [
        'site' => $identity,
        'generated_at' => date('c'),
        'file_count' => count($out),
        'files' => $out,
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_invpush');
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Active parent peer not found.');
        }
        $secret = (string)($peer['shared_secret_plain'] ?? '');
        if ($secret === '') {
            throw new RuntimeException('Selected parent peer has no stored API secret. Re-add the peer after update 005.');
        }

        $url = rtrim((string)$peer['site_url'], '/') . '/api/federation/inventory-push.php';
        $payload = build_inventory_payload($db);
        $result = fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), $secret, $payload);
        fed_log($db, (int)$peer['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'INVENTORY_PUSH_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
        $_SESSION['fed_invpush_result'] = $result;
        header('Location: inventory-push.php');
        exit;
    }

    if (!catalog_require_admin_page('Push Inventory')) {
        exit;
    }

    catalog_head('Push Inventory');
    catalog_page_header('Push Inventory to Parent', 'Child sites use this to send verified file metadata to their parent. No files are uploaded in this step.', catalog_federation_links() + ['Peers' => 'peers.php', 'Logs' => 'logs.php']);
	
    if (isset($_SESSION['fed_invpush_result'])) {
        echo '<div class="card"><h2>Last push result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_invpush_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_invpush_result']);
    }

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $count = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="verified"')['c'] ?? 0);
    echo '<div class="card"><h2>Inventory summary</h2><p>Verified local files ready to report: <strong>' . $count . '</strong></p></div>';
    echo '<div class="card"><h2>Run push</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent peer configured.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_invpush')) . '"><p><label>Parent peer<br><select name="peer_id">';
        foreach ($parents as $parent) {
            echo '<option value="' . (int)$parent['id'] . '">' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
        }
        echo '</select></label></p><button>Push inventory to parent</button></form>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Push inventory error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
