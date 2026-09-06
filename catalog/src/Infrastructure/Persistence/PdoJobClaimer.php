<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use PDOStatement;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/**
 * Claims the next runnable durable job.
 *
 * Candidate row ownership is protected by FOR UPDATE SKIP LOCKED. Resource-class
 * and concurrency-key admission is serialized separately by PdoJobAdmissionGuard.
 * A detached worker also holds one MySQL named lock for its current root workflow
 * so each worker stays on one source/file tree until that tree is terminal.
 */
final class PdoJobClaimer
{
    private readonly PdoJobAdmissionGuard $admissionGuard;
    private ?int $ownedRootJobId = null;
    private string $ownedRootQueue = '';
    private ?string $ownedRootLockName = null;
    private ?PDOStatement $acquireRootLockStatement = null;
    private ?PDOStatement $releaseRootLockStatement = null;

    public function __construct(private readonly PDO $db, ?PdoJobRecovery $legacyRecovery = null)
    {
        // PdoJobQueue retains one claimer for the worker connection lifetime. Keep
        // one admission guard and one root-affinity lock with that connection.
        $this->admissionGuard = new PdoJobAdmissionGuard($db);
    }

    public function __destruct()
    {
        $this->releaseRootAffinity();
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
        $guard = $this->admissionGuard;

        if ($preferredRootJobId !== null) {
            // Strict root affinity: while this root still has queued/running work,
            // never fall through to an unrelated source merely because the next
            // child is delayed or temporarily blocked by a resource limit.
            if (!$this->ensureRootAffinity($queue, $preferredRootJobId)) {
                return null;
            }

            $preferred = $this->claimFromScope(
                $queue,
                $workerId,
                $leaseSeconds,
                $preferredRootJobId,
                $guard,
                false
            );
            if ($preferred !== null) {
                return $preferred;
            }
            if ($this->workflowOpen($queue, $preferredRootJobId)) {
                return null;
            }

            $this->releaseRootAffinity();
        } else {
            // A worker that intentionally released its application affinity must
            // release the corresponding database root lock before choosing again.
            $this->releaseRootAffinity();
        }

        // New work is selected from execution roots. Ordinary workflows use a
        // persisted top-level row; direct source jobs created by a profiled upload
        // batch are also roots because the batch is only a planning coordinator.
        // Full Sync file/dependency children are independent execution roots too:
        // one game coordinator may therefore feed several workers without putting
        // the whole 70k-file game behind one root-affinity lock.
        // Each selected source/unit gets its own persistent root lock so workers
        // drain that file/archive/unit and its descendants independently.
        return $this->claimFromScope($queue, $workerId, $leaseSeconds, null, $guard, true);
    }

    private function claimFromScope(
        string $queue,
        string $workerId,
        int $leaseSeconds,
        ?int $preferredRootJobId,
        PdoJobAdmissionGuard $guard,
        bool $claimNewRoot
    ): ?ClaimedJob {
        /** @var array<string,true> $blockedResourceClasses */
        $blockedResourceClasses = [];
        /** @var array<string,true> $blockedConcurrencyKeys */
        $blockedConcurrencyKeys = [];
        /** @var array<int,true> $blockedJobIds */
        $blockedJobIds = [];

        while (true) {
            $candidate = $this->lockNextCandidate(
                $queue,
                $preferredRootJobId,
                array_keys($blockedResourceClasses),
                array_keys($blockedConcurrencyKeys),
                array_keys($blockedJobIds),
                $claimNewRoot
            );
            if ($candidate === null) {
                return null;
            }

            $resolvedRootJobId = $preferredRootJobId ?? (int)$candidate['id'];
            $rootLockAcquiredForCandidate = false;
            if ($claimNewRoot) {
                if (!$this->ensureRootAffinity($queue, $resolvedRootJobId)) {
                    $this->rollbackClaimTransaction();
                    $blockedJobIds[$resolvedRootJobId] = true;
                    continue;
                }
                $rootLockAcquiredForCandidate = true;
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
                $this->rollbackClaimTransaction();
                if ($rootLockAcquiredForCandidate) {
                    $this->releaseRootAffinity();
                }
                throw $error;
            }
            $locks = $lockResult['locks'];

            if ($locks === null) {
                $this->rollbackClaimTransaction();
                if ($rootLockAcquiredForCandidate) {
                    $this->releaseRootAffinity();
                }
                $this->rememberBlockedDimension(
                    (string)($lockResult['blocked_dimension'] ?? 'resource'),
                    $resourceClass,
                    $concurrencyKey,
                    $blockedResourceClasses,
                    $blockedConcurrencyKeys
                );
                continue;
            }

            $leaseSucceeded = false;
            try {
                $decision = $guard->decision(
                    $queue,
                    $resourceClass,
                    $persistedLimit,
                    $concurrencyKey !== '' ? $concurrencyKey : null
                );
                if (empty($decision['allowed'])) {
                    $this->rollbackClaimTransaction();
                    if ($rootLockAcquiredForCandidate) {
                        $this->releaseRootAffinity();
                    }
                    $this->rememberBlockedDimension(
                        (string)($decision['blocked_dimension'] ?? 'resource'),
                        $resourceClass,
                        $concurrencyKey,
                        $blockedResourceClasses,
                        $blockedConcurrencyKeys
                    );
                    continue;
                }

                $claimed = $this->leaseLockedCandidate(
                    $candidate,
                    $workerId,
                    $leaseSeconds,
                    $resourceClass,
                    max(1, (int)$decision['resource_limit']),
                    $concurrencyKey,
                    $resolvedRootJobId
                );
                $leaseSucceeded = true;
                return $claimed;
            } finally {
                $this->rollbackClaimTransaction();
                $guard->release($locks);
                if ($rootLockAcquiredForCandidate && !$leaseSucceeded) {
                    $this->releaseRootAffinity();
                }
            }
        }
    }

