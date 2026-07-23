<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Inventory Access')) {
        exit;
    }

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    catalog_head('Inventory Access');
    catalog_page_header(
        'Inventory Access',
        'Manual inventory pushes are not used by the current federation workflow.',
        catalog_federation_links() + ['Overview' => 'admin.php', 'Connections' => 'peers.php']
    );

    echo '<div class="card"><h2>Current mode: ' . catalog_h(ucfirst($role)) . '</h2>';
    if ($role === 'parent') {
        echo '<p>This parent reads inventories directly from its connected children.</p>';
        echo '<p><a class="button" href="peer-inventory.php">Open Child Inventories</a></p>';
    } elseif ($role === 'child') {
        echo '<p>No manual action is required. The connected parent reads this child inventory when needed.</p>';
        echo '<p><a class="button" href="peers.php?role=parent">View Parent Connection</a></p>';
    } else {
        echo '<p>Inventory exchange is unavailable while this site is in Standalone mode.</p>';
        echo '<p><a class="button" href="settings.php">Federation Settings</a></p>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Inventory access error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
