<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns explicit durable-job retry compatibility transitions.
 * Why: Running-job recovery is based on detached-worker liveness, never elapsed time.
 * Role: Infrastructure persistence collaborator used by PdoJobQueue.
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

    /**
     * Compatibility method for older callers. Expired timestamps are diagnostic
     * only and must never change ownership of a running job.
     *
     * @return array{requeued:int,cancelled:int,dead_lettered:int}
     */
    public function recoverExpiredLeases(string $queue): array
    {
        PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        return ['requeued' => 0, 'cancelled' => 0, 'dead_lettered' => 0];
    }

    public function retryDeadLetter(int $jobId, ?DateTimeImmutable $availableAt = null): bool
    {
        if ($jobId < 1) {
            return false;
        }
        $availableAt = ($availableAt ?? PdoJobQueueSupport::now())->setTimezone(PdoJobQueueSupport::utc());
        $now = PdoJobQueueSupport::now()->format('Y-m-d H:i:s');
        // Restart means resume. Preserve progress_json so the handler can continue
        // from its last durable unit rather than replaying successful work. A
        // cancelled workflow unit is restartable too: cancellation is an
        // operator decision, not loss of the unit's recovery state.
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=NULL,result_json=NULL,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
            . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE id=? AND status IN ("dead_letter","failed","cancelled")'
        );
        $statement->execute([$availableAt->format('Y-m-d H:i:s'), $now, $jobId]);
        return $statement->rowCount() === 1;
    }
}
