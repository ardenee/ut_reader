<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Requests a paired child to refresh its cached copy of this parent's inventory.
 * Why: Signed parent-to-child refresh transport belongs in the namespaced federation infrastructure layer, not a procedural compatibility helper.
 * Role: Infrastructure federation service preserving the existing bidirectional inventory-refresh protocol and logging.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationInventoryRefreshService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPeerSecret.php';
    }

    /** @return array<string,mixed> */
    public function requestChildRefreshParentInventory(int $peerId): array
    {
        $localRole = strtolower(trim((string)\fed_setting($this->db, 'site_role', 'standalone')));
        if ($localRole !== 'parent') {
            throw new RuntimeException('Only a parent may request a child to refresh the parent inventory.');
        }

        $child = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1',
            [$peerId]
        );
        if (!$child) {
            throw new RuntimeException('Active child connection not found.');
        }

        $siteId = trim((string)\fed_setting($this->db, 'site_id', ''));
        if ($siteId === '') {
            throw new RuntimeException('Local parent site ID is unavailable.');
        }

        $secret = \federation_peer_stored_signing_secret($this->db, $child);
        $url = rtrim((string)$child['site_url'], '/') . '/api/federation/inventory-refresh.php';
        $result = \fed_http_post_signed($url, $siteId, $secret, [
            'requested_at' => date('c'),
            'reason' => 'manual_bidirectional_refresh',
        ]);

        if (empty($result['ok'])) {
            throw new RuntimeException(
                'The child could not refresh the parent inventory: '
                . (string)($result['error'] ?? 'invalid response')
            );
        }

        \fed_log(
            $this->db,
            $peerId,
            null,
            'INFO',
            'REMOTE_PARENT_INVENTORY_REFRESH',
            'Child refreshed ' . (int)($result['received'] ?? 0) . ' parent inventory row(s).'
        );

        return $result;
    }
}
