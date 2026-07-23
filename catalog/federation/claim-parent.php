<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Automatic Parent Pairing')) {
        exit;
    }

    $_SESSION['fed_join_main_result'] = [
        'ok' => true,
        'status' => (string)fed_setting($db, 'main_parent_join_status', 'none'),
        'message' => 'Manual parent claims are no longer required. Parent approval and pairing are handled on the Join a Parent page.',
    ];
    header('Location: join-main-parent.php');
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Automatic parent pairing error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
