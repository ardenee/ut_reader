<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;

/**
 * Durable MySQL queue with lease ownership, cooperative cancellation,
 * dead-letter recovery and persisted resource-class limits.
 */
final class PdoJobQueue implements JobQueue
{
    private const UTC = 'UTC';

    public function __construct(private readonly PDO $db)
    {
    }

    public function enqueue(
        string $queue,
        string $type,
        array $payload,
        int $priority = 100,
        ?DateTimeImmutable $availableAt = null,
        ?string $dedupeKey = null,
        ?int $createdBy = null,
        int $maxAttempts = 3
    ): int {
        $queue = self::requiredIdentifier($queue, 'queue');
        $type = self::requiredIdentifier($type, 'type');
        $dedupeKey = $dedupeKey === null ? null : self::optionalIdentifier($dedupeKey, 'dedupe key');
        $priority = max(0, min($priority, 1000));
        $maxAttempts = max(1, min($maxAttempts, 20));
        $payloadJson = self::encodeJson($payload);
        $availableAt = ($availableAt ?? self::now())->setTimezone(new DateTimeZone(self::UTC));
        $resource = JobResourcePolicy::for($type, $payload);

        if ($dedupeKey === null) {
            $statement = $this->db->prepare(
                'INSERT INTO ue_background_jobs '
                . '(queue_name, job_type, resource_class, resource_limit, concurrency_key, payload_json, priority, status, available_at, max_attempts, created_by) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, "queued", ?, ?, ?)'
            );
            $statement->execute([
                $queue,
                $type,
                $resource->resourceClass,
                $resource->limit,
                $resource->concurrencyKey,
                $payloadJson,
                $priority,
                $availableAt->format('Y-m-d H:i:s'),
                $maxAttempts,
                $createdBy,
            ]);
            return (int)$this->db->lastInsertId();
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_background_jobs '
            . '(queue_name, job_type, resource_class, resource_limit, concurrency_key, payload_json, priority, status, available_at, max_attempts, dedupe_key, created_by) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, "queued", ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), updated_at=updated_at'
        );
        $statement->execute([
            $queue,
            $type,
            $resource->resourceClass,
            $resource->limit,
            $resource->concurrencyKey,
            $payloadJson,
            $priority,
            $availableAt->format('Y-m-d H:i:s'),
            $maxAttempts,
            $dedupeKey,
            $createdBy,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        $queue = self::requiredIdentifier($queue, 'queue');
        $workerId = self::requiredIdentifier($workerId, 'worker id');
        $leaseSeconds = max(15, min($leaseSeconds, 3600));
        $claimLock = $this->claimLockName($queue);
        $this->acquireClaimLock($claimLock);

        try {
            $this->recoverExpiredLeases($queue);
            $leaseToken = bin2hex(random_bytes(16));
            $now = self::now();
            $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds');

            $this->db->beginTransaction();
            try {
                $statement = $this->db->prepare(
                    'SELECT candidate.* FROM ue_background_jobs candidate '
                    . 'WHERE candidate.queue_name=? AND candidate.status="queued" '
                    . 'AND candidate.cancel_requested_at IS NULL AND candidate.available_at<=? '
                    . 'AND (SELECT COUNT(*) FROM ue_background_jobs active '
                    . 'WHERE active.queue_name=candidate.queue_name AND active.status="running" '
                    . 'AND active.resource_class=candidate.resource_class) < candidate.resource_limit '
                    . 'AND (candidate.concurrency_key IS NULL OR NOT EXISTS ('
                    . 'SELECT 1 FROM ue_background_jobs keyed WHERE keyed.queue_name=candidate.queue_name '
                    . 'AND keyed.status="running" AND keyed.concurrency_key=candidate.concurrency_key)) '
                    . 'ORDER BY candidate.priority ASC, candidate.available_at ASC, candidate.id ASC '
                    . 'LIMIT 1 FOR UPDATE'
                );
                $statement->execute([$queue, $now->format('Y-m-d H:i:s')]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    $this->db->commit();
                    return null;
                }

                $update = $this->db->prepare(
                    'UPDATE ue_background_jobs '
                    . 'SET status="running", attempts=attempts+1, worker_id=?, lease_token=?, leased_at=?, '
                    . 'lease_expires_at=?, last_heartbeat_at=?, progress_json=NULL, progress_updated_at=NULL, updated_at=? '
                    . 'WHERE id=? AND status="queued" AND cancel_requested_at IS NULL'
                );
                $update->execute([
                    $workerId,
                    $leaseToken,
                    $now->format('Y-m-d H:i:s'),
                    $leaseExpiresAt->format('Y-m-d H:i:s'),
                    $now->format('Y-m-d H:i:s'),
                    $now->format('Y-m-d H:i:s'),
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
                    self::decodePayload((string)$row['payload_json']),
                    $leaseToken,
                    (int)$row['attempts'] + 1,
                    (int)$row['max_attempts'],
                    $leaseExpiresAt,
                    (string)$row['resource_class'],
                    (int)$row['resource_limit'],
                    $row['concurrency_key'] !== null ? (string)$row['concurrency_key'] : null
                );
            } catch (\Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        } finally {
            $this->releaseClaimLock($claimLock);
        }
    }

    public function complete(ClaimedJob $job, array $result = []): string
    {
        $now = self::now()->format('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $row = $this->lockLeaseRow($job);
            if (!empty($row['cancel_requested_at'])) {
                $this->transitionCancelled($job, (string)($row['cancel_reason'] ?? ''), $now);
                $this->db->commit();
                return 'cancelled';
            }

            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="completed", result_json=?, dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
                . 'leased_at=NULL, lease_expires_at=NULL, progress_json=?, progress_updated_at=?, completed_at=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                self::encodeJson($result),
                self::encodeJson(['stage' => 'completed']),
                $now,
                $now,
                $now,
                $now,
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertLeaseUpdate($statement->rowCount(), $job);
            $this->db->commit();
            return 'completed';
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): string
    {
        $now = self::now();
        $timestamp = $now->format('Y-m-d H:i:s');
        $error = self::trimError($exception);

        $this->db->beginTransaction();
        try {
            $row = $this->lockLeaseRow($job);
            if (!empty($row['cancel_requested_at'])) {
                $this->transitionCancelled($job, (string)($row['cancel_reason'] ?? $error), $timestamp);
                $this->db->commit();
                return 'cancelled';
            }

            if ($job->attempt < $job->maxAttempts && $retryDelaySeconds > 0) {
                $availableAt = $now->modify('+' . min(3600, $retryDelaySeconds) . ' seconds');
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="queued", available_at=?, worker_id=NULL, lease_token=NULL, leased_at=NULL, '
                    . 'lease_expires_at=NULL, last_heartbeat_at=NULL, last_error=?, progress_json=NULL, progress_updated_at=NULL, updated_at=? '
                    . 'WHERE id=? AND status="running" AND lease_token=?'
                );
                $statement->execute([
                    $availableAt->format('Y-m-d H:i:s'),
                    $error,
                    $timestamp,
                    $job->id,
                    $job->leaseToken,
                ]);
                $this->assertLeaseUpdate($statement->rowCount(), $job);
                $this->db->commit();
                return 'retry_queued';
            }

            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="dead_letter", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, leased_at=NULL, '
                . 'lease_expires_at=NULL, last_heartbeat_at=NULL, last_error=?, dead_lettered_at=?, completed_at=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                $error,
                $timestamp,
                $timestamp,
                $timestamp,
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertLeaseUpdate($statement->rowCount(), $job);
            $this->db->commit();
            return 'dead_letter';
        } catch (\Throwable $failure) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $failure;
        }
    }

    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress = []): string
    {
        $leaseSeconds = max(15, min($leaseSeconds, 3600));
        $now = self::now();
        $expires = $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s');
        $timestamp = $now->format('Y-m-d H:i:s');

        if ($progress === []) {
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET lease_expires_at=?, last_heartbeat_at=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([$expires, $timestamp, $timestamp, $job->id, $job->leaseToken]);
        } else {
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET lease_expires_at=?, last_heartbeat_at=?, progress_json=?, progress_updated_at=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                $expires,
                $timestamp,
                self::encodeJson($progress),
                $timestamp,
                $timestamp,
                $job->id,
                $job->leaseToken,
            ]);
        }

        $check = $this->db->prepare(
            'SELECT cancel_requested_at FROM ue_background_jobs WHERE id=? AND status="running" AND lease_token=?'
        );
        $check->execute([$job->id, $job->leaseToken]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'lost';
        }
        return empty($row['cancel_requested_at']) ? 'active' : 'cancel_requested';
    }

