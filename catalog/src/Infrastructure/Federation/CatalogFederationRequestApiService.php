<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns signed federation request submit/status/cancel and child item-status lifecycle.
 * Why: HTTP API entry points should authenticate/parse/serialize; request policy, persistence, paging and state transitions belong to services.
 * Role: Infrastructure federation protocol service preserving existing request/status semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use Throwable;

final class CatalogFederationRequestApiService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationRequestLifecycle.php';
        require_once $root . '/lib/FederationPackageAvailability.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
        require_once $root . '/lib/BaseGameProtection.php';
        require_once $root . '/lib/CatalogPublicRateLimit.php';
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array<string,mixed> */
    public function submitRequest(array $peer, array $payload): array
    {
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException('Only a paired child may submit requests.', 403);
        }

        $rate = \catalog_public_join_rate_limit('federation_request_submit');
        if (empty($rate['allowed'])) {
            throw new CatalogFederationApiException(
                'Too many federation request submissions from this address. Retry after '
                . max(1, (int)($rate['retry_after'] ?? 60)) . ' seconds.',
                429
            );
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($items === []) {
            throw new CatalogFederationApiException('At least one request item is required.', 400);
        }

        $ignoreBaseGame = \federation_ignore_base_game_files($this->db, $peer);
        $filtered = [];
        $excludedByPolicy = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($ignoreBaseGame) {
                $explicitBaseGame = !empty($item['is_base_game_dependency']);
                $officialBaseGame = false;
                $requiredPackage = \catalog_clean_unreal_package_stem((string)($item['required_package'] ?? ''));
                if ($requiredPackage !== '') {
                    $officialBaseGame = (bool)\catalog_one(
                        $this->db,
                        'SELECT 1 AS found FROM ue_base_game_files b '
                        . 'LEFT JOIN ue_files bf ON bf.id=b.source_file_id '
                        . 'WHERE LOWER(COALESCE(NULLIF(b.package_name,""),bf.package_name,""))=LOWER(?) LIMIT 1',
                        [$requiredPackage]
                    );
                }
                if ($explicitBaseGame || $officialBaseGame) {
                    $excludedByPolicy++;
                    continue;
                }
            }
            $filtered[] = $item;
        }
        $items = $filtered;
        if ($items === []) {
            throw new CatalogFederationApiException(
                'Every selected package is excluded by the parent Ignore base-game files policy.',
                422
            );
        }
        if (count($items) > 5000) {
            throw new CatalogFederationApiException('A single request may contain at most 5000 items.', 413);
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ue_federation_requests(peer_id,direction,status,title,notes) '
                . 'VALUES(?,"child_to_parent","submitted",?,?)'
            );
            $stmt->execute([
                (int)$peer['id'],
                trim((string)($payload['title'] ?? 'File request')),
                trim((string)($payload['notes'] ?? '')),
            ]);
            $requestId = (int)$this->db->lastInsertId();

            $insertItem = $this->db->prepare(
                'INSERT INTO ue_federation_request_items('
                . 'request_id,required_package,required_object_path,wanted_guid,wanted_md5,game_name,engine_key,'
                . 'use_count,object_count,status,status_message'
                . ') VALUES(?,?,?,?,?,?,?,?,?,"requested",NULL)'
            );
            foreach ($items as $item) {
                $insertItem->execute([
                    $requestId,
                    (string)($item['required_package'] ?? ''),
                    (string)($item['required_object_path'] ?? ''),
                    (string)($item['wanted_guid'] ?? ''),
                    (string)($item['wanted_md5'] ?? ''),
                    (string)($item['game_name'] ?? ''),
                    (string)($item['engine_key'] ?? ''),
                    (int)($item['use_count'] ?? 0),
                    (int)($item['object_count'] ?? 0),
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        \federation_refresh_request_matches($this->db, $requestId);
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'REQUEST_SUBMITTED',
            'Child submitted request ' . $requestId . ' with ' . count($items) . ' item(s).'
        );
        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'submitted',
            'items' => count($items),
            'excluded_by_policy' => $excludedByPolicy,
            'policy' => \federation_base_game_policy_payload($this->db, $peer),
        ];
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array<string,mixed> */
    public function requestStatus(array $peer, array $payload): array
    {
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException('Only a paired child may query request status.', 403);
        }

        if (!empty($payload['package_statuses'])) {
            return [
                'ok' => true,
                'policy' => \federation_base_game_policy_payload($this->db, $peer),
                'package_statuses' => $this->packageStatuses((int)$peer['id']),
            ];
        }

        $requestId = (int)($payload['request_id'] ?? 0);
        if ($requestId <= 0 && !empty($payload['list'])) {
            $closed = !empty($payload['closed']);
            $pageSize = max(1, min(100, (int)($payload['page_size'] ?? 100)));
            $move = strtolower(trim((string)($payload['move'] ?? 'first')));
            if ($move === 'prev') {
                $move = 'previous';
            }
            if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
                $move = 'first';
            }
            $cursor = $this->decodeCursor((string)($payload['cursor'] ?? ''));
            if ((string)($payload['cursor'] ?? '') !== '' && $cursor === null) {
                $move = 'first';
            }

            $where = ['r.peer_id=?', 'r.direction="child_to_parent"'];
            $params = [(int)$peer['id']];
            $where[] = $closed
                ? 'r.status IN ("completed","cancelled","denied")'
                : 'r.status NOT IN ("completed","cancelled","denied")';
            if ($cursor !== null && $move === 'next') {
                $where[] = '(r.created_at<? OR (r.created_at=? AND r.id<?))';
                array_push($params, $cursor['created_at'], $cursor['created_at'], $cursor['id']);
            } elseif ($cursor !== null && $move === 'previous') {
                $where[] = '(r.created_at>? OR (r.created_at=? AND r.id>?))';
                array_push($params, $cursor['created_at'], $cursor['created_at'], $cursor['id']);
            }
            $ascending = $move === 'previous' || $move === 'last';
            $rows = \catalog_all(
                $this->db,
                'SELECT r.*,COUNT(i.id) item_count '
                . 'FROM ue_federation_requests r '
                . 'LEFT JOIN ue_federation_request_items i ON i.request_id=r.id '
                . 'WHERE ' . implode(' AND ', $where) . ' '
                . 'GROUP BY r.id ORDER BY r.created_at ' . ($ascending ? 'ASC' : 'DESC')
                . ',r.id ' . ($ascending ? 'ASC' : 'DESC') . ' LIMIT ' . ($pageSize + 1),
                $params
            );
            $hasMore = count($rows) > $pageSize;
            if ($hasMore) {
                array_pop($rows);
            }
            if ($ascending) {
                $rows = array_reverse($rows);
            }

            foreach ($rows as &$row) {
                \federation_refresh_request_matches($this->db, (int)$row['id']);
                $row = \catalog_one(
                    $this->db,
                    'SELECT r.*,COUNT(i.id) item_count '
                    . 'FROM ue_federation_requests r '
                    . 'LEFT JOIN ue_federation_request_items i ON i.request_id=r.id '
                    . 'WHERE r.id=? GROUP BY r.id',
                    [(int)$row['id']]
                ) ?: $row;
                $row['status_counts'] = $this->statusSummary((int)$row['id']);
            }
            unset($row);

            $first = $rows[0] ?? null;
            $last = $rows[count($rows) - 1] ?? null;
            return [
                'ok' => true,
                'policy' => \federation_base_game_policy_payload($this->db, $peer),
                'requests' => $rows,
                'request_page' => [
                    'has_previous' => $move === 'next' || ($move === 'last' && $rows !== []),
                    'has_next' => $move === 'previous' || ($hasMore && !$ascending),
                    'previous_cursor' => is_array($first) ? $this->encodeCursor($first) : '',
                    'next_cursor' => is_array($last) ? $this->encodeCursor($last) : '',
                ],
            ];
        }

        if ($requestId <= 0) {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC LIMIT 1',
                [(int)$peer['id']]
            );
        } else {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_requests WHERE id=? AND peer_id=? AND direction="child_to_parent"',
                [$requestId, (int)$peer['id']]
            );
        }
        if (!$request) {
            throw new CatalogFederationApiException('Request not found for this child.', 404);
        }

        \federation_refresh_request_matches($this->db, (int)$request['id']);
        $request = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_requests WHERE id=?',
            [(int)$request['id']]
        ) ?: $request;
        $items = \catalog_all(
            $this->db,
            'SELECT i.*,f.package_name,f.original_name,f.file_size,f.package_guid,f.md5,f.sha1 '
            . 'FROM ue_federation_request_items i LEFT JOIN ue_files f ON f.id=i.local_file_id '
            . 'WHERE i.request_id=? ORDER BY i.required_package,i.id',
            [(int)$request['id']]
        );
        return [
            'ok' => true,
            'request' => $request,
            'summary' => $this->statusSummary((int)$request['id']),
            'items' => $items,
            'policy' => \federation_base_game_policy_payload($this->db, $peer),
        ];
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array{ok:true,request_id:int,status:string} */
    public function cancelRequest(array $peer, array $payload): array
    {
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException('Only a paired child may cancel its request.', 403);
        }

        $requestId = (int)($payload['request_id'] ?? 0);
        if ($requestId <= 0) {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC LIMIT 1',
                [(int)$peer['id']]
            );
        } else {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_requests WHERE id=? AND peer_id=? AND direction="child_to_parent"',
                [$requestId, (int)$peer['id']]
            );
        }
        if (!$request) {
            throw new CatalogFederationApiException('Request not found for this child.', 404);
        }
        if (in_array((string)$request['status'], ['completed', 'denied', 'updated', 'cancelled'], true)) {
            throw new CatalogFederationApiException('Request cannot be cancelled from status: ' . (string)$request['status'], 409);
        }

        $reason = trim((string)($payload['reason'] ?? 'Cancelled by child site.'));
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE ue_federation_requests SET status="cancelled",notes=CONCAT(COALESCE(notes,""),?) WHERE id=?'
            )->execute(["\n" . date('Y-m-d H:i:s') . ' - ' . $reason, (int)$request['id']]);
            $this->db->prepare(
                'UPDATE ue_federation_request_items SET status="failed",status_message=? '
                . 'WHERE request_id=? AND status IN ("requested","approved","queued","downloading","downloaded")'
            )->execute(['Request cancelled by child site.', (int)$request['id']]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        \fed_log($this->db, (int)$peer['id'], null, 'INFO', 'REQUEST_CANCELLED', 'Request ' . (int)$request['id'] . ' cancelled by child.');
        return ['ok' => true, 'request_id' => (int)$request['id'], 'status' => 'cancelled'];
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array{ok:true,item_id:int,status:string} */
    public function updateItemStatus(array $peer, array $payload): array
    {
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException('Only a paired child may update request item status.', 403);
        }

        $itemId = (int)($payload['request_item_id'] ?? 0);
        $status = (string)($payload['status'] ?? '');
        $message = trim((string)($payload['message'] ?? ''));
        $childLocalFileId = isset($payload['child_local_file_id']) ? (int)$payload['child_local_file_id'] : null;
        $childMd5 = strtolower(trim((string)($payload['md5'] ?? '')));
        $childSha1 = strtolower(trim((string)($payload['sha1'] ?? '')));
        $allowed = ['queued', 'downloading', 'downloaded', 'imported', 'failed', 'skipped_already_have'];
        if ($itemId <= 0 || !in_array($status, $allowed, true)) {
            throw new CatalogFederationApiException('Invalid request item status update.', 400);
        }

        $item = \catalog_one(
            $this->db,
            'SELECT i.*,r.peer_id FROM ue_federation_request_items i '
            . 'JOIN ue_federation_requests r ON r.id=i.request_id WHERE i.id=? AND r.peer_id=?',
            [$itemId, (int)$peer['id']]
        );
        if (!$item) {
            throw new CatalogFederationApiException('Request item not found for this peer.', 404);
        }

        $detail = $message;
        if ($childLocalFileId !== null) {
            $detail .= ($detail !== '' ? "\n" : '') . 'Child local file ID: ' . $childLocalFileId;
        }
        if ($childMd5 !== '') {
            $detail .= ($detail !== '' ? "\n" : '') . 'Child MD5: ' . $childMd5;
        }
        if ($childSha1 !== '') {
            $detail .= ($detail !== '' ? "\n" : '') . 'Child SHA1: ' . $childSha1;
        }

        $this->db->prepare('UPDATE ue_federation_request_items SET status=?,status_message=? WHERE id=?')
            ->execute([$status, $detail, $itemId]);
        $this->updateParentRequestStatus((int)$item['request_id']);
        \fed_log($this->db, (int)$peer['id'], null, $status === 'failed' ? 'ERROR' : 'INFO', 'REQUEST_ITEM_STATUS_UPDATE', 'Item ' . $itemId . ' -> ' . $status);
        return ['ok' => true, 'item_id' => $itemId, 'status' => $status];
    }

    /** @return array<string,int> */
    private function statusSummary(int $requestId): array
    {
        $rows = \catalog_all(
            $this->db,
            'SELECT status,COUNT(*) c FROM ue_federation_request_items WHERE request_id=? GROUP BY status',
            [$requestId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['status']] = (int)$row['c'];
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function packageStatuses(int $peerId): array
    {
        $rows = \catalog_all(
            $this->db,
            'SELECT i.id item_id,i.required_package,i.status item_status,i.status_message,r.id request_id,r.status request_status,r.created_at '
            . 'FROM ue_federation_request_items i JOIN ue_federation_requests r ON r.id=i.request_id '
            . 'WHERE r.peer_id=? AND r.direction="child_to_parent" ORDER BY r.created_at DESC,i.id DESC',
            [$peerId]
        );
        $out = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string)$row['required_package']));
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'request_id' => (int)$row['request_id'],
                'request_status' => (string)$row['request_status'],
                'item_id' => (int)$row['item_id'],
                'item_status' => (string)$row['item_status'],
                'status_message' => (string)($row['status_message'] ?? ''),
                'created_at' => (string)$row['created_at'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'created_at' => (string)($row['created_at'] ?? ''),
            'id' => (int)($row['id'] ?? 0),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return null|array{created_at:string,id:int} */
    private function decodeCursor(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $padding = (4 - (strlen($token) % 4)) % 4;
        $decoded = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            return null;
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }
        $createdAt = trim((string)($payload['created_at'] ?? ''));
        $id = (int)($payload['id'] ?? 0);
        return $createdAt !== '' && $id > 0 ? ['created_at' => $createdAt, 'id' => $id] : null;
    }

    private function updateParentRequestStatus(int $requestId): void
    {
        $counts = \catalog_one(
            $this->db,
            'SELECT COUNT(*) total,SUM(status IN ("imported","skipped_already_have","denied","failed")) finished,'
            . 'SUM(status="imported") imported,SUM(status="failed") failed '
            . 'FROM ue_federation_request_items WHERE request_id=?',
            [$requestId]
        );
        if (!$counts || (int)$counts['total'] <= 0) {
            return;
        }
        if ((int)$counts['finished'] >= (int)$counts['total']) {
            $newStatus = (int)$counts['failed'] > 0 ? 'failed' : 'completed';
            $this->db->prepare(
                'UPDATE ue_federation_requests SET status=? WHERE id=? AND status NOT IN ("cancelled","denied","updated")'
            )->execute([$newStatus, $requestId]);
        } elseif ((int)$counts['imported'] > 0) {
            $this->db->prepare(
                'UPDATE ue_federation_requests SET status="downloading" '
                . 'WHERE id=? AND status IN ("approved","part_approved","submitted")'
            )->execute([$requestId]);
        }
    }
}
