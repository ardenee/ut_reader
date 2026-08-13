<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

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
        $preferredRootJobId = $preferredRootJobId !== null && $preferredRootJobId > 0 ? $preferredRootJobId : null;

        $blockedClasses = [];
        $blockedKeys = [];
        while (true) {
            $candidate = null;
            if ($preferredRootJobId !== null) {
                $candidate = $this->lockNextCandidate($queue, $blockedClasses, $blockedKeys, $preferredRootJobId);
                if ($candidate === null) {
                    if ($requirePreferredRoot && $this->workflowOpen($queue, $preferredRootJobId)) {
                        return null;
                    }
                    $preferredRootJobId = null;
                }
            }
            if ($candidate === null) {
                $candidate = $this->lockNextCandidate($queue, $blockedClasses, $blockedKeys, null);
            }
            if ($candidate === null) {
                return null;
            }

            $resourceClass = trim((string)($candidate['resource_class'] ?? 'default')) ?: 'default';
            $resourceLimit = max(1, (int)($candidate['resource_limit'] ?? 1));
            $concurrencyKey = $candidate['concurrency_key'] !== null ? trim((string)$candidate['concurrency_key']) : '';

            $resourceLock = $this->coordinationLockName('resource', $queue, $resourceClass);
            if (!$this->acquireLock($resourceLock, 2)) {
                $this->rollbackClaimTransaction();
                $blockedClasses[$resourceClass] = true;
                continue;
            }

            $keyLock = null;
            try {
                if ($concurrencyKey !== '') {
                    $keyLock = $this->coordinationLockName('key', $queue, $concurrencyKey);
                    if (!$this->acquireLock($keyLock, 2)) {
                        $this->rollbackClaimTransaction();
                        $blockedKeys[$concurrencyKey] = true;
                        continue;
                    }
                }
                if ($this->runningResourceCount($queue, $resourceClass) >= $resourceLimit) {
                    $this->rollbackClaimTransaction();
                    $blockedClasses[$resourceClass] = true;
                    continue;
                }
                if ($concurrencyKey !== '' && $this->concurrencyKeyRunning($queue, $concurrencyKey)) {
                    $this->rollbackClaimTransaction();
                    $blockedKeys[$concurrencyKey] = true;
                    continue;
                }
                return $this->leaseLockedCandidate($candidate, $workerId, $leaseSeconds, $resourceClass, $resourceLimit, $concurrencyKey);
            } finally {
                if ($keyLock !== null) {
                    $this->releaseLock($keyLock);
                }
                $this->releaseLock($resourceLock);
            }
        }
    }

    private function lockNextCandidate(string $queue, array $blockedClasses, array $blockedKeys, ?int $preferredRootJobId): ?array
    {
        $this->db->beginTransaction();
        try {
            $where = ['queue_name=?', 'status="queued"', 'cancel_requested_at IS NULL', 'available_at<=?'];
            $params = [$queue, PdoJobQueueSupport::now()->format('Y-m-d H:i:s')];
            if ($preferredRootJobId !== null && $preferredRootJobId > 0) {
                $where[] = '(id=? OR parent_job_id=?)';
                $params[] = $preferredRootJobId;
                $params[] = $preferredRootJobId;
            }
            if ($blockedClasses !== []) {
                $classes = array_keys($blockedClasses);
                $where[] = 'resource_class NOT IN (' . implode(',', array_fill(0, count($classes), '?')) . ')';
                array_push($params, ...$classes);
            }
            if ($blockedKeys !== []) {
                $keys = array_keys($blockedKeys);
                $where[] = '(concurrency_key IS NULL OR concurrency_key NOT IN (' . implode(',', array_fill(0, count($keys), '?')) . '))';
                array_push($params, ...$keys);
            }
            $statement = $this->db->prepare(
                'SELECT * FROM ue_background_jobs WHERE ' . implode(' AND ', $where)
                . ' ORDER BY priority ASC,available_at ASC,id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
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
            'SELECT 1 FROM ue_background_jobs WHERE queue_name=? AND (id=? OR parent_job_id=?) '
            . 'AND status IN ("queued","running") LIMIT 1'
        );
        $statement->execute([$queue, $rootJobId, $rootJobId]);
        return $statement->fetchColumn() !== false;
    }

    private function leaseLockedCandidate(array $row, string $workerId, int $leaseSeconds, string $resourceClass, int $resourceLimit, string $concurrencyKey): ClaimedJob
    {
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
                . 'lease_expires_at=?,last_heartbeat_at=?,updated_at=? WHERE id=? AND status="queued" AND cancel_requested_at IS NULL'
            );
            $update->execute([$workerId, $leaseToken, $timestamp, $leaseExpiresAt->format('Y-m-d H:i:s'), $timestamp, $timestamp, (int)$row['id']]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Job claim lost before ownership update.');
            }
            $this->db->commit();
            $parentJobId = isset($row['parent_job_id']) && (int)$row['parent_job_id'] > 0 ? (int)$row['parent_job_id'] : null;
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

    private function runningResourceCount(string $queue, string $resourceClass): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=? AND status="running" AND resource_class=?');
        $statement->execute([$queue, $resourceClass]);
        return (int)$statement->fetchColumn();
    }

    private function concurrencyKeyRunning(string $queue, string $concurrencyKey): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM ue_background_jobs WHERE queue_name=? AND status="running" AND concurrency_key=? LIMIT 1');
        $statement->execute([$queue, $concurrencyKey]);
        return (bool)$statement->fetchColumn();
    }

    private function coordinationLockName(string $kind, string $queue, string $value): string
    {
        $identity = $this->databaseIdentity() . "\0" . $queue . "\0" . $kind . "\0" . $value;
        return 'unrealdb:' . $kind . ':' . substr(hash('sha256', $identity), 0, 40);
    }

    private function databaseIdentity(): string
    {
        if ($this->databaseIdentity === null) {
            $this->databaseIdentity = (string)($this->db->query('SELECT DATABASE()')->fetchColumn() ?: 'default');
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
            error_log('[UnrealDB jobs] Could not release claim coordination lock: ' . $error->getMessage());
        }
    }

    private function rollbackClaimTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
