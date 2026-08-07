<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for inventory refresh.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/FederationInventory.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        fed_json_response(['ok' => false, 'error' => 'Inventory refresh requests require POST.'], 405);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $body = fed_read_request_body(32768);
    $peer = fed_require_signed_peer($db, $body);

    $localRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $peerRole = strtolower(trim((string)($peer['peer_role'] ?? '')));
    if ($localRole !== 'child' || $peerRole !== 'parent') {
        fed_json_response(['ok' => false, 'error' => 'Only the paired parent may request this child to refresh its parent inventory.'], 403);
    }

    // Decode the signed request even though no user-controlled values are needed.
    fed_decode_json_object($body);
    $result = federation_pull_inventory_from_parent($db, (int)$peer['id']);

    fed_log(
        $db,
        (int)$peer['id'],
        null,
        'INFO',
        'PARENT_INVENTORY_REFRESH_REQUESTED',
        'Refreshed ' . (int)$result['received'] . ' parent inventory row(s); removed ' . (int)$result['removed_stale'] . ' stale row(s).'
    );

    fed_json_response([
        'ok' => true,
        'received' => (int)$result['received'],
        'removed_stale' => (int)$result['removed_stale'],
        'pages' => (int)$result['pages'],
        'synchronized_at' => (string)$result['synchronized_at'],
    ]);
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] remote inventory refresh failed: ' . get_class($error) . ': ' . $error->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Remote inventory refresh failed.', 'reference' => catalog_request_id()], 500);
}
