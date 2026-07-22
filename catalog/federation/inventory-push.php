<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();

try {
    if (!catalog_require_admin_page('Child Inventory Access')) {
        exit;
    }

    catalog_head('Child Inventory Access');
    catalog_page_header(
        'Inventory Is Read by the Parent',
        'Manual child inventory pushes are no longer part of the federation workflow.',
        catalog_federation_links() + ['Federation Admin' => 'admin.php', 'Peers' => 'peers.php']
    );

    echo '<div class="card"><h2>No child action required</h2>';
    echo '<p>The paired parent/master authenticates directly to this child and reads its verified transferable inventory when required.</p>';
    echo '<p>The child does not push inventory, approve inventory access, or prepare an inventory export.</p>';
    echo '<p class="muted">The legacy API receiver remains available for compatibility with older deployments, but this administrator action has been retired.</p>';
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Child inventory access error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