    /**
     * @param list<string> $blockedResourceClasses
     * @param list<string> $blockedConcurrencyKeys
     * @param list<int> $blockedJobIds
     * @return array<string,mixed>|null
     */
    private function lockNextCandidate(
        string $queue,
        ?int $preferredRootJobId,
        array $blockedResourceClasses,
        array $blockedConcurrencyKeys,
        array $blockedJobIds,
        bool $rootOnly
    ): ?array {
        $this->db->beginTransaction();
        try {
            $cte = '';
            $params = [];
            if ($preferredRootJobId !== null) {
                $cte = 'WITH RECURSIVE root_scope AS ('
                    . 'SELECT id FROM ue_background_jobs WHERE queue_name=? AND id=? '
                    . 'UNION ALL '
                    . 'SELECT child.id FROM ue_background_jobs child '
                    . 'INNER JOIN root_scope parent ON child.parent_job_id=parent.id '
                    . 'WHERE child.queue_name=?'
                    . ') ';
                $params[] = $queue;
                $params[] = $preferredRootJobId;
                $params[] = $queue;
            }

            $where = [
                'j.queue_name=?',
                'j.status="queued"',
                'j.cancel_requested_at IS NULL',
                'COALESCE(j.available_at,j.created_at)<=UTC_TIMESTAMP()',
            ];
            $params[] = $queue;

            if ($preferredRootJobId !== null) {
                $where[] = 'EXISTS (SELECT 1 FROM root_scope scope WHERE scope.id=j.id)';
            } elseif ($rootOnly) {
                $where[] = '('
                    . 'j.parent_job_id IS NULL OR '
                    . 'EXISTS(SELECT 1 FROM ue_background_jobs execution_parent '
                    . 'WHERE execution_parent.id=j.parent_job_id '
                    . 'AND execution_parent.queue_name=j.queue_name '
                    . 'AND ('
                    . 'execution_parent.job_type="' . JobType::PROFILED_UPLOAD_BATCH . '" OR '
                    . '(execution_parent.job_type="' . JobType::FULL_SYNC_GAME . '" '
                    . 'AND j.job_type IN ("' . JobType::FULL_SYNC_FILE . '","'
                    . JobType::FULL_SYNC_DEPENDENCY_FILE . '"))'
                    . '))'
                    . ')';
            }

            if ($blockedJobIds !== []) {
                $where[] = 'j.id NOT IN (' . implode(',', array_fill(0, count($blockedJobIds), '?')) . ')';
                foreach ($blockedJobIds as $jobId) {
                    $params[] = $jobId;
                }
            }

            if ($blockedResourceClasses !== []) {
                $where[] = 'j.resource_class NOT IN ('
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

            // Descendants run before the root coordinator when both are ready.
            // This lets one worker drain child work instead of repeatedly polling
            // the coordinator while runnable children are already queued.
            $order = $preferredRootJobId !== null
                ? '(j.parent_job_id IS NULL) ASC,j.priority ASC,j.available_at ASC,j.id ASC'
                : 'j.priority ASC,j.available_at ASC,j.id ASC';

            $statement = $this->db->prepare(
                $cte . 'SELECT j.* FROM ue_background_jobs j WHERE ' . implode(' AND ', $where)
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

    private function workflowOpen(string $queue, int $rootJobId): bool
    {
        $statement = $this->db->prepare(
            'WITH RECURSIVE root_scope AS ('
            . 'SELECT id FROM ue_background_jobs WHERE queue_name=? AND id=? '
            . 'UNION ALL '
            . 'SELECT child.id FROM ue_background_jobs child '
            . 'INNER JOIN root_scope parent ON child.parent_job_id=parent.id '
            . 'WHERE child.queue_name=?'
            . ') '
            . 'SELECT 1 FROM ue_background_jobs j '
            . 'INNER JOIN root_scope scope ON scope.id=j.id '
            . 'WHERE j.queue_name=? AND j.status IN ("queued","running") LIMIT 1'
        );
        $statement->execute([$queue, $rootJobId, $queue, $queue]);
        $open = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        return $open;
    }

    private function ensureRootAffinity(string $queue, int $rootJobId): bool
    {
        if ($this->ownedRootJobId === $rootJobId && $this->ownedRootQueue === $queue) {
            return true;
        }
        $this->releaseRootAffinity();

        $lockName = 'unrealdb:root:' . substr(hash('sha256', $queue . "\0" . $rootJobId), 0, 40);
        $statement = $this->acquireRootLockStatement ??= $this->db->prepare('SELECT GET_LOCK(?,0)');
        $statement->execute([$lockName]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ($result === false || $result === null) {
            throw new \RuntimeException('MySQL did not return a valid root-affinity lock result.');
        }
        if ((int)$result !== 1) {
            return false;
        }

        $this->ownedRootJobId = $rootJobId;
        $this->ownedRootQueue = $queue;
        $this->ownedRootLockName = $lockName;
        return true;
    }

    private function releaseRootAffinity(): void
    {
        $lockName = $this->ownedRootLockName;
        $this->ownedRootJobId = null;
        $this->ownedRootQueue = '';
        $this->ownedRootLockName = null;
        if ($lockName === null || $lockName === '') {
            return;
        }
        try {
            $statement = $this->releaseRootLockStatement ??= $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
            $statement->fetchColumn();
            $statement->closeCursor();
        } catch (\Throwable $error) {
            error_log('[UnrealDB jobs] Could not release root-affinity lock: ' . $error->getMessage());
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
        string $concurrencyKey,
        int $resolvedRootJobId
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
                $workflowUnitKey !== '' ? $workflowUnitKey : null,
                $resolvedRootJobId
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
