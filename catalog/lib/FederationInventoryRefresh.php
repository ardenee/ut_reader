<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for federation inventory refresh.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/FederationPeerSecret.php';

/**
 * Ask a paired child to refresh its cached copy of this parent's inventory.
 * The child performs the normal paged, signed inventory pull, so large parent
 * catalogs are not sent in one request.
 *
 * @return array<string,mixed>
 */
function federation_request_child_refresh_parent_inventory(PDO $db, int $peerId): array
{
    $localRole = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    if ($localRole !== 'parent') {
        throw new RuntimeException('Only a parent may request a child to refresh the parent inventory.');
    }

    $child = catalog_one(
        $db,
        'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1',
        [$peerId]
    );
    if (!$child) {
        throw new RuntimeException('Active child connection not found.');
    }

    $siteId = trim((string)fed_setting($db, 'site_id', ''));
    if ($siteId === '') {
        throw new RuntimeException('Local parent site ID is unavailable.');
    }

    $secret = federation_peer_stored_signing_secret($db, $child);
    $url = rtrim((string)$child['site_url'], '/') . '/api/federation/inventory-refresh.php';
    $result = fed_http_post_signed($url, $siteId, $secret, [
        'requested_at' => date('c'),
        'reason' => 'manual_bidirectional_refresh',
    ]);

    if (empty($result['ok'])) {
        throw new RuntimeException('The child could not refresh the parent inventory: ' . (string)($result['error'] ?? 'invalid response'));
    }

    fed_log(
        $db,
        $peerId,
        null,
        'INFO',
        'REMOTE_PARENT_INVENTORY_REFRESH',
        'Child refreshed ' . (int)($result['received'] ?? 0) . ' parent inventory row(s).'
    );

    return $result;
}
