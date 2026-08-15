<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Claims the next runnable durable job.
 *
 * Candidate row ownership is protected by FOR UPDATE SKIP LOCKED. Resource-class
 * and concurrency-key admission is serialized separately by PdoJobAdmissionGuard,
 * so workers selecting different queue rows cannot race through the same limit.
 */
final class PdoJobClaimer
{
    public function __construct(private readonly PDO $db, ?PdoJobRecovery $legacyRecovery = null)
    {
    }

    public function claim(
        string $queue,
        string $workerId,
        int $leaseSeconds,
        ?int $preferredRootJobId = null
    ): ?ClaimedJob {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $workerId = PdoJobQueueSupport::requiredIdentifier($workerId, 'worker id');
        $leaseSeconds = max(15, min($leaseSeconds, 6 * 3600));
        $preferredRootJobId = $preferredRootJobId !== null && $preferredRootJobId > 0
            ? $preferredRootJobId
            : null;
        $guard = new PdoJobAdmissionGuard($this->db);

        // Root affinity is preference-only. If the preferred workflow has no
        // runnable row, immediately fall back to unrelated global work rather
        // than leaving a healthy worker idle.
        if ($preferredRootJobId !== null) {
            $preferred = $this->claimFromScope(
                $queue,
                $workerId,
                $leaseSeconds,
                $preferredRootJobId,
                $guard
            );
            if ($preferred !== null) {
                return $preferred;
            }
        }

        return $this->claimFromScope($queue, $workerId, $leaseSeconds, null, $guard);
    }

    private function claimFromScope(
        string $queue,
        string $workerId,
        int $leaseSeconds,
        ?int $preferredRootJobId,
        PdoJobAdmissionGuard $guard
    ): ?ClaimedJob {
        /** @var array<string,true> $blockedResourceClasses */
        $blockedResourceClasses = [];
        /** @var array<string,true> $blockedConcurrencyKeys */
        $blockedConcurrencyKeys = [];

        while (true) {
            $candidate = $this->lockNextCandidate(
                $queue,
                $preferredRootJobId,
                array_keys($blockedResourceClasses),
                array_keys($blockedConcurrencyKeys)
            );
            if ($candidate === null) {
                return null;
            }

            $resourceClass = trim((string)($candidate['resource_class'] ?? 'default')) ?: 'default';
            $persistedLimit = max(1, (int)($candidate['resource_limit'] ?? 1));
            $concurrencyKey = trim((string)($candidate['concurrency_key'] ?? ''));
            try {
                $lockResult = $guard->acquireWithBlocker(
                    $queue,
                    $resourceClass,
                    $concurrencyKey !== '' ? $concurrencyKey : null
                );
            } catch (\Throwable $error) {
                // lockNextCandidate deliberately leaves the candidate row locked
                // until admission completes. A GET_LOCK/driver failure must not
                // leak that transaction while the error propagates to the worker.
                $this->rollbackClaimTransaction();
                throw $error;
            }
            $locks = $lockResult['locks'];

            if ($locks === null) {
                $this->rollbackClaimTransaction();
                $this->rememberBlockedDimension(
                    (string)($lockResult['blocked_dimension'] ?? 'resource'),
                    $resourceClass,
                    $concurrencyKey,
                    $blockedResourceClasses,
                    $blockedConcurrencyKeys
                );
                continue;
            }

            try {
                $decision = $guard->decision(
                    $queue,
                    $resourceClass,
                    $persistedLimit,
                    $concurrencyKey !== '' ? $concurrencyKey : null
                );
                if (empty($decision['allowed'])) {
                    $this->rollbackClaimTransaction();
                    $this->rememberBlockedDimension(
                        (string)($decision['blocked_dimension'] ?? 'resource'),
                        $resourceClass,
                        $concurrencyKey,
                        $blockedResourceClasses,
                        $blockedConcurrencyKeys
                    );
                    continue;
                }

                return $this->leaseLockedCandidate(
                    $candidate,
                    $workerId,
                    $leaseSeconds,
                    $resourceClass,
                    max(1, (int)$decision['resource_limit']),
                    $concurrencyKey
                );
            } finally {
                $this->rollbackClaimTransaction();
                $guard->release($locks);
            }
        }
    }

    /**
     * @param list<string> $blockedResourceClasses
     * @param list<string> $blockedConcurrencyKeys
     * @return array<string,mixed>|null
     */
    private function lockNextCandidate(
        string $queue,
        ?int $preferredRootJobId,
        array $blockedResourceClasses,
        array $blockedConcurrencyKeys
    ): ?array {
        $this->db->beginTransaction();
        try {
            $where = [
                'j.queue_name=?',
                'j.status="queued"',
                'j.cancel_requested_at IS NULL',
                'COALESCE(j.available_at,j.created_at)<=UTC_TIMESTAMP()',
            ];
            $params = [$queue];

            if ($preferredRootJobId !== null) {
                $where[] = '(j.id=? OR j.parent_job_id=?)';
                $params[] = $preferredRootJobId;
                $params[] = $preferredRootJobId;
            }

            if ($blockedResourceClasses !== []) {
                $where[] = 'COALESCE(NULLIF(j.resource_class,""),"default") NOT IN ('
                    . implode(',', array_fill(0, count($blockedResourceClasses), '?')) . ')';
                foreach ($blockedResourceClasses as $resourceClass) {
                    $params[] = $resourceClass;
                }
            }

            if ($blockedConcurrencyKeys !== []) {
                $where[] = '(j.concurrency_key IS NULL OR j.concurrency_key="" OR j.concurrency_key NOT IN ('
                    . implode(',', array_fill(0, count($blockedConcurrencyKeys), '?')) . '))';
                foreach ($blockedConcurrencyKeys as $concurrencyKey) {
                    $params[] = $concurrencyKey;
                }
            }

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

    /**
     * A saturated resource class blocks every row in that class. An occupied
     * concurrency key blocks only rows carrying that key. Recording the semantic
     * blocker prevents an arbitrarily long run of high-priority blocked rows from
     * starving unrelated runnable jobs later in the queue.
     *
     * @param array<string,true> $blockedResourceClasses
     * @param array<string,true> $blockedConcurrencyKeys
     */
    private function rememberBlockedDimension(
        string $dimension,
        string $resourceClass,
        string $concurrencyKey,
        array &$blockedResourceClasses,
        array &$blockedConcurrencyKeys
    ): void {
        if ($dimension === 'concurrency' && $concurrencyKey !== '') {
            $blockedConcurrencyKeys[$concurrencyKey] = true;
            return;
        }
        $blockedResourceClasses[$resourceClass] = true;
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

    private function rollbackClaimTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
