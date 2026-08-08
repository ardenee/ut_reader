<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Selects and leases the next runnable durable background job.
 * Why: Claiming has unique concurrency/performance requirements and should not be mixed with enqueue/completion/recovery SQL.
 * Role: Infrastructure persistence collaborator used by PdoJobQueue.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

final class PdoJobClaimer
{
    public function __construct(
        private readonly PDO $db,
        private readonly PdoJobRecovery $recovery
    ) {
    }

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $workerId = PdoJobQueueSupport::requiredIdentifier($workerId, 'worker id');
        $leaseSeconds = max(15, min($leaseSeconds, 6 * 3600));
        $claimLock = $this->claimLockName($queue);
        $this->acquireClaimLock($claimLock);

        try {
            $this->recovery->recoverExpiredLeases($queue);
            return $this->claimUnderCoordinationLock($queue, $workerId, $leaseSeconds);
        } finally {
            $this->releaseClaimLock($claimLock);
        }
    }

    private function claimUnderCoordinationLock(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        $now = PdoJobQueueSupport::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds');
        $leaseToken = bin2hex(random_bytes(16));

        $this->db->beginTransaction();
        try {
            /*
             * Compute running pressure once. The previous candidate query used a
             * correlated COUNT and NOT EXISTS for every queued row considered by
             * MySQL. Under the existing queue-wide claim lock there can be only
             * one claimer changing running membership at a time, so one snapshot
             * preserves the same resource/concurrency decision semantics.
             */
            $runningByClass = [];
            $classQuery = $this->db->prepare(
                'SELECT resource_class,COUNT(*) AS running_count FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="running" GROUP BY resource_class'
            );
            $classQuery->execute([$queue]);
            foreach ($classQuery->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $runningByClass[(string)$row['resource_class']] = (int)$row['running_count'];
            }

            $runningKeys = [];
            $keyQuery = $this->db->prepare(
                'SELECT concurrency_key FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="running" AND concurrency_key IS NOT NULL'
            );
            $keyQuery->execute([$queue]);
            foreach ($keyQuery->fetchAll(PDO::FETCH_COLUMN) ?: [] as $key) {
                $key = (string)$key;
                if ($key !== '') {
                    $runningKeys[$key] = true;
                }
            }

            /*
             * Read only fields required to decide eligibility. This ordered scan
             * can use the claim index and does not materialize payload/result JSON.
             * It is intentionally unbounded so a runnable lower-priority-class job
             * is never hidden behind an arbitrary candidate batch limit.
             */
            $candidateQuery = $this->db->prepare(
                'SELECT id,resource_class,resource_limit,concurrency_key '
                . 'FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=? '
                . 'ORDER BY priority ASC,available_at ASC,id ASC'
            );
            $candidateQuery->execute([$queue, $timestamp]);
            $candidateId = 0;
            while (($candidate = $candidateQuery->fetch(PDO::FETCH_ASSOC)) !== false) {
                $resourceClass = (string)$candidate['resource_class'];
                $resourceLimit = max(1, (int)$candidate['resource_limit']);
                if (($runningByClass[$resourceClass] ?? 0) >= $resourceLimit) {
                    continue;
                }
                $concurrencyKey = $candidate['concurrency_key'] !== null
                    ? (string)$candidate['concurrency_key']
                    : '';
                if ($concurrencyKey !== '' && isset($runningKeys[$concurrencyKey])) {
                    continue;
                }
                $candidateId = (int)$candidate['id'];
                break;
            }
            $candidateQuery->closeCursor();

            if ($candidateId < 1) {
                $this->db->commit();
                return null;
            }

            // Lock only the chosen row. Revalidate eligibility because an explicit
            // administrator mutation does not participate in the claimer lock.
            $select = $this->db->prepare(
                'SELECT * FROM ue_background_jobs '
                . 'WHERE id=? AND queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=? '
                . 'FOR UPDATE'
            );
            $select->execute([$candidateId, $queue, $timestamp]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }

            $resourceClass = (string)$row['resource_class'];
            $resourceLimit = max(1, (int)$row['resource_limit']);
            $concurrencyKey = $row['concurrency_key'] !== null ? (string)$row['concurrency_key'] : '';
            if (($runningByClass[$resourceClass] ?? 0) >= $resourceLimit
                || ($concurrencyKey !== '' && isset($runningKeys[$concurrencyKey]))) {
                $this->db->commit();
                return null;
            }

            $update = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="running",attempts=attempts+1,worker_id=?,lease_token=?,leased_at=?,'
                . 'lease_expires_at=?,last_heartbeat_at=?,progress_json=NULL,progress_updated_at=NULL,updated_at=? '
                . 'WHERE id=? AND status="queued" AND cancel_requested_at IS NULL'
            );
            $update->execute([
                $workerId,
                $leaseToken,
                $timestamp,
                $leaseExpiresAt->format('Y-m-d H:i:s'),
                $timestamp,
                $timestamp,
                (int)$row['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Job claim lost before lease update.');
            }

            $this->db->commit();
            return new ClaimedJob(
                (int)$row['id'],
                (string)$row['queue_name'],
                (string)$row['job_type'],
                PdoJobQueueSupport::decodePayload((string)$row['payload_json']),
                $leaseToken,
                (int)$row['attempts'] + 1,
                (int)$row['max_attempts'],
                $leaseExpiresAt,
                $resourceClass,
                $resourceLimit,
                $concurrencyKey !== '' ? $concurrencyKey : null
            );
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function claimLockName(string $queue): string
    {
        $database = (string)($this->db->query('SELECT DATABASE()')->fetchColumn() ?: 'default');
        return 'unrealdb:job-claim:' . substr(hash('sha256', $database . ':' . $queue), 0, 40);
    }

    private function acquireClaimLock(string $lockName): void
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?,10)');
        $statement->execute([$lockName]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Could not acquire the job claim coordination lock.');
        }
    }

    private function releaseClaimLock(string $lockName): void
    {
        try {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (\Throwable $error) {
            error_log('[UnrealDB jobs] Could not release claim lock: ' . $error->getMessage());
        }
    }
}
