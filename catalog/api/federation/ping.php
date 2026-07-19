<?php
declare(strict_types=1);


require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);
    fed_log($db, (int)$peer['id'], null, 'INFO', 'PING_OK', 'Signed federation ping accepted.');
    fed_json_response([
        'ok' => true,
        'message' => 'pong',
        'peer_id' => (int)$peer['id'],
        'peer_name' => (string)$peer['site_name'],
        'local' => fed_public_status($db),
    ]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
