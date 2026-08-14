<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Claims the next runnable durable job.
 *
 * A worker must never report idle merely because advisory claim coordination is
 * unavailable. The queue row transition itself is protected by a transaction and
 * FOR UPDATE SKIP LOCKED. The advisory mutex is therefore best-effort only: it
 * normally serializes resource/concurrency decisions, but failure to acquire it
 * can never hide otherwise runnable work.
 */
final class PdoJobClaimer
{
    private ?string $databaseIdentity = null;

    public function __construct(private readonly PDO $db, ?PdoJobRecovery $legacyRecovery = null)
    {
    }

    public function claim(
        string $queue,
        string $workerId,
        int $leaseSeconds,
        ?int $preferredRootJobId = null,
        bool $requirePreferredRoot = false
    ): ?ClaimedJob {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $workerId = PdoJobQueueSupport::requiredIdentifier($workerId, 'worker id');
        $leaseSeconds = max(15, min($leaseSeconds, 6 * 3600));
        $preferredRootJobId = $preferredRootJobId !== null && $preferredRootJobId > 0
            ? $preferredRootJobId
            : null;

        // Keep the short advisory mutex when it is immediately available because
        // it makes resource/concurrency decisions deterministic across the small
        // local worker pool. It is deliberately NOT a prerequisite for claiming:
        // a stale/contended named lock must never turn a non-empty queue into an
        // apparently idle queue.
        $claimLock = $this->coordinationLockName($queue);
        $claimLockHeld = $this->tryAcquireLock($claimLock);

        try {
            $candidate = null;

            // Affinity only affects ordering. If this root has no runnable row,
            // immediately fall back to any valid queue job.
            if ($preferredRootJobId !== null) {
                $candidate = $this->lockNextValidCandidate($queue, $preferredRootJobId);
            }
            if ($candidate === null) {
                $candidate = $this->lockNextValidCandidate($queue, null);
            }
            if ($candidate === null) {
                return null;
            }

            $resourceClass = trim((string)($candidate['resource_class'] ?? 'default')) ?: 'default';
            $resourceLimit = max(1, (int)($candidate['resource_limit'] ?? 1));
            $concurrencyKey = $candidate['concurrency_key'] !== null
                ? trim((string)$candidate['concurrency_key'])
                : '';

            return $this->leaseLockedCandidate(
                $candidate,
                $workerId,
                $leaseSeconds,
                $resourceClass,
                $resourceLimit,
                $concurrencyKey
            );
        } finally {
            // A selected row leaves its transaction open until
            // leaseLockedCandidate() commits it. If anything exits early or
            // throws, never leak that row transaction into the next poll.
            $this->rollbackClaimTransaction();
            if ($claimLockHeld) {
                $this->releaseLock($claimLock);
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function lockNextValidCandidate(string $queue, ?int $preferredRootJobId): ?array
    {
        $this->db->beginTransaction();
        try {
            $where = [
                'j.queue_name=?',
                'j.status="queued"',
                'j.cancel_requested_at IS NULL',
                // Use database time so PHP/DB clock or timezone differences can
                // never make an already-available job invisible.
                'COALESCE(j.available_at,j.created_at)<=UTC_TIMESTAMP()',
                // Legacy rows may predate persisted resource policy. Normalize
                // NULL/blank class and NULL limit in SQL before evaluating the
                // runnable predicate rather than relying on PHP normalization
                // after the row has already been excluded.
                '(SELECT COUNT(*) FROM ue_background_jobs rr '
                    . 'WHERE rr.queue_name=j.queue_name AND rr.status="running" '
                    . 'AND COALESCE(NULLIF(rr.resource_class,""),"default")='
                    . 'COALESCE(NULLIF(j.resource_class,""),"default")) '
                    . '< GREATEST(1,COALESCE(j.resource_limit,1))',
                '(j.concurrency_key IS NULL OR j.concurrency_key="" OR NOT EXISTS('
                    . 'SELECT 1 FROM ue_background_jobs rk '
                    . 'WHERE rk.queue_name=j.queue_name AND rk.status="running" '
                    . 'AND rk.concurrency_key=j.concurrency_key LIMIT 1))',
            ];
            $params = [$queue];

            if ($preferredRootJobId !== null) {
                $where[] = '(j.id=? OR j.parent_job_id=?)';
                $params[] = $preferredRootJobId;
                $params[] = $preferredRootJobId;
            }

            // When the coordinator becomes available it gets a brief turn so it
            // can publish progress, compact legacy children and finalize. While it
            // is deferred, its runnable child rows naturally win.
            $order = $preferredRootJobId !== null
                ? '(j.parent_job_id IS NULL) DESC,j.priority ASC,j.available_at ASC,j.id ASC'
                : 'j.priority ASC,j.available_at ASC,j.id ASC';

            $statement = $this->db->prepare(
                'SELECT j.* FROM ue_background_jobs j WHERE ' . implode(' AND ', $where)
                . ' ORDER BY ' . $order . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $statement->execute($params);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }
            return $row;
        } catch (\Throwable $exception) {
            $this->rollbackClaimTransaction();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $row */
    private function leaseLockedCandidate(
        array $row,
        string $workerId,
        int $leaseSeconds,
        string $resourceClass,
        int $resourceLimit,
        string $concurrencyKey
    ): ClaimedJob {
        $now = PdoJobQueueSupport::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds');
        $leaseToken = bin2hex(random_bytes(16));
        $resumeProgress = [];
        if (!empty($row['progress_json'])) {
            $decoded = json_decode((string)$row['progress_json'], true);
            if (is_array($decoded)) {
                $resumeProgress = $decoded;
            }
        }

        try {
            $update = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="running",attempts=attempts+1,worker_id=?,lease_token=?,leased_at=?,'
                . 'lease_expires_at=?,last_heartbeat_at=?,updated_at=? '
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
                throw new \RuntimeException('Job claim lost before ownership update.');
            }
            $this->db->commit();

            $parentJobId = isset($row['parent_job_id']) && (int)$row['parent_job_id'] > 0
                ? (int)$row['parent_job_id']
                : null;
            $workflowUnitKey = trim((string)($row['workflow_unit_key'] ?? ''));

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
                $concurrencyKey !== '' ? $concurrencyKey : null,
                $resumeProgress,
                $parentJobId,
                $workflowUnitKey !== '' ? $workflowUnitKey : null
            );
        } catch (\Throwable $exception) {
            $this->rollbackClaimTransaction();
            throw $exception;
        }
    }

    private function coordinationLockName(string $queue): string
    {
        $identity = $this->databaseIdentity() . "\0" . $queue;
        return 'unrealdb:queue-claim:' . substr(hash('sha256', $identity), 0, 40);
    }

    private function databaseIdentity(): string
    {
        if ($this->databaseIdentity === null) {
            $this->databaseIdentity = (string)(
                $this->db->query('SELECT DATABASE()')->fetchColumn() ?: 'default'
            );
        }
        return $this->databaseIdentity;
    }

    private function tryAcquireLock(string $lockName): bool
    {
        try {
            $statement = $this->db->prepare('SELECT GET_LOCK(?,0)');
            $statement->execute([$lockName]);
            return (int)$statement->fetchColumn() === 1;
        } catch (\Throwable) {
            // Claim coordination is an optimization, never queue availability.
            return false;
        }
    }

    private function releaseLock(string $lockName): void
    {
        try {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (\Throwable $error) {
            error_log('[UnrealDB jobs] Could not release queue claim lock: ' . $error->getMessage());
        }
    }

    private function rollbackClaimTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
