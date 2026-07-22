<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();

try {
    if (!catalog_require_admin_page('Parent Pull Authority')) {
        exit;
    }

    catalog_head('Parent Pull Authority');
    catalog_page_header(
        'Files Are Pulled by the Parent',
        'Manual child uploads to the parent are no longer part of the federation workflow.',
        catalog_federation_links() + ['Federation Admin' => 'admin.php', 'Peers' => 'peers.php']
    );

    echo '<div class="card"><h2>No child upload action</h2>';
    echo '<p>The parent/master reads this child inventory and selects any transferable file that the parent does not already have.</p>';
    echo '<p>The parent then downloads the selected file directly from the child without child approval.</p>';
    echo '<p class="muted">This prevents child-driven arbitrary uploads and keeps the parent in control as the federation source of truth.</p>';
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Parent pull authority error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
