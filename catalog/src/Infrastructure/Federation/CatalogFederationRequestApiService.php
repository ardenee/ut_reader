<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns signed federation request cancellation and child item-status lifecycle mutations.
 * Why: Request submit/status now have dedicated compatibility/read services; this class keeps only shared mutation ownership.
 * Role: Infrastructure federation request mutation service preserving cancel and item-status semantics.
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
                'SELECT * FROM ue_federation_requests '
                . 'WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC LIMIT 1',
                [(int)$peer['id']]
            );
        } else {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_requests '
                . 'WHERE id=? AND peer_id=? AND direction="child_to_parent"',
                [$requestId, (int)$peer['id']]
            );
        }
        if (!$request) {
            throw new CatalogFederationApiException('Request not found for this child.', 404);
        }
        if (in_array((string)$request['status'], ['completed', 'denied', 'updated', 'cancelled'], true)) {
            throw new CatalogFederationApiException(
                'Request cannot be cancelled from status: ' . (string)$request['status'],
                409
            );
        }

        $reason = trim((string)($payload['reason'] ?? 'Cancelled by child site.'));
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE ue_federation_requests '
                . 'SET status="cancelled",notes=CONCAT(COALESCE(notes,""),?) WHERE id=?'
            )->execute([
                "\n" . date('Y-m-d H:i:s') . ' - ' . $reason,
                (int)$request['id'],
            ]);
            $this->db->prepare(
                'UPDATE ue_federation_request_items SET status="failed",status_message=? '
                . 'WHERE request_id=? '
                . 'AND status IN ("requested","approved","queued","downloading","downloaded")'
            )->execute(['Request cancelled by child site.', (int)$request['id']]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'REQUEST_CANCELLED',
            'Request ' . (int)$request['id'] . ' cancelled by child.'
        );
        return ['ok' => true, 'request_id' => (int)$request['id'], 'status' => 'cancelled'];
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $payload @return array{ok:true,item_id:int,status:string} */
    public function updateItemStatus(array $peer, array $payload): array
    {
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException(
                'Only a paired child may update request item status.',
                403
            );
        }

        $itemId = (int)($payload['request_item_id'] ?? 0);
        $status = (string)($payload['status'] ?? '');
        $message = trim((string)($payload['message'] ?? ''));
        $childLocalFileId = isset($payload['child_local_file_id'])
            ? (int)$payload['child_local_file_id']
            : null;
        $childMd5 = strtolower(trim((string)($payload['md5'] ?? '')));
        $childSha1 = strtolower(trim((string)($payload['sha1'] ?? '')));
        $allowed = ['queued', 'downloading', 'downloaded', 'imported', 'failed', 'skipped_already_have'];
        if ($itemId <= 0 || !in_array($status, $allowed, true)) {
            throw new CatalogFederationApiException('Invalid request item status update.', 400);
        }

        $item = \catalog_one(
            $this->db,
            'SELECT i.*,r.peer_id FROM ue_federation_request_items i '
            . 'JOIN ue_federation_requests r ON r.id=i.request_id '
            . 'WHERE i.id=? AND r.peer_id=?',
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

        $this->db->prepare(
            'UPDATE ue_federation_request_items SET status=?,status_message=? WHERE id=?'
        )->execute([$status, $detail, $itemId]);
        $this->updateParentRequestStatus((int)$item['request_id']);
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            $status === 'failed' ? 'ERROR' : 'INFO',
            'REQUEST_ITEM_STATUS_UPDATE',
            'Item ' . $itemId . ' -> ' . $status
        );
        return ['ok' => true, 'item_id' => $itemId, 'status' => $status];
    }

    private function updateParentRequestStatus(int $requestId): void
    {
        $counts = \catalog_one(
            $this->db,
            'SELECT COUNT(*) total,'
            . 'SUM(status IN ("imported","skipped_already_have","denied","failed")) finished,'
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
                'UPDATE ue_federation_requests SET status=? '
                . 'WHERE id=? AND status NOT IN ("cancelled","denied","updated")'
            )->execute([$newStatus, $requestId]);
        } elseif ((int)$counts['imported'] > 0) {
            $this->db->prepare(
                'UPDATE ue_federation_requests SET status="downloading" '
                . 'WHERE id=? AND status IN ("approved","part_approved","submitted")'
            )->execute([$requestId]);
        }
    }
}
