<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/FederationAuth.php';
require_once __DIR__ . '/FederationPeerSecret.php';

/** @return array<string,mixed> */
function federation_build_inventory_payload(PDO $db): array
{
    $identity = fed_ensure_identity($db);
    $files = catalog_all(
        $db,
        'SELECT f.*, g.name game_name, p.engine_key profile_engine
         FROM ue_files f
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         WHERE f.scan_status="verified"
         ORDER BY f.id'
    );
    $out = [];
    foreach ($files as $file) {
        $out[] = [
            'file_id' => (int)$file['id'],
            'game_id' => (int)$file['game_id'],
            'game_name' => (string)$file['game_name'],
            'engine_key' => (string)($file['profile_engine'] ?? ''),
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'extension' => (string)$file['extension'],
            'file_size' => (int)$file['file_size'],
            'md5' => (string)$file['md5'],
            'sha1' => (string)$file['sha1'],
            'package_guid' => (string)$file['package_guid'],
            'is_compressed' => (int)($file['is_compressed'] ?? 0),
            'compression_flags' => (int)($file['compression_flags'] ?? 0),
            'import_count' => (int)$file['import_count'],
            'export_count' => (int)$file['export_count'],
        ];
    }

    return [
        'site' => $identity,
        'generated_at' => date('c'),
        'file_count' => count($out),
        'files' => $out,
    ];
}

/** @return array<string,mixed> */
function federation_push_inventory_to_parent(PDO $db, int $peerId): array
{
    $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$parent) {
        throw new RuntimeException('Active parent peer not found.');
    }
    $storedSecret = federation_peer_stored_signing_secret($db, $parent);

    $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/inventory-push.php';
    $result = fed_http_post_signed(
        $url,
        (string)fed_setting($db, 'site_id', ''),
        $storedSecret,
        federation_build_inventory_payload($db)
    );
    fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'INVENTORY_PUSH_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
    return $result;
}

/** @return array<string,mixed> */
function federation_auto_push_inventory_to_parent(PDO $db): array
{
    if ((string)fed_setting($db, 'site_role', 'standalone') !== 'child') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'site is not child'];
    }
    $parent = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY id LIMIT 1');
    if (!$parent) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no active parent peer'];
    }
    return federation_push_inventory_to_parent($db, (int)$parent['id']);
}

function federation_inventory_local_game_id(PDO $db, array $file): ?int
{
    $gameName = trim((string)($file['game_name'] ?? ''));
    if ($gameName !== '') {
        $game = catalog_one($db, 'SELECT id FROM ue_games WHERE name=? ORDER BY id LIMIT 1', [$gameName]);
        if ($game) {
            return (int)$game['id'];
        }
    }

    $engineKey = strtoupper(trim((string)($file['engine_key'] ?? '')));
    if ($engineKey !== '') {
        $game = catalog_one(
            $db,
            'SELECT g.id
             FROM ue_games g
             JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
             WHERE UPPER(p.engine_key)=?
             ORDER BY g.id LIMIT 1',
            [$engineKey]
        );
        if ($game) {
            return (int)$game['id'];
        }
    }

    return null;
}

/** @return array<int,mixed> */
function federation_inventory_row_values(PDO $db, int $peerId, array $file, string $seenAt): array
{
    $packageName = trim((string)($file['package_name'] ?? ''));
    $originalName = trim((string)($file['original_name'] ?? ''));
    $guid = strtoupper(trim((string)($file['package_guid'] ?? '')));
    $md5 = strtolower(trim((string)($file['md5'] ?? '')));
    $sha1 = strtolower(trim((string)($file['sha1'] ?? '')));
    if ($packageName === '' || $originalName === '' || ($guid === '' && $md5 === '')) {
        throw new RuntimeException('Peer inventory contains an incomplete file identity.');
    }
    if ($md5 !== '' && preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
        throw new RuntimeException('Peer inventory contains an invalid MD5 value.');
    }
    if ($sha1 !== '' && preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
        throw new RuntimeException('Peer inventory contains an invalid SHA1 value.');
    }
    if ($guid !== '' && preg_match('/^[A-F0-9-]{8,64}$/', $guid) !== 1) {
        throw new RuntimeException('Peer inventory contains an invalid package GUID.');
    }

    return [
        $peerId,
        federation_inventory_local_game_id($db, $file),
        mb_substr(trim((string)($file['game_name'] ?? '')), 0, 160, 'UTF-8'),
        mb_substr(trim((string)($file['engine_key'] ?? '')), 0, 32, 'UTF-8'),
        isset($file['file_id']) ? max(0, (int)$file['file_id']) ?: null : null,
        mb_substr($packageName, 0, 255, 'UTF-8'),
        mb_substr($originalName, 0, 255, 'UTF-8'),
        mb_substr(strtolower(trim((string)($file['extension'] ?? pathinfo($originalName, PATHINFO_EXTENSION)))), 0, 32, 'UTF-8'),
        max(0, (int)($file['file_size'] ?? 0)),
        $md5 !== '' ? $md5 : null,
        $sha1 !== '' ? $sha1 : null,
        $guid !== '' ? $guid : null,
        !empty($file['is_compressed']) ? 1 : 0,
        max(0, (int)($file['compression_flags'] ?? 0)),
        max(0, (int)($file['import_count'] ?? 0)),
        max(0, (int)($file['export_count'] ?? 0)),
        $seenAt,
    ];
}

