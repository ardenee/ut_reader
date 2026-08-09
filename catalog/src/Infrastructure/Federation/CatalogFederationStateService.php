<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation role, peer-state and connection-removal transitions.
 * Why: Site-role mutation, join-state cleanup, active-job cancellation and peer deletion form one transactional federation state boundary.
 * Role: Infrastructure federation state service replacing procedural mutation logic from FederationState.php.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationStateService
{
    private readonly CatalogFederationSettingsStore $settings;

    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->settings = new CatalogFederationSettingsStore($db);
    }

    public function siteRole(): string
    {
        $role = strtolower(trim((string)$this->settings->get('site_role', 'standalone')));
        return in_array($role, ['standalone', 'parent', 'child'], true) ? $role : 'standalone';
    }

    /** @return array<string,mixed>|null */
    public function parentPeer(bool $activeOnly = true): ?array
    {
        return \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE peer_role="parent"'
                . ($activeOnly ? ' AND is_active=1' : '')
                . ' ORDER BY is_active DESC, id ASC LIMIT 1'
        );
    }

    /** @return list<array<string,mixed>> */
    public function childPeers(bool $activeOnly = false): array
    {
        return \catalog_all(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE peer_role="child"'
                . ($activeOnly ? ' AND is_active=1' : '')
                . ' ORDER BY is_active DESC, site_name, id'
        );
    }

    public function parentJoinStatus(): string
    {
        $status = strtolower(trim((string)$this->settings->get('main_parent_join_status', 'none')));
        return in_array($status, ['none', 'pending', 'approved', 'claimed', 'denied', 'expired'], true)
            ? $status
            : 'none';
    }

    public function hasPendingParentJoin(): bool
    {
        return in_array($this->parentJoinStatus(), ['pending', 'approved'], true)
            && trim((string)$this->settings->get('main_parent_url', '')) !== '';
    }

    public function displayRole(): string
    {
        $role = $this->siteRole();
        if ($role === 'standalone' && $this->hasPendingParentJoin()) {
            return 'Joining Parent';
        }
        return ucfirst($role);
    }

    public function setSiteRole(string $role): void
    {
        if (!in_array($role, ['standalone', 'parent', 'child'], true)) {
            throw new RuntimeException('Invalid federation site role.');
        }

        $this->settings->set('site_role', $role);
        $this->settings->set('parent_enabled', $role === 'parent' ? '1' : '0');
        $this->settings->set('child_enabled', $role === 'child' ? '1' : '0');
        if ($role === 'child') {
            $this->settings->set('join_requests_enabled', '0');
        }
    }

    public function clearParentJoinState(): void
    {
        foreach ([
            'main_parent_url',
            'main_parent_join_request_id',
            'main_parent_join_request_token',
            'main_parent_join_status_message',
            'main_parent_join_admin_notes',
        ] as $key) {
            $this->settings->set($key, '');
        }
        $this->settings->set('main_parent_join_status', 'none');
    }

    public function canJoinParent(): bool
    {
        if ($this->siteRole() !== 'standalone') {
            return false;
        }
        if ($this->parentPeer(false) !== null || $this->childPeers(false) !== []) {
            return false;
        }
        $pendingChildren = (int)(\catalog_one(
            $this->db,
            'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status IN ("pending","approved")'
        )['c'] ?? 0);
        return $pendingChildren === 0;
    }

    public function canAcceptChildren(): bool
    {
        if ($this->siteRole() === 'child' || $this->parentPeer(false) !== null) {
            return false;
        }
        return !$this->hasPendingParentJoin();
    }

    public function cancelActivePeerJobs(int $peerId, string $reason): int
    {
        $statement = $this->db->prepare(
            'UPDATE ue_federation_transfer_jobs
             SET status="cancelled", finished_at=NOW(), last_error=?
             WHERE peer_id=? AND status IN ("queued","running","downloaded")'
        );
        $statement->execute([$reason, $peerId]);
        return $statement->rowCount();
    }

    /** @param array<string,mixed> $peer */
    public function removePeer(array $peer): void
    {
        $peerId = (int)($peer['id'] ?? 0);
        if ($peerId <= 0) {
            throw new RuntimeException('Federation peer is invalid.');
        }

        $this->db->beginTransaction();
        try {
            $this->cancelActivePeerJobs(
                $peerId,
                'Cancelled because the federation connection was removed.'
            );
            $this->db->prepare('DELETE FROM ue_federation_peer_files WHERE peer_id=?')->execute([$peerId]);
            $this->db->prepare('DELETE FROM ue_federation_peers WHERE id=?')->execute([$peerId]);

            if ((string)($peer['peer_role'] ?? '') === 'parent') {
                $this->clearParentJoinState();
                $this->setSiteRole('standalone');
            }

            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function reconcileSiteRole(): string
    {
        $parent = $this->parentPeer(false);
        $children = $this->childPeers(false);
        $role = $this->siteRole();

        if ($parent !== null && $role !== 'child') {
            $this->setSiteRole('child');
            return 'child';
        }
        if ($parent === null && $role === 'child') {
            $this->setSiteRole('standalone');
            return 'standalone';
        }
        if ($children !== [] && $role !== 'parent') {
            $this->setSiteRole('parent');
            return 'parent';
        }
        return $role;
    }

    /** @return array<string,string> */
    public static function mainLinks(): array
    {
        return [
            'Overview' => 'admin.php',
            'Connections' => 'connections.php',
            'Inventories' => 'inventories.php',
            'File Requests' => 'requests.php',
            'Transfers' => 'queue.php',
            'Settings' => 'settings.php',
            'Diagnostics' => 'diagnostics.php',
        ];
    }
}
