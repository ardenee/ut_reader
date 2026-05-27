<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';

function ml_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function ml_csrf(): string
{
    $_SESSION['mirror_links_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['mirror_links_csrf'];
}

function ml_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['mirror_links_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!ml_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        ml_check_csrf();
        $action = (string)($_POST['action'] ?? 'add_manual');
        if ($action === 'add_manual') {
            $fileId = (int)($_POST['file_id'] ?? 0);
            $providerId = (int)($_POST['provider_id'] ?? 0);
            $url = trim((string)($_POST['external_url'] ?? ''));
            $days = (int)($_POST['expiry_days'] ?? 7);
            if ($fileId <= 0 || $providerId <= 0 || $url === '') {
                throw new RuntimeException('File, provider and URL are required.');
            }
            external_create_manual_link($db, $fileId, $providerId, $url, $_SESSION['user']['id'] ?? null, $days);
            $_SESSION['mirror_links_flash'] = 'Manual external mirror link added.';
        } elseif ($action === 'expire') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE ue_external_download_links SET status="expired" WHERE id=?')->execute([$id]);
            $_SESSION['mirror_links_flash'] = 'Mirror link expired.';
        } elseif ($action === 'mark_broken') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE ue_external_download_links SET status="broken", error_message="Marked broken by admin." WHERE id=?')->execute([$id]);
            $_SESSION['mirror_links_flash'] = 'Mirror link marked broken.';
        }
        header('Location: mirror-links.php');
        exit;
    }

    catalog_head('External Mirror Links');

    if (!ml_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['mirror_links_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['mirror_links_flash']) . '</strong></div>';
        unset($_SESSION['mirror_links_flash']);
    }

    $fileId = (int)($_GET['file_id'] ?? 0);
    echo '<div class="card"><h1>External Mirror Links</h1><p class="muted">Admin-managed external download cache. Active links are reused until expiry.</p><p><a class="button" href="admin.php">Catalog Admin</a> <a class="button" href="mirror-providers.php">Providers</a> <a class="button" href="mirror-queue.php">Mirror Queue</a></p></div>';

    $providers = catalog_all($db, 'SELECT * FROM ue_external_download_providers WHERE is_active=1 ORDER BY priority, provider_name');
    echo '<div class="card"><h2>Add manual external link</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(ml_csrf()) . '"><input type="hidden" name="action" value="add_manual">';
    echo '<p><label>File ID<br><input name="file_id" value="' . ($fileId ?: '') . '" required style="width:120px"></label></p>';
    echo '<p><label>Provider<br><select name="provider_id">';
    foreach ($providers as $p) {
        echo '<option value="' . (int)$p['id'] . '">' . catalog_h($p['provider_name']) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>External URL<br><input name="external_url" required style="min-width:760px"></label></p>';
    echo '<p><label>Expiry days<br><input name="expiry_days" value="7" style="width:80px"></label></p><p><button>Add active mirror link</button></p></form></div>';

    $where = $fileId > 0 ? 'WHERE l.file_id=' . $fileId : '';
    $links = catalog_all($db, 'SELECT l.*, p.provider_name, p.provider_key, f.package_name, f.original_name, f.md5 FROM ue_external_download_links l JOIN ue_external_download_providers p ON p.id=l.provider_id JOIN ue_files f ON f.id=l.file_id ' . $where . ' ORDER BY l.created_at DESC LIMIT 500');
    echo '<div class="card"><h2>Mirror links</h2>';
    if (!$links) {
        echo '<p class="muted">No mirror links found.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>File</th><th>Provider</th><th>Status</th><th>URL</th><th>Expires</th><th>Requests</th><th>Error</th><th>Action</th></tr>';
        foreach ($links as $l) {
            echo '<tr>';
            echo '<td class="mono">' . (int)$l['id'] . '</td>';
            echo '<td><a href="file-info.php?id=' . (int)$l['file_id'] . '" target="_blank">' . catalog_h($l['package_name'] . ' / ' . $l['original_name']) . '</a><br><span class="mono small">' . catalog_h($l['md5']) . '</span></td>';
            echo '<td>' . catalog_h($l['provider_name']) . '</td><td>' . catalog_h($l['status']) . '</td>';
            echo '<td class="path"><a href="' . catalog_h($l['external_url']) . '" target="_blank" rel="noopener">' . catalog_h($l['external_url']) . '</a></td>';
            echo '<td>' . catalog_h($l['expires_at']) . '</td><td>' . (int)$l['requested_count'] . '</td><td class="path">' . catalog_h($l['error_message']) . '</td>';
            echo '<td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(ml_csrf()) . '"><input type="hidden" name="id" value="' . (int)$l['id'] . '"><button name="action" value="expire">Expire</button> <button name="action" value="mark_broken">Broken</button></form></td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Mirror links error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
