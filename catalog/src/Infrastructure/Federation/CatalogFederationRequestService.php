<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation request decisions, child request protocol calls, and request-detail reads.
 * Why: Request lifecycle/network/persistence behavior must not be embedded in the Requests rendering controller or partials.
 * Role: Infrastructure orchestration service preserving existing parent/child request semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationRequestService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationPeerSecret.php';
        require_once $root . '/lib/FederationPackageAvailability.php';
        require_once $root . '/lib/FederationRequestLifecycle.php';
        require_once $root . '/lib/FederationDependencyDownloads.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
        require_once $root . '/lib/FederationState.php';
    }

    /**
     * @param array<string,mixed> $input
     * @return array{flash:string,redirect:string,result:?array<string,mixed>}
     */
    public function handle(array $input, string $role): array
    {
        $action = strtolower(trim((string)($input['action'] ?? '')));

        if ($role === 'parent' && in_array($action, ['approve', 'deny', 'approve_all', 'deny_all'], true)) {
            $requestId = (int)($input['request_id'] ?? 0);
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_requests WHERE id=? AND direction="child_to_parent"',
                [$requestId]
            );
            if (!$request) {
                throw new RuntimeException('Incoming request not found.');
            }

            \federation_refresh_request_matches($this->db, $requestId);
            $ids = str_ends_with($action, '_all')
                ? array_map(
                    static fn(array $row): int => (int)$row['id'],
                    \catalog_all($this->db, 'SELECT id FROM ue_federation_request_items WHERE request_id=?', [$requestId])
                )
                : array_values(array_unique(array_filter(
                    array_map('intval', is_array($input['item_ids'] ?? null) ? $input['item_ids'] : []),
                    static fn(int $id): bool => $id > 0
                )));
            if ($ids === []) {
                throw new RuntimeException('Select at least one request item.');
            }

            $decision = str_starts_with($action, 'approve') ? 'approve' : 'deny';
            foreach ($ids as $itemId) {
                $this->decideItem($requestId, $itemId, $decision);
            }
            \federation_request_recalculate_header($this->db, $requestId);
            \fed_log(
                $this->db,
                (int)$request['peer_id'],
                null,
                'INFO',
                'REQUEST_DECISION',
                'Request #' . $requestId . ' updated.'
            );
            return [
                'flash' => 'Request #' . $requestId . ' updated.',
                'redirect' => 'requests.php?tab=incoming&request_id=' . $requestId,
                'result' => null,
            ];
        }

        if ($role === 'child' && $action === 'cancel') {
            $parent = $this->parent();
            $requestId = (int)($input['request_id'] ?? 0);
            $result = \fed_http_post_signed(
                rtrim((string)$parent['site_url'], '/') . '/api/federation/request-cancel.php',
                (string)\fed_setting($this->db, 'site_id', ''),
                \federation_peer_stored_signing_secret($this->db, $parent),
                ['request_id' => $requestId, 'reason' => 'Cancelled by child administrator.']
            );
            if (empty($result['ok'])) {
                throw new RuntimeException((string)($result['error'] ?? 'Cancellation failed.'));
            }
            return [
                'flash' => 'Outgoing request #' . $requestId . ' cancelled.',
                'redirect' => 'requests.php?tab=closed',
                'result' => null,
            ];
        }

        if ($role === 'child' && $action === 'queue_approved') {
            return [
                'flash' => 'Approved files checked and still-required downloads queued.',
                'redirect' => 'requests.php?tab=active',
                'result' => \federation_queue_approved_dependency_downloads($this->db),
            ];
        }

        throw new RuntimeException('Unsupported request action.');
    }

    /** @return array<string,mixed> */
    public function parent(): array
    {
        $parent = \federation_parent_peer($this->db, true);
        if (!$parent) {
            throw new RuntimeException('Active parent connection not found.');
        }
        \federation_peer_stored_signing_secret($this->db, $parent);
        return $parent;
    }

    /** @param array<string,mixed> $parent @param array<string,mixed> $payload @return array<string,mixed> */
    public function childStatus(array $parent, array $payload): array
    {
        $result = \fed_http_post_signed(
            rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
            (string)\fed_setting($this->db, 'site_id', ''),
            \federation_peer_stored_signing_secret($this->db, $parent),
            $payload
        );
        if (is_array($result['policy'] ?? null)) {
            \federation_cache_parent_base_game_policy($this->db, (int)$parent['id'], $result['policy']);
        }
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Parent request status unavailable.'));
        }
        return $result;
    }

    /**
     * Refreshes package matches exactly as the legacy parent detail view did,
     * then returns the request and its display items as one read model.
     *
     * @return array{request:?array<string,mixed>,items:list<array<string,mixed>>}
     */
    public function parentRequestDetail(int $requestId): array
    {
        if ($requestId < 1) {
            return ['request' => null, 'items' => []];
        }
        \federation_refresh_request_matches($this->db, $requestId);
        $request = \catalog_one(
            $this->db,
            'SELECT r.*,p.site_name peer_name FROM ue_federation_requests r '
            . 'JOIN ue_federation_peers p ON p.id=r.peer_id WHERE r.id=?',
            [$requestId]
        );
        return [
            'request' => is_array($request) ? $request : null,
            'items' => $this->parentItems($requestId),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function parentItems(int $requestId): array
    {
        return \catalog_all(
            $this->db,
            'SELECT i.*,f.package_name,f.original_name,f.file_size,f.package_guid,f.md5,f.sha1 '
            . 'FROM ue_federation_request_items i '
            . 'LEFT JOIN ue_files f ON f.id=i.local_file_id '
            . 'WHERE i.request_id=? ORDER BY i.required_package,i.id',
            [$requestId]
        );
    }

    private function decideItem(int $requestId, int $itemId, string $decision): void
    {
        $item = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_request_items WHERE id=? AND request_id=?',
            [$itemId, $requestId]
        );
        if (!$item || !in_array((string)$item['status'], ['requested', 'approved', 'denied'], true)) {
            return;
        }

        if ($decision === 'deny') {
            $this->db->prepare(
                'UPDATE ue_federation_request_items '
                . 'SET status="denied",status_message="Denied by the parent administrator." WHERE id=?'
            )->execute([$itemId]);
            return;
        }

        $available = \federation_package_availability($this->db, [
            'required_package' => (string)$item['required_package'],
            'wanted_guid' => (string)($item['wanted_guid'] ?? ''),
            'wanted_md5' => (string)($item['wanted_md5'] ?? ''),
        ]);
        if (!empty($available['policy_excluded'])) {
            $this->db->prepare(
                'UPDATE ue_federation_request_items '
                . 'SET status="denied",status_message="Excluded by the parent base-game policy." WHERE id=?'
            )->execute([$itemId]);
            return;
        }

        $fileId = !empty($available['file_id']) ? (int)$available['file_id'] : null;
        $message = $fileId
            ? 'Approved for this child by the parent administrator.'
            : 'Approved and waiting until the parent imports a matching file.';
        $this->db->prepare(
            'UPDATE ue_federation_request_items '
            . 'SET status="approved",local_file_id=?,status_message=? WHERE id=?'
        )->execute([$fileId, $message, $itemId]);
    }
}
