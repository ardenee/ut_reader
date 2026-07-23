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

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    catalog_head('Parent Pull');
    catalog_page_header(
        'Parent Pull From Children',
        'Parent/master downloads do not require child approval. Files are selected from connected child inventories.',
        catalog_federation_links() + [
            'Child Inventories' => 'peer-inventory.php',
            'Run Transfer Queue' => 'transfer-run.php',
            'Import Downloaded Files' => 'import-run.php',
        ]
    );

    echo '<div class="card"><h2>Server mode</h2><p>This server is running in <strong>' . catalog_h(ucfirst($role)) . '</strong> mode.</p></div>';
    if ($role !== 'parent') {
        echo '<div class="card"><h2>Parent Pull disabled</h2>';
        echo '<p>Only a server running in Parent mode can pull files from child sites. A Child site cannot have children.</p>';
        echo '<p><a class="button" href="settings.php">Federation Settings</a> <a class="button" href="peers.php?role=parent">Parent Connection</a></p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Select files from child inventory</h2>';
    echo '<p>The parent may download any non-protected child file that is not already present locally. Files already held by the parent are not offered.</p>';
    echo '<p><a class="button" href="peer-inventory.php">Open Child Inventories</a></p>';
    echo '<ul><li><strong>Needed</strong>: matches a current missing dependency on the parent.</li><li><strong>Missing</strong>: another child file that the parent does not have.</li></ul>';
    echo '</div>';

    $jobs = catalog_all(
        $db,
        'SELECT j.*, p.site_name peer_name
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         WHERE j.direction="parent_pull_from_child"
         ORDER BY j.created_at DESC, j.id DESC
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
