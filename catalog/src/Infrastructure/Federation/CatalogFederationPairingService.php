<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Stores approved parent peers and completes automatic parent-claim pairing on child sites.
 * Why: Peer persistence, role-setting updates, fingerprint validation and remote claim transport form one federation
 *      pairing use case and should not live in a procedural catalog/lib file.
 * Role: Infrastructure federation pairing service preserving the existing automatic-join contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationPairingService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
    }

    /** @param array<string,mixed> $parent */
    public function storeParentPeer(array $parent, string $source = 'automatic_join'): int
    {
        $siteName = trim((string)($parent['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string)($parent['site_url'] ?? '')), '/');
        $siteId = strtolower(trim((string)($parent['site_id'] ?? '')));
        $fingerprint = strtoupper(trim((string)($parent['site_fingerprint'] ?? '')));
        $secret = trim((string)($parent['shared_secret'] ?? ''));

        if ($siteName === '' || $siteUrl === '' || $siteId === '' || $fingerprint === '' || $secret === '') {
            throw new RuntimeException('Approved parent response is missing pairing values.');
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('Approved parent URL is invalid.');
        }

        $expected = \fed_site_fingerprint($siteUrl, $siteId);
        if (!hash_equals($expected, $fingerprint)) {
            throw new RuntimeException('Approved parent fingerprint does not match its URL and site ID.');
        }

        $secretFields = \fed_prepare_peer_secret($secret);
        $permissions = [
            'parent_is_master' => true,
            'parent_inventory_read_without_child_approval' => true,
            'parent_pull_without_child_approval' => true,
            'child_download_requires_parent_approval' => true,
            'child_download_scope' => 'missing_dependencies_only',
            'created_by' => $source,
        ];
        $permissionsJson = json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($permissionsJson === false) {
            throw new RuntimeException('Could not encode parent peer permissions.');
        }

        $existing = \catalog_one(
            $this->db,
            'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1',
            [$siteId]
        );
        if ($existing) {
            $this->db->prepare(
                'UPDATE ue_federation_peers '
                . 'SET peer_role="parent", site_name=?, site_url=?, peer_fingerprint=?, '
                . 'shared_secret_hash=?, shared_secret_plain=?, permissions_json=?, is_active=1 '
                . 'WHERE peer_site_id=?'
            )->execute([
                $siteName,
                $siteUrl,
                $fingerprint,
                $secretFields['hash'],
                $secretFields['stored'],
                $permissionsJson,
                $siteId,
            ]);
            $peerId = (int)$existing['id'];
        } else {
            $statement = $this->db->prepare(
                'INSERT INTO ue_federation_peers('
                . 'peer_role, site_name, site_url, peer_site_id, peer_fingerprint, '
                . 'shared_secret_hash, shared_secret_plain, permissions_json, is_active'
                . ') VALUES("parent",?,?,?,?,?,?,?,1)'
            );
            $statement->execute([
                $siteName,
                $siteUrl,
                $siteId,
                $fingerprint,
                $secretFields['hash'],
                $secretFields['stored'],
                $permissionsJson,
            ]);
            $peerId = (int)$this->db->lastInsertId();
        }

        \fed_set_setting($this->db, 'main_parent_url', $siteUrl);
        \fed_set_setting($this->db, 'site_role', 'child');
        \fed_set_setting($this->db, 'child_enabled', '1');
        \fed_set_setting($this->db, 'parent_enabled', '0');
        \fed_set_setting($this->db, 'join_requests_enabled', '0');
        \fed_set_setting($this->db, 'main_parent_join_status', 'claimed');
        \fed_set_setting(
            $this->db,
            'main_parent_join_status_message',
            'Parent approved and pairing completed automatically.'
        );
        \fed_set_setting($this->db, 'main_parent_join_admin_notes', '');
        \fed_set_setting($this->db, 'main_parent_join_request_token', '');

        \fed_log(
            $this->db,
            $peerId,
            null,
            'INFO',
            'PARENT_PAIRED_AUTOMATICALLY',
            'Approved parent pairing stored automatically. Parent/master role enforced.'
        );

        return $peerId;
    }

    /** @return array<string,mixed> */
    public function autoClaimParent(string $parentUrl, int $requestId, string $requestToken): array
    {
        $parentUrl = rtrim(trim($parentUrl), '/');
        if ($parentUrl === '' || $requestId <= 0 || trim($requestToken) === '') {
            throw new RuntimeException('Stored automatic pairing details are incomplete.');
        }

        $body = json_encode(
            ['request_id' => $requestId, 'token' => $requestToken],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $allowSelfSigned = (string)\fed_setting(
            $this->db,
            'allow_self_signed_federation_certificates',
            '0'
        ) === '1';
        CatalogFederationHttpClient::configureTesting($allowSelfSigned);

        $result = CatalogFederationHttpClient::fromRuntime()->postJson(
            $parentUrl . '/api/federation/join-claim.php',
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: UnrealFileCatalogFederation/2.0',
            ],
            $body,
            1048576,
            60
        );

        if (empty($result['ok']) || empty($result['parent']) || !is_array($result['parent'])) {
            throw new RuntimeException(
                'Parent automatic pairing failed: ' . ($result['error'] ?? 'unknown error')
            );
        }

        $peerId = $this->storeParentPeer($result['parent'], 'automatic_join_approval');
        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'claimed',
            'message' => 'Parent approved and pairing completed automatically.',
            'peer_id' => $peerId,
            'parent' => [
                'site_name' => (string)($result['parent']['site_name'] ?? ''),
                'site_url' => (string)($result['parent']['site_url'] ?? ''),
                'site_id' => (string)($result['parent']['site_id'] ?? ''),
            ],
        ];
    }
}
