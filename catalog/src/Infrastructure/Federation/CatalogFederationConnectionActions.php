<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes administrator federation-connection actions without rendering HTML.
 * Why: Pairing protocol calls, role transitions and peer persistence must not be embedded in the Connections page.
 * Role: Infrastructure/application orchestration over namespaced federation state and inventory services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationConnectionActions
{
    private const OFFICIAL_PARENT_URL = 'https://unrealdb.com';

    private readonly CatalogFederationStateService $state;
    private readonly CatalogFederationPeerInventorySyncService $peerInventorySync;
    private readonly CatalogFederationLocalInventoryService $localInventory;
    private readonly CatalogFederationInventoryRefreshService $inventoryRefresh;

    public function __construct(private readonly PDO $db)
    {
        $this->state = new CatalogFederationStateService($db);
        $this->peerInventorySync = new CatalogFederationPeerInventorySyncService($db);
        $this->localInventory = new CatalogFederationLocalInventoryService($db);
        $this->inventoryRefresh = new CatalogFederationInventoryRefreshService($db);

        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPairing.php';
        require_once $root . '/lib/FederationPeerSecret.php';
    }

    /** @param array<string,mixed> $input */
    public function handle(array $input, ?int $userId): string
    {
        $action = strtolower(trim((string)($input['action'] ?? '')));

        if ($action === 'submit_parent') {
            if (!$this->state->canJoinParent()) {
                throw new RuntimeException('Disconnect/remove all federation relationships before joining a parent.');
            }
            $identity = \fed_ensure_identity($this->db);
            $mode = strtolower(trim((string)($input['parent_mode'] ?? 'manual')));
            $parentUrl = $this->parentUrl(
                $mode === 'official' ? self::OFFICIAL_PARENT_URL : (string)($input['parent_url'] ?? '')
            );
            if (rtrim(strtolower((string)$identity['site_url']), '/') === strtolower($parentUrl)) {
                throw new RuntimeException('This deployment cannot join itself.');
            }
            $requestToken = \fed_random_secret();
            $result = $this->postJson($parentUrl . '/api/federation/join-request-submit.php', [
                'site_name' => (string)$identity['site_name'],
                'site_url' => (string)$identity['site_url'],
                'site_id' => (string)$identity['site_id'],
                'site_fingerprint' => (string)$identity['site_fingerprint'],
                'request_token' => $requestToken,
                'contact_name' => trim((string)($input['contact_name'] ?? '')),
                'contact_email' => trim((string)($input['contact_email'] ?? '')),
                'notes' => trim((string)($input['notes'] ?? 'Request to join this federation parent.')),
            ]);
            if (empty($result['ok'])) {
                throw new RuntimeException('Parent rejected join request: ' . (string)($result['error'] ?? 'unknown error'));
            }
            \fed_set_setting($this->db, 'main_parent_url', $parentUrl);
            \fed_set_setting($this->db, 'main_parent_join_request_id', (string)($result['request_id'] ?? '0'));
            \fed_set_setting($this->db, 'main_parent_join_request_token', $requestToken);
            $this->storeJoinResult($result);
            $this->state->setSiteRole('standalone');
            \fed_log($this->db, null, null, 'INFO', 'PARENT_JOIN_SUBMITTED', 'Join request submitted to ' . $parentUrl . '; local role remains Standalone until pairing completes.');
            return 'Parent join request submitted. This server remains Standalone until the parent approves and pairing completes.';
        }

        if ($action === 'poll_parent') {
            $result = $this->pollParent();
            return (string)($result['message'] ?? 'Parent join status refreshed.');
        }

        if ($action === 'cancel_parent_join') {
            $parentUrl = trim((string)\fed_setting($this->db, 'main_parent_url', ''));
            $requestId = (int)\fed_setting($this->db, 'main_parent_join_request_id', '0');
            $requestToken = trim((string)\fed_setting($this->db, 'main_parent_join_request_token', ''));
            $identity = \fed_ensure_identity($this->db);
            $remoteMessage = '';
            if ($parentUrl !== '' && $requestId > 0 && $requestToken !== '') {
                try {
                    $remote = $this->postJson($this->parentUrl($parentUrl) . '/api/federation/join-request-cancel.php', [
                        'request_id' => $requestId,
                        'site_id' => (string)$identity['site_id'],
                        'request_token' => $requestToken,
                    ]);
                    $remoteMessage = !empty($remote['ok'])
                        ? ' The parent request was cancelled.'
                        : ' The local request was cleared; parent cancellation returned an error.';
                } catch (Throwable) {
                    $remoteMessage = ' The local request was cleared; the parent could not be contacted.';
                }
            }
            $this->state->clearParentJoinState();
            $this->state->setSiteRole('standalone');
            \fed_log($this->db, null, null, 'INFO', 'PARENT_JOIN_CANCELLED', 'Pending parent join request cancelled locally.');
            return 'Pending parent join request removed.' . $remoteMessage;
        }

        if ($action === 'set_join_requests') {
            if (!$this->state->canAcceptChildren()) {
                throw new RuntimeException('A Child or a server joining a Parent cannot accept child connections.');
            }
            $enabled = (string)($input['enabled'] ?? '0') === '1' ? '1' : '0';
            \fed_set_setting($this->db, 'join_requests_enabled', $enabled);
            return $enabled === '1' ? 'Child join requests enabled.' : 'Child join requests disabled.';
        }

        if (in_array($action, ['approve_child', 'deny_child'], true)) {
            $requestId = (int)($input['request_id'] ?? 0);
            $request = \catalog_one($this->db, 'SELECT * FROM ue_federation_join_requests WHERE id=?', [$requestId]);
            if (!$request) {
                throw new RuntimeException('Child join request not found.');
            }
            if ($action === 'approve_child') {
                $this->approveChild(
                    $request,
                    trim((string)($input['admin_notes'] ?? 'Approved by parent administrator.')),
                    $userId
                );
                return 'Child approved. This server is now a Parent.';
            }

            $this->db->prepare(
                'UPDATE ue_federation_join_requests SET status="denied", admin_notes=?, '
                . 'claim_token_hash=NULL, claim_expires_at=NULL WHERE id=?'
            )->execute([
                trim((string)($input['admin_notes'] ?? 'Denied by parent administrator.')),
                $requestId,
            ]);
            \fed_log($this->db, null, null, 'INFO', 'JOIN_REQUEST_DENIED', 'Child join request #' . $requestId . ' denied.');
            return 'Child join request denied.';
        }

        if (in_array($action, ['toggle_peer', 'update_child', 'remove_peer', 'test_peer', 'refresh_peer'], true)) {
            $peer = $this->peer((int)($input['peer_id'] ?? 0));
            $role = $this->state->siteRole();
            if (($role === 'child' && (string)$peer['peer_role'] !== 'parent')
                || ($role === 'parent' && (string)$peer['peer_role'] !== 'child')) {
                throw new RuntimeException('This connection does not belong to the current federation role.');
            }

            if ($action === 'toggle_peer') {
                $newState = (int)$peer['is_active'] === 1 ? 0 : 1;
                $this->db->prepare('UPDATE ue_federation_peers SET is_active=? WHERE id=?')
                    ->execute([$newState, (int)$peer['id']]);
                return $newState ? 'Connection enabled.' : 'Connection disabled.';
            }

            if ($action === 'update_child') {
                if ($role !== 'parent' || (string)$peer['peer_role'] !== 'child') {
                    throw new RuntimeException('Only a Parent may edit an established child.');
                }
                $name = trim((string)($input['site_name'] ?? ''));
                $url = rtrim(trim((string)($input['site_url'] ?? '')), '/');
                if ($name === '' || $url === '') {
                    throw new RuntimeException('Child name and URL are required.');
                }
                $this->db->prepare('UPDATE ue_federation_peers SET site_name=?, site_url=? WHERE id=?')
                    ->execute([$name, $url, (int)$peer['id']]);
                return 'Child connection updated.';
            }

            if ($action === 'remove_peer') {
                $this->state->removePeer($peer);
                return (string)$peer['peer_role'] === 'parent'
                    ? 'Disconnected from parent.'
                    : 'Child connection removed.';
            }

            if ($action === 'test_peer') {
                $result = \fed_http_post_signed(
                    rtrim((string)$peer['site_url'], '/') . '/api/federation/ping.php',
                    (string)\fed_setting($this->db, 'site_id', ''),
                    \federation_peer_stored_signing_secret($this->db, $peer),
                    ['tested_at' => date('c')]
                );
                if (empty($result['ok'])) {
                    throw new RuntimeException('Connection test failed: ' . (string)($result['error'] ?? 'unknown error'));
                }
                return 'Connection test succeeded: ' . (string)($result['message'] ?? 'pong');
            }

            $local = $this->peerInventorySync->pullFromPeer((int)$peer['id']);
            if ((string)$peer['peer_role'] === 'child') {
                $remote = $this->inventoryRefresh->requestChildRefreshParentInventory((int)$peer['id']);
                return 'Inventories refreshed: received ' . (int)($local['received'] ?? 0)
                    . ' child rows; child received ' . (int)($remote['received'] ?? 0) . ' parent rows.';
            }
            $push = $this->localInventory->pushToParent((int)$peer['id']);
            return 'Parent inventory refreshed; local inventory push result: '
                . (!empty($push['ok']) ? 'success' : 'failed') . '.';
        }

        if ($action === 'stop_parent') {
            if ($this->state->siteRole() !== 'parent') {
                throw new RuntimeException('This server is not a Parent.');
            }
            if ($this->state->childPeers(false) !== []) {
                throw new RuntimeException('Remove all established children before leaving Parent mode.');
            }
            $pending = (int)(\catalog_one(
                $this->db,
                'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status IN ("pending","approved")'
            )['c'] ?? 0);
            if ($pending > 0) {
                throw new RuntimeException('Deny or expire all pending/approved child requests before leaving Parent mode.');
            }
            \fed_set_setting($this->db, 'join_requests_enabled', '0');
            $this->state->setSiteRole('standalone');
            return 'Parent mode disabled. This server is now Standalone.';
        }

        throw new RuntimeException('Unknown federation connection action.');
    }

    private function parentUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException(
                'Parent URL must be a plain HTTPS URL without credentials, query parameters, or a fragment.'
            );
        }
        return $url;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function postJson(string $url, array $payload): array
    {
        \TrustedHttpSourceClient::configureFederationTesting(
            (string)\fed_setting($this->db, 'allow_self_signed_federation_certificates', '0') === '1'
        );
        return \TrustedHttpSourceClient::postJson(
            $url,
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: UnrealFileCatalogFederation/2.0',
            ],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            1048576,
            60
        );
    }

    /** @param array<string,mixed> $result */
    private function storeJoinResult(array $result): void
    {
        $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
        \fed_set_setting($this->db, 'main_parent_join_status', $status !== '' ? $status : 'unknown');
        \fed_set_setting($this->db, 'main_parent_join_status_message', trim((string)($result['message'] ?? '')));
        \fed_set_setting($this->db, 'main_parent_join_admin_notes', trim((string)($result['admin_notes'] ?? '')));
    }

    /** @return array<string,mixed> */
    private function pollParent(): array
    {
        $identity = \fed_ensure_identity($this->db);
        $parentUrl = $this->parentUrl((string)\fed_setting($this->db, 'main_parent_url', ''));
        $requestId = (int)\fed_setting($this->db, 'main_parent_join_request_id', '0');
        $requestToken = trim((string)\fed_setting($this->db, 'main_parent_join_request_token', ''));
        if ($requestId <= 0 || $requestToken === '') {
            throw new RuntimeException('No complete pending parent join request is stored.');
        }

        $result = $this->postJson($parentUrl . '/api/federation/join-request-status.php', [
            'request_id' => $requestId,
            'site_id' => (string)$identity['site_id'],
            'request_token' => $requestToken,
        ]);
        if (empty($result['ok'])) {
            throw new RuntimeException('Parent status check failed: ' . (string)($result['error'] ?? 'unknown error'));
        }

        $status = strtolower(trim((string)($result['status'] ?? 'unknown')));
        if (in_array($status, ['approved', 'claimed'], true) && !empty($result['claim_ready'])) {
            return \federation_auto_claim_parent($this->db, $parentUrl, $requestId, $requestToken);
        }
        $this->storeJoinResult($result);
        return $result;
    }

    /** @param array<string,mixed> $request */
    private function approveChild(array $request, string $adminNotes, ?int $userId): int
    {
        if (!$this->state->canAcceptChildren()) {
            throw new RuntimeException('This server cannot accept a child while connected to, or waiting to join, a parent.');
        }
        if ((string)$request['status'] !== 'pending') {
            throw new RuntimeException('Only pending child join requests can be approved.');
        }
        if (\catalog_one(
            $this->db,
            'SELECT id FROM ue_federation_peers WHERE peer_site_id=? LIMIT 1',
            [(string)$request['site_id']]
        )) {
            throw new RuntimeException('A federation connection already exists for this site ID.');
        }

        $sharedSecret = \fed_random_secret();
        $secretFields = \fed_prepare_peer_secret($sharedSecret);
        $ttl = max(600, (int)(\fed_setting($this->db, 'join_claim_token_ttl_seconds', '86400') ?: 86400));
        $permissions = json_encode([
            'parent_is_master' => true,
            'parent_inventory_read_without_child_approval' => true,
            'parent_pull_without_child_approval' => true,
            'child_download_requires_parent_approval' => true,
            'child_download_scope' => 'missing_dependencies_only',
            'created_by_join_request' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO ue_federation_peers('
                . 'peer_role,site_name,site_url,peer_site_id,peer_fingerprint,'
                . 'shared_secret_hash,shared_secret_plain,permissions_json,is_active'
                . ') VALUES("child",?,?,?,?,?,?,?,1)'
            );
            $statement->execute([
                (string)$request['site_name'],
                (string)$request['site_url'],
                (string)$request['site_id'],
                (string)$request['site_fingerprint'],
                $secretFields['hash'],
                $secretFields['stored'],
                $permissions,
            ]);
            $peerId = (int)$this->db->lastInsertId();
            $this->db->prepare(
                'UPDATE ue_federation_join_requests '
                . 'SET status="approved", admin_notes=?, claim_token_hash=request_token_hash,'
                . 'claim_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), approved_at=NOW(),'
                . 'approved_by=?, created_peer_id=? WHERE id=?'
            )->execute([
                $adminNotes,
                $ttl,
                $userId,
                $peerId,
                (int)$request['id'],
            ]);
            $this->state->setSiteRole('parent');
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        \fed_log(
            $this->db,
            $peerId,
            null,
            'INFO',
            'JOIN_REQUEST_APPROVED',
            'Child join request #' . (int)$request['id'] . ' approved; server role set to Parent.'
        );
        return $peerId;
    }

    /** @return array<string,mixed> */
    private function peer(int $peerId): array
    {
        $peer = \catalog_one($this->db, 'SELECT * FROM ue_federation_peers WHERE id=?', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Federation connection not found.');
        }
        return $peer;
    }
}