    public function requestCancellation(int $jobId, ?int $requestedBy = null, string $reason = ''): string
    {
        if ($jobId < 1) {
            return 'not_found';
        }
        $reason = self::trimReason($reason);
        $now = self::now()->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT status FROM ue_background_jobs WHERE id=? FOR UPDATE');
            $select->execute([$jobId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return 'not_found';
            }

            $status = (string)$row['status'];
            if ($status === 'queued') {
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, cancel_requested_at=?, '
                    . 'cancel_requested_by=?, cancel_reason=?, completed_at=?, updated_at=? WHERE id=? AND status="queued"'
                );
                $statement->execute([$now, $requestedBy, $reason, $now, $now, $jobId]);
                $this->db->commit();
                return 'cancelled';
            }
            if ($status === 'running') {
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET cancel_requested_at=COALESCE(cancel_requested_at,?), '
                    . 'cancel_requested_by=COALESCE(cancel_requested_by,?), '
                    . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END, updated_at=? '
                    . 'WHERE id=? AND status="running"'
                );
                $statement->execute([$now, $requestedBy, $reason, $now, $jobId]);
                $this->db->commit();
                return 'cancel_requested';
            }

            $this->db->commit();
            return in_array($status, ['completed', 'failed', 'dead_letter'], true) ? $status : 'cancelled';
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelClaimed(ClaimedJob $job, string $reason = ''): void
    {
        $now = self::now()->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
            . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, cancel_requested_at=COALESCE(cancel_requested_at,?), '
            . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END, completed_at=?, updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([$now, self::trimReason($reason), $now, $now, $job->id, $job->leaseToken]);
        $this->assertLeaseUpdate($statement->rowCount(), $job);
    }

