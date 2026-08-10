<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns durable background-job lease recovery and explicit retry transitions.
 * Why: Crash recovery is queue maintenance, not worker claim or HTTP presentation behaviour.
 * Role: Infrastructure persistence collaborator used by PdoJobQueue/PdoJobClaimer.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;

final class PdoJobRecovery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{requeued:int,cancelled:int,dead_lettered:int} */
    public function recoverExpiredLeases(string $queue): array
    {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $timestamp = PdoJobQueueSupport::now()->format('Y-m-d H:i:s');

        // Normal worker claims should not open a write transaction and execute
        // three status-changing UPDATE scans when there is nothing to recover.
        // This is a non-locking existence check, not a throttle: every claim can
        // still recover an expired lease immediately when one actually exists.
        $expired = $this->db->prepare(
            'SELECT 1 FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" AND lease_expires_at<? LIMIT 1'
        );
        $expired->execute([$queue, $timestamp]);
        if (!$expired->fetchColumn()) {
            return ['requeued' => 0, 'cancelled' => 0, 'dead_lettered' => 0];
        }

        $this->db->beginTransaction();
        try {
            $cancel = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,completed_at=?,updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND cancel_requested_at IS NOT NULL AND lease_expires_at<?'
            );
            $cancel->execute([$timestamp, $timestamp, $queue, $timestamp]);

            $retry = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",worker_id=NULL,lease_token=NULL,leased_at=NULL,'
                . 'lease_expires_at=NULL,last_heartbeat_at=NULL,available_at=?,recovery_count=recovery_count+1,'
                . 'last_error=COALESCE(last_error,"Worker lease expired; recovered for retry."),'
                . 'progress_json=NULL,progress_updated_at=NULL,updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND cancel_requested_at IS NULL '
                . 'AND lease_expires_at<? AND attempts<max_attempts'
            );
            $retry->execute([$timestamp, $timestamp, $queue, $timestamp]);

            $dead = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="dead_letter",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,recovery_count=recovery_count+1,'
                . 'last_error=COALESCE(last_error,"Worker lease expired after maximum attempts."),'
                . 'dead_lettered_at=?,completed_at=?,updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND cancel_requested_at IS NULL '
                . 'AND lease_expires_at<? AND attempts>=max_attempts'
            );
            $dead->execute([$timestamp, $timestamp, $timestamp, $queue, $timestamp]);

            $result = [
                'requeued' => $retry->rowCount(),
                'cancelled' => $cancel->rowCount(),
                'dead_lettered' => $dead->rowCount(),
            ];
            $this->db->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function retryDeadLetter(int $jobId, ?DateTimeImmutable $availableAt = null): bool
    {
        if ($jobId < 1) {
            return false;
        }
        $availableAt = ($availableAt ?? PdoJobQueueSupport::now())->setTimezone(PdoJobQueueSupport::utc());
        $now = PdoJobQueueSupport::now()->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=NULL,result_json=NULL,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,progress_json=NULL,progress_updated_at=NULL,'
            . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? WHERE id=? AND status IN ("dead_letter","failed")'
        );
        $statement->execute([$availableAt->format('Y-m-d H:i:s'), $now, $jobId]);
        return $statement->rowCount() === 1;
    }
}
