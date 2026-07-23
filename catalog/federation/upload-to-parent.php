<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Transfers')) {
        exit;
    }

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    catalog_head('Federation Transfers');
    catalog_page_header(
        'Federation Transfers',
        'Manual child uploads to a parent are not used by the current federation workflow.',
        catalog_federation_links() + ['Overview' => 'admin.php', 'Transfer Queue' => 'queue.php']
    );

    echo '<div class="card"><h2>Current mode: ' . catalog_h(ucfirst($role)) . '</h2>';
    if ($role === 'parent') {
        echo '<p>Select files from a connected child inventory. The parent then pulls those files directly.</p>';
        echo '<p><a class="button" href="peer-inventory.php">Open Child Inventories</a></p>';
    } elseif ($role === 'child') {
        echo '<p>This child does not push arbitrary files to its parent. Missing files are requested from the parent, while the parent independently pulls child files it needs.</p>';
        echo '<p><a class="button" href="missing-files.php">Open Missing Files</a></p>';
    } else {
        echo '<p>Federation transfers are unavailable while this site is in Standalone mode.</p>';
        echo '<p><a class="button" href="settings.php">Federation Settings</a></p>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation transfers error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
