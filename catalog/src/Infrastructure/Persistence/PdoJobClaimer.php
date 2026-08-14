<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Claims the next runnable durable job.
 *
 * Claiming is intentionally simple: one very short queue-level mutex serializes
 * only the decision that changes a queued row to running. Within that critical
 * section a single SELECT chooses a row which is queued, available, not
 * cancelled, within its resource-class limit and not blocked by a running
 * concurrency key. Worker/root affinity affects ordering only; it can never make
 * a worker idle while another valid queue row is runnable.
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

        // The old claimer stacked independent resource/key GET_LOCK calls on top
        // of row locking. That made an otherwise runnable queue capable of
        // returning idle simply because claim-coordination locks disagreed. One
        // queue claim mutex is sufficient for a local 1-8 process worker pool and
        // keeps resource/concurrency checks atomic with the ownership transition.
        $claimLock = $this->coordinationLockName($queue);
        if (!$this->acquireLock($claimLock, 1)) {
            // Another worker is spending a few milliseconds claiming a row. This
            // worker will poll again; this is not evidence that the queue is idle.
            return null;
        }

        try {
            $candidate = null;

            // Affinity is a preference, never a gate. Prefer a runnable row from
            // the same root workflow, but immediately fall back to any valid job
            // if that root is deferred, blocked, completed or otherwise unable to
            // make progress now.
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
            $this->releaseLock($claimLock);
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
                'j.available_at<=?',
                // Resource limits are evaluated against rows actually owned now.
                // Because every ownership transition passes through the queue
                // claim mutex, two workers cannot race this count and both claim
                // the final slot.
                '(SELECT COUNT(*) FROM ue_background_jobs rr '
                    . 'WHERE rr.queue_name=j.queue_name AND rr.status="running" '
                    . 'AND rr.resource_class=j.resource_class) < GREATEST(1,j.resource_limit)',
                // A concurrency key blocks only while another row with that exact
                // key is genuinely running. Queued/deferred rows never block one
                // another.
                '(j.concurrency_key IS NULL OR j.concurrency_key="" OR NOT EXISTS('
                    . 'SELECT 1 FROM ue_background_jobs rk '
                    . 'WHERE rk.queue_name=j.queue_name AND rk.status="running" '
                    . 'AND rk.concurrency_key=j.concurrency_key LIMIT 1))',
            ];
            $params = [
                $queue,
                PdoJobQueueSupport::now()->format('Y-m-d H:i:s'),
            ];

            if ($preferredRootJobId !== null) {
                $where[] = '(j.id=? OR j.parent_job_id=?)';
                $params[] = $preferredRootJobId;
                $params[] = $preferredRootJobId;
            }

            // Within a preferred workflow, useful child work wins over the
            // coordinator row. Globally, normal durable priority/FIFO ordering is
            // retained.
            $order = $preferredRootJobId !== null
                ? '(j.parent_job_id IS NULL) ASC,j.priority ASC,j.available_at ASC,j.id ASC'
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

    private function acquireLock(string $lockName, int $timeoutSeconds): bool
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?,?)');
        $statement->execute([$lockName, max(0, $timeoutSeconds)]);
        return (int)$statement->fetchColumn() === 1;
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