/**
 * Pulls the transferable inventory from either paired side. Parent sites pull
 * child inventories and child sites pull their parent inventory. Possessing an
 * inventory row does not grant download authority; child downloads remain
 * restricted to approved dependency request items.
 *
 * @return array<string,mixed>
 */
function federation_pull_inventory_from_peer(PDO $db, int $peerId): array
{
    $peer = catalog_one(
        $db,
        'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role IN ("parent","child") AND is_active=1',
        [$peerId]
    );
    if (!$peer) {
        throw new RuntimeException('Active federation peer not found.');
    }
    $storedSecret = federation_peer_stored_signing_secret($db, $peer);
    $siteId = (string)fed_setting($db, 'site_id', '');
    if ($siteId === '') {
        throw new RuntimeException('Local federation site ID is unavailable.');
    }

    $url = rtrim((string)$peer['site_url'], '/') . '/api/federation/inventory-list.php';
    $seenAt = date('Y-m-d H:i:s');
    $afterFileId = 0;
    $received = 0;
    $pages = 0;
    $complete = false;
    $upsert = $db->prepare(
        'INSERT INTO ue_federation_peer_files(
            peer_id,game_id,remote_game_name,remote_engine_key,remote_file_id,
            package_name,original_name,extension,file_size,md5,sha1,package_guid,
            is_compressed,compression_flags,import_count,export_count,last_seen_at
         ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            game_id=VALUES(game_id), remote_game_name=VALUES(remote_game_name),
            remote_engine_key=VALUES(remote_engine_key), remote_file_id=VALUES(remote_file_id),
            package_name=VALUES(package_name), original_name=VALUES(original_name),
            extension=VALUES(extension), file_size=VALUES(file_size), md5=VALUES(md5),
            sha1=VALUES(sha1), package_guid=VALUES(package_guid),
            is_compressed=VALUES(is_compressed), compression_flags=VALUES(compression_flags),
            import_count=VALUES(import_count), export_count=VALUES(export_count),
            last_seen_at=VALUES(last_seen_at)'
    );

    while (!$complete) {
        if (++$pages > 1000) {
            throw new RuntimeException('Peer inventory exceeded the maximum page count.');
        }
        $result = fed_http_post_signed($url, $siteId, $storedSecret, [
            'after_file_id' => $afterFileId,
            'limit' => 500,
        ]);
        if (empty($result['ok']) || !isset($result['files']) || !is_array($result['files'])) {
            throw new RuntimeException('Peer inventory request failed: ' . ($result['error'] ?? 'invalid response'));
        }
        $remoteSiteId = strtolower(trim((string)($result['site']['site_id'] ?? '')));
        if ($remoteSiteId !== '' && !hash_equals(strtolower((string)$peer['peer_site_id']), $remoteSiteId)) {
            throw new RuntimeException('Inventory identity does not match the selected peer.');
        }

        $db->beginTransaction();
        try {
            foreach ($result['files'] as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $upsert->execute(federation_inventory_row_values($db, $peerId, $file, $seenAt));
                $received++;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        $nextAfter = max($afterFileId, (int)($result['next_after_file_id'] ?? $afterFileId));
        $complete = !empty($result['complete']);
        if (!$complete && $nextAfter <= $afterFileId) {
            throw new RuntimeException('Peer inventory cursor did not advance.');
        }
        $afterFileId = $nextAfter;
    }

    $delete = $db->prepare('DELETE FROM ue_federation_peer_files WHERE peer_id=? AND last_seen_at<>?');
    $delete->execute([$peerId, $seenAt]);
    $removed = $delete->rowCount();

    fed_log(
        $db,
        $peerId,
        null,
        'INFO',
        'INVENTORY_SYNC_FROM_PEER',
        'Role=' . (string)$peer['peer_role'] . '; received ' . $received . ' row(s) in ' . $pages . ' page(s); removed ' . $removed . ' stale row(s).'
    );
    return [
        'ok' => true,
        'peer_id' => $peerId,
        'peer_role' => (string)$peer['peer_role'],
        'peer_name' => (string)$peer['site_name'],
        'received' => $received,
        'removed_stale' => $removed,
        'pages' => $pages,
        'synchronized_at' => $seenAt,
    ];
}

/** @return array<string,mixed> */
function federation_pull_inventory_from_child(PDO $db, int $peerId): array
{
    $peer = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]);
    if (!$peer) {
        throw new RuntimeException('Active child peer not found.');
    }
    return federation_pull_inventory_from_peer($db, $peerId);
}

