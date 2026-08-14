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
    private const MAX_BLOCKED_CANDIDATES_PER_SCOPE = 32;

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
        $guard = new PdoJobAdmissionGuard($this->db);

        if ($preferredRootJobId !== null) {
            $preferred = $this->claimFromScope(
                $queue,
                $workerId,
                $leaseSeconds,
                $preferredRootJobId,
                $guard
            );
            if ($preferred !== null || $requirePreferredRoot) {
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
        $excluded = [];

        for ($attempt = 0; $attempt < self::MAX_BLOCKED_CANDIDATES_PER_SCOPE; $attempt++) {
            $candidate = $this->lockNextCandidate($queue, $preferredRootJobId, $excluded);
            if ($candidate === null) {
                return null;
            }

            $candidateId = (int)($candidate['id'] ?? 0);
            $resourceClass = trim((string)($candidate['resource_class'] ?? 'default')) ?: 'default';
            $persistedLimit = max(1, (int)($candidate['resource_limit'] ?? 1));
            $concurrencyKey = trim((string)($candidate['concurrency_key'] ?? ''));
            $locks = $guard->acquire(
                $queue,
                $resourceClass,
                $concurrencyKey !== '' ? $concurrencyKey : null
            );

            if ($locks === null) {
                $this->rollbackClaimTransaction();
                if ($candidateId > 0) {
                    $excluded[] = $candidateId;
                }
                continue;
            }

            try {
                $resourceLimit = $guard->currentLimit($resourceClass, $persistedLimit);
                if (!$guard->canRun(
                    $queue,
                    $resourceClass,
                    $resourceLimit,
                    $concurrencyKey !== '' ? $concurrencyKey : null
                )) {
                    $this->rollbackClaimTransaction();
                    if ($candidateId > 0) {
                        $excluded[] = $candidateId;
                    }
                    continue;
                }

                return $this->leaseLockedCandidate(
                    $candidate,
                    $workerId,
                    $leaseSeconds,
                    $resourceClass,
                    $resourceLimit,
                    $concurrencyKey
                );
            } finally {
                $this->rollbackClaimTransaction();
                $guard->release($locks);
            }
        }

        return null;
    }

    /**
     * @param list<int> $excludedIds
     * @return array<string,mixed>|null
     */
    private function lockNextCandidate(
        string $queue,
        ?int $preferredRootJobId,
        array $excludedIds
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

            if ($excludedIds !== []) {
                $where[] = 'j.id NOT IN (' . implode(',', array_fill(0, count($excludedIds), '?')) . ')';
                foreach ($excludedIds as $excludedId) {
                    $params[] = $excludedId;
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
