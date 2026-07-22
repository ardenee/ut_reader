<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Parent Pull')) {
        exit;
    }

    catalog_head('Parent Pull');
    catalog_page_header(
        'Parent Pull From Children',
        'Parent/master downloads do not require child approval. Select needed dependency files or other files absent from the parent on the Child Inventory page.',
        catalog_federation_links() + [
            'Child Inventory' => 'peer-inventory.php',
            'Run Transfer Queue' => 'transfer-run.php',
            'Import Downloaded Files' => 'import-run.php',
        ]
    );

    echo '<div class="card"><h2>Select files from child inventory</h2>';
    echo '<p>The parent may download any non-protected child file that is not already present locally. Files already held by the parent are not offered.</p>';
    echo '<p><a class="button" href="peer-inventory.php">Open Child Inventory</a></p>';
    echo '<ul><li><strong>Needed</strong>: matches a current missing dependency on the parent.</li><li><strong>Missing</strong>: another child file that the parent does not have.</li></ul>';
    echo '</div>';

    $jobs = catalog_all(
        $db,
        'SELECT j.*, p.site_name peer_name
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         WHERE j.direction="parent_pull_from_child"
         ORDER BY j.created_at DESC
         LIMIT 200'
    );

    echo '<div class="card"><h2>Recent parent pull jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No parent pull jobs have been queued.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Child</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
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
