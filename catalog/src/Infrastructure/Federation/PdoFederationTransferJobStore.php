<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Claims and updates durable federation transfer/import jobs.
 * Why: Queue SQL, status transitions and progress persistence should have one PDO owner instead of being embedded in HTTP/file orchestration.
 * Role: Infrastructure persistence boundary for federation transfer jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class PdoFederationTransferJobStore
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function claimTransfer(): ?array
    {
        $this->db->beginTransaction();
        try {
            $sql = 'SELECT j.*,p.site_name peer_name,p.site_url,p.peer_site_id,p.shared_secret_plain,'
                . 'p.signature_algorithm,p.signing_public_key,p.signing_key_id,p.signing_revoked_at '
                . 'FROM ue_federation_transfer_jobs j '
                . 'JOIN ue_federation_peers p ON p.id=j.peer_id '
                . 'WHERE j.status="queued" '
                . 'AND j.direction IN ("parent_pull_from_child","download_from_parent","upload_to_parent") '
                . 'AND p.is_active=1 ORDER BY j.created_at ASC LIMIT 1 FOR UPDATE';
            $job = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->db->commit();
                return null;
            }
            $statement = $this->db->prepare(
                'UPDATE ue_federation_transfer_jobs '
                . 'SET status="running",started_at=NOW(),attempts=attempts+1,last_error=NULL '
                . 'WHERE id=? AND status="queued"'
            );
            $statement->execute([(int)$job['id']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Transfer claim was lost.');
            }
            $this->db->commit();
            return $job;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function claimDownloadedImport(): ?array
    {
        $this->db->beginTransaction();
        try {
            $job = $this->db->query(
                'SELECT * FROM ue_federation_transfer_jobs '
                . 'WHERE status="downloaded" AND incoming_path IS NOT NULL AND incoming_path<>"" '
                . 'ORDER BY finished_at ASC,id ASC LIMIT 1 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->db->commit();
                return null;
            }
            $statement = $this->db->prepare(
                'UPDATE ue_federation_transfer_jobs '
                . 'SET status="running",started_at=COALESCE(started_at,NOW()),last_error=NULL '
                . 'WHERE id=? AND status="downloaded"'
            );
            $statement->execute([(int)$job['id']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Federation import claim was lost.');
            }
            $this->db->commit();
            return $job;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function progressCallback(int $jobId): callable
    {
        $lastBytes = -1;
        $lastAt = 0.0;
        return function (int $bytes, int $total) use ($jobId, &$lastBytes, &$lastAt): void {
            $now = microtime(true);
            if ($bytes === $lastBytes
                || ($bytes < $total
                    && $bytes - $lastBytes < 1048576
                    && $now - $lastAt < 1.0)) {
                return;
            }
            $lastBytes = $bytes;
            $lastAt = $now;
            $this->db->prepare(
                'UPDATE ue_federation_transfer_jobs '
                . 'SET bytes_done=?,bytes_total=CASE WHEN ? > 0 THEN ? ELSE bytes_total END '
                . 'WHERE id=? AND status="running"'
            )->execute([$bytes, $total, $total, $jobId]);
        };
    }

    public function markDownloaded(
        int $jobId,
        int $bytes,
        string $incomingPath,
        string $md5,
        string $sha1,
        string $message
    ): void {
        $this->db->prepare(
            'UPDATE ue_federation_transfer_jobs '
            . 'SET status="downloaded",bytes_total=?,bytes_done=?,incoming_path=?,'
            . 'downloaded_md5=?,downloaded_sha1=?,finished_at=NOW(),last_error=? WHERE id=?'
        )->execute([$bytes, $bytes, $incomingPath, $md5, $sha1, $message, $jobId]);
    }

    public function markUploaded(
        int $jobId,
        int $bytes,
        string $md5,
        string $sha1,
        string $message
    ): void {
        $this->db->prepare(
            'UPDATE ue_federation_transfer_jobs '
            . 'SET status="imported",bytes_total=?,bytes_done=?,downloaded_md5=?,'
            . 'downloaded_sha1=?,finished_at=NOW(),last_error=? WHERE id=?'
        )->execute([$bytes, $bytes, $md5, $sha1, $message, $jobId]);
    }

    public function markImportResult(
        int $jobId,
        string $status,
        ?int $localFileId,
        ?string $incomingPath,
        string $message
    ): void {
        $this->db->prepare(
            'UPDATE ue_federation_transfer_jobs '
            . 'SET status=?,local_file_id=?,incoming_path=?,finished_at=NOW(),last_error=? WHERE id=?'
        )->execute([$status, $localFileId, $incomingPath, $message, $jobId]);
    }

    public function markFailed(int $jobId, string $message): void
    {
        $this->db->prepare(
            'UPDATE ue_federation_transfer_jobs '
            . 'SET status="failed",finished_at=NOW(),last_error=? WHERE id=?'
        )->execute([$message, $jobId]);
    }
}
