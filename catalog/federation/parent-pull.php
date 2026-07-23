<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

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
        'Parent/master downloads do not require child approval. Files are selected from connected child inventories under the parent-controlled base-game policy.',
        catalog_federation_links() + [
            'Child Inventories' => 'peer-inventory.php',
            'Run Transfer Queue' => 'transfer-run.php',
            'Import Downloaded Files' => 'import-run.php',
        ]
    );

    if ($role !== 'parent') {
        echo '<div class="card"><h2>Parent Pull disabled</h2>';
        echo '<p>Only a server running in Parent mode can pull files from child sites. A Child site cannot have children.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Select files from child inventory</h2>';
    echo '<p>The parent may download child files that are not already present locally. ' . catalog_h(federation_base_game_policy_label($db)) . '</p>';
    echo '<p><a class="button" href="peer-inventory.php">Open Child Inventories</a></p>';
    echo '<ul><li><strong>Parent Dependency Needs</strong>: files that satisfy current missing dependencies, including base-game dependency exceptions.</li><li><strong>Parent Needs</strong>: other absent files after applying the ordinary base-game policy.</li></ul>';
    echo '</div>';

    $jobs = catalog_all(
        $db,
        'SELECT j.*, p.site_name peer_name, pf.package_name, pf.original_name, COALESCE(pf.is_base_game,0) is_base_game
         FROM ue_federation_transfer_jobs j
         JOIN ue_federation_peers p ON p.id=j.peer_id
         LEFT JOIN ue_federation_peer_files pf ON pf.peer_id=j.peer_id AND pf.remote_file_id=j.remote_file_id
         WHERE j.direction="parent_pull_from_child"
         GROUP BY j.id
         ORDER BY j.created_at DESC, j.id DESC
         LIMIT 200'
    );

    echo '<div class="card"><h2>Recent parent pull jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No parent pull jobs have been queued.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Child</th><th>File</th><th>Status</th><th>Bytes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            $fileLabel = trim((string)($job['package_name'] ?? '') . ' / ' . (string)($job['original_name'] ?? ''), ' /');
            $baseBadge = !empty($job['is_base_game']) ? ' <span class="pill amber">base-game</span>' : '';
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($fileLabel !== '' ? $fileLabel : 'remote file #' . (int)$job['remote_file_id']) . $baseBadge . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
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
