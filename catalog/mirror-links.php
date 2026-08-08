<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes administrator-managed external mirror links.
 * Why: Link mutation/read persistence belongs to the shared external mirror admin service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $service = new CatalogExternalMirrorAdminService($db, $config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('mirror_links');
        $flash = $service->handleLinkAction(
            (string)($_POST['action'] ?? 'add_manual'),
            $_POST,
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
        );
        if ($flash !== '') {
            $_SESSION['mirror_links_flash'] = $flash;
        }
        header('Location: mirror-links.php');
        exit;
    }

    if (!catalog_require_admin_page('External Mirror Links')) {
        exit;
    }

    catalog_head('External Mirror Links');
    catalog_flash($_SESSION['mirror_links_flash'] ?? null);
    unset($_SESSION['mirror_links_flash']);

    $fileId = (int)($_GET['file_id'] ?? 0);
    catalog_page_header('External Mirror Links', 'Admin-managed external download cache. Active links are reused until expiry.', ['Catalog Admin' => 'dashboard.php', 'Providers' => 'mirror-providers.php', 'Mirror Queue' => 'mirror-queue.php', 'Downloads' => 'download-admin.php']);

    $providers = $service->providers(true);
    echo '<div class="card"><h2>Add manual external link</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_links')) . '"><input type="hidden" name="action" value="add_manual">';
    echo '<p><label>File ID<br><input name="file_id" value="' . ($fileId ?: '') . '" required style="width:120px"></label></p>';
    echo '<p><label>Provider<br><select name="provider_id">';
    foreach ($providers as $p) {
        echo '<option value="' . (int)$p['id'] . '">' . catalog_h($p['provider_name']) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>External URL<br><input name="external_url" required style="min-width:760px"></label></p>';
    echo '<p><label>Expiry days<br><input name="expiry_days" value="7" style="width:80px"></label></p><p><button>Add active mirror link</button></p></form></div>';

    $links = $service->links($fileId, 500);
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
            echo '<td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_links')) . '"><input type="hidden" name="id" value="' . (int)$l['id'] . '"><button name="action" value="expire">Expire</button> <button name="action" value="mark_broken">Broken</button></form></td>';
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