/** @return array<string,mixed> */
function federation_pull_inventory_from_parent(PDO $db, int $peerId): array
{
    $peer = catalog_one($db, 'SELECT id FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$peerId]);
    if (!$peer) {
        throw new RuntimeException('Active parent peer not found.');
    }
    return federation_pull_inventory_from_peer($db, $peerId);
}

function federation_inventory_sync_interval_hours(PDO $db): int
{
    return max(0, min(720, (int)(fed_setting($db, 'inventory_sync_interval_hours', '24') ?? '24')));
}

function federation_inventory_last_sync_at(PDO $db, int $peerId): ?string
{
    $row = catalog_one(
        $db,
        'SELECT MAX(created_at) synchronized_at
         FROM ue_federation_transfer_logs
         WHERE peer_id=? AND event IN ("INVENTORY_SYNC_FROM_PEER","INVENTORY_PULLED_BY_PARENT")',
        [$peerId]
    );
    $value = trim((string)($row['synchronized_at'] ?? ''));
    return $value !== '' ? $value : null;
}

function federation_inventory_sync_is_due(PDO $db, int $peerId, ?int $now = null): bool
{
    $hours = federation_inventory_sync_interval_hours($db);
    if ($hours <= 0) {
        return false;
    }
    $last = federation_inventory_last_sync_at($db, $peerId);
    if ($last === null) {
        return true;
    }
    $timestamp = strtotime($last);
    if ($timestamp === false) {
        return true;
    }
    return ($now ?? time()) >= $timestamp + ($hours * 3600);
}

/** @return array<string,mixed> */
function federation_sync_due_inventories(PDO $db, bool $force = false): array
{
    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $interval = federation_inventory_sync_interval_hours($db);
    if ($role === 'standalone') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'standalone site', 'interval_hours' => $interval, 'peers' => []];
    }
    if (!$force && $interval <= 0) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'automatic inventory synchronization disabled', 'interval_hours' => 0, 'peers' => []];
    }

    $wantedPeerRole = $role === 'parent' ? 'child' : 'parent';
    $peers = catalog_all(
        $db,
        'SELECT id,site_name,peer_role FROM ue_federation_peers WHERE peer_role=? AND is_active=1 ORDER BY id',
        [$wantedPeerRole]
    );
    $results = [];
    $synchronized = 0;
    $notDue = 0;
    $failed = 0;
    foreach ($peers as $peer) {
        $peerId = (int)$peer['id'];
        if (!$force && !federation_inventory_sync_is_due($db, $peerId)) {
            $notDue++;
            $results[] = [
                'peer_id' => $peerId,
                'peer_name' => (string)$peer['site_name'],
                'status' => 'not_due',
                'last_sync_at' => federation_inventory_last_sync_at($db, $peerId),
            ];
            continue;
        }
        try {
            $result = federation_pull_inventory_from_peer($db, $peerId);
            $result['status'] = 'synchronized';
            $results[] = $result;
            $synchronized++;
        } catch (Throwable $error) {
            $failed++;
            fed_log($db, $peerId, null, 'ERROR', 'INVENTORY_SYNC_FAILED', $error->getMessage());
            $results[] = [
                'peer_id' => $peerId,
                'peer_name' => (string)$peer['site_name'],
                'status' => 'failed',
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'ok' => $failed === 0,
        'site_role' => $role,
        'interval_hours' => $interval,
        'synchronized' => $synchronized,
        'not_due' => $notDue,
        'failed' => $failed,
        'peers' => $results,
    ];
}
