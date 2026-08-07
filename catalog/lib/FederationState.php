<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for federation state.
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

function federation_site_role(PDO $db): string
{
    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    return in_array($role, ['standalone', 'parent', 'child'], true) ? $role : 'standalone';
}

function federation_parent_peer(PDO $db, bool $activeOnly = true): ?array
{
    return catalog_one(
        $db,
        'SELECT * FROM ue_federation_peers WHERE peer_role="parent"'
            . ($activeOnly ? ' AND is_active=1' : '')
            . ' ORDER BY is_active DESC, id ASC LIMIT 1'
    );
}

/** @return list<array<string,mixed>> */
function federation_child_peers(PDO $db, bool $activeOnly = false): array
{
    return catalog_all(
        $db,
        'SELECT * FROM ue_federation_peers WHERE peer_role="child"'
            . ($activeOnly ? ' AND is_active=1' : '')
            . ' ORDER BY is_active DESC, site_name, id'
    );
}

function federation_parent_join_status(PDO $db): string
{
    $status = strtolower(trim((string)fed_setting($db, 'main_parent_join_status', 'none')));
    return in_array($status, ['none', 'pending', 'approved', 'claimed', 'denied', 'expired'], true)
        ? $status
        : 'none';
}

function federation_has_pending_parent_join(PDO $db): bool
{
    return in_array(federation_parent_join_status($db), ['pending', 'approved'], true)
        && trim((string)fed_setting($db, 'main_parent_url', '')) !== '';
}

function federation_display_role(PDO $db): string
{
    $role = federation_site_role($db);
    if ($role === 'standalone' && federation_has_pending_parent_join($db)) {
        return 'Joining Parent';
    }
    return ucfirst($role);
}

function federation_set_site_role(PDO $db, string $role): void
{
    if (!in_array($role, ['standalone', 'parent', 'child'], true)) {
        throw new RuntimeException('Invalid federation site role.');
    }

    fed_set_setting($db, 'site_role', $role);
    fed_set_setting($db, 'parent_enabled', $role === 'parent' ? '1' : '0');
    fed_set_setting($db, 'child_enabled', $role === 'child' ? '1' : '0');
    if ($role === 'child') {
        fed_set_setting($db, 'join_requests_enabled', '0');
    }
}

function federation_clear_parent_join_state(PDO $db): void
{
    foreach ([
        'main_parent_url',
        'main_parent_join_request_id',
        'main_parent_join_request_token',
        'main_parent_join_status_message',
        'main_parent_join_admin_notes',
    ] as $key) {
        fed_set_setting($db, $key, '');
    }
    fed_set_setting($db, 'main_parent_join_status', 'none');
}

function federation_can_join_parent(PDO $db): bool
{
    if (federation_site_role($db) !== 'standalone') {
        return false;
    }
    if (federation_parent_peer($db, false) !== null || federation_child_peers($db, false) !== []) {
        return false;
    }
    $pendingChildren = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status IN ("pending","approved")'
    )['c'] ?? 0);
    return $pendingChildren === 0;
}

function federation_can_accept_children(PDO $db): bool
{
    if (federation_site_role($db) === 'child' || federation_parent_peer($db, false) !== null) {
        return false;
    }
    return !federation_has_pending_parent_join($db);
}

function federation_cancel_active_peer_jobs(PDO $db, int $peerId, string $reason): int
{
    $stmt = $db->prepare(
        'UPDATE ue_federation_transfer_jobs
         SET status="cancelled", finished_at=NOW(), last_error=?
         WHERE peer_id=? AND status IN ("queued","running","downloaded")'
    );
    $stmt->execute([$reason, $peerId]);
    return $stmt->rowCount();
}

function federation_remove_peer(PDO $db, array $peer): void
{
    $peerId = (int)($peer['id'] ?? 0);
    if ($peerId <= 0) {
        throw new RuntimeException('Federation peer is invalid.');
    }

    $db->beginTransaction();
    try {
        federation_cancel_active_peer_jobs($db, $peerId, 'Cancelled because the federation connection was removed.');
        $db->prepare('DELETE FROM ue_federation_peer_files WHERE peer_id=?')->execute([$peerId]);
        $db->prepare('DELETE FROM ue_federation_peers WHERE id=?')->execute([$peerId]);

        if ((string)($peer['peer_role'] ?? '') === 'parent') {
            federation_clear_parent_join_state($db);
            federation_set_site_role($db, 'standalone');
        }

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function federation_reconcile_site_role(PDO $db): string
{
    $parent = federation_parent_peer($db, false);
    $children = federation_child_peers($db, false);
    $role = federation_site_role($db);

    if ($parent !== null && $role !== 'child') {
        federation_set_site_role($db, 'child');
        return 'child';
    }
    if ($parent === null && $role === 'child') {
        federation_set_site_role($db, 'standalone');
        return 'standalone';
    }
    if ($children !== [] && $role !== 'parent') {
        federation_set_site_role($db, 'parent');
        return 'parent';
    }
    return $role;
}

/** @return array<string,string> */
function federation_main_links(): array
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