    public function recoverExpiredLeases(string $queue): array
    {
        $queue = self::requiredIdentifier($queue, 'queue');
        $timestamp = self::now()->format('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $cancel = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
                . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, completed_at=?, updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND cancel_requested_at IS NOT NULL AND lease_expires_at<?'
            );
            $cancel->execute([$timestamp, $timestamp, $queue, $timestamp]);

            $retry = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued", worker_id=NULL, lease_token=NULL, leased_at=NULL, '
                . 'lease_expires_at=NULL, last_heartbeat_at=NULL, available_at=?, recovery_count=recovery_count+1, '
                . 'last_error=COALESCE(last_error,"Worker lease expired; recovered for retry."), '
                . 'progress_json=NULL, progress_updated_at=NULL, updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND cancel_requested_at IS NULL '
                . 'AND lease_expires_at<? AND attempts<max_attempts'
            );
            $retry->execute([$timestamp, $timestamp, $queue, $timestamp]);

            $dead = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="dead_letter", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
                . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, recovery_count=recovery_count+1, '
                . 'last_error=COALESCE(last_error,"Worker lease expired after maximum attempts."), '
                . 'dead_lettered_at=?, completed_at=?, updated_at=? '
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
        $availableAt = ($availableAt ?? self::now())->setTimezone(new DateTimeZone(self::UTC));
        $now = self::now()->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued", attempts=0, available_at=?, worker_id=NULL, lease_token=NULL, '
            . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, last_error=NULL, result_json=NULL, '
            . 'cancel_requested_at=NULL, cancel_requested_by=NULL, cancel_reason=NULL, progress_json=NULL, progress_updated_at=NULL, '
            . 'dead_lettered_at=NULL, completed_at=NULL, updated_at=? WHERE id=? AND status IN ("dead_letter","failed")'
        );
        $statement->execute([$availableAt->format('Y-m-d H:i:s'), $now, $jobId]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed> */
    private function lockLeaseRow(ClaimedJob $job): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM ue_background_jobs WHERE id=? AND status="running" AND lease_token=? FOR UPDATE'
        );
        $statement->execute([$job->id, $job->leaseToken]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
        }
        return $row;
    }

    private function transitionCancelled(ClaimedJob $job, string $reason, string $timestamp): void
    {
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
            . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, cancel_requested_at=COALESCE(cancel_requested_at,?), '
            . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END, completed_at=?, updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([$timestamp, self::trimReason($reason), $timestamp, $timestamp, $job->id, $job->leaseToken]);
        $this->assertLeaseUpdate($statement->rowCount(), $job);
    }

    private function claimLockName(string $queue): string
    {
        $database = (string)($this->db->query('SELECT DATABASE()')->fetchColumn() ?: 'default');
        return 'unrealdb:job-claim:' . substr(hash('sha256', $database . ':' . $queue), 0, 40);
    }

    private function acquireClaimLock(string $lockName): void
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?, 10)');
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

    private function assertLeaseUpdate(int $affectedRows, ClaimedJob $job): void
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
        }
    }

    private static function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private static function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Job payload must decode to an object.');
        }
        return $decoded;
    }

    private static function trimError(\Throwable $exception): string
    {
        return substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 60000);
    }

    private static function trimReason(string $reason): string
    {
        $reason = trim($reason);
        return substr($reason !== '' ? $reason : 'Cancellation requested.', 0, 1000);
    }

    private static function requiredIdentifier(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 120) {
            throw new \InvalidArgumentException('Invalid job ' . $label . '.');
        }
        return $value;
    }

    private static function optionalIdentifier(string $value, string $label): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 191) {
            throw new \InvalidArgumentException('Invalid job ' . $label . '.');
        }
        return $value;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::UTC));
    }
}
