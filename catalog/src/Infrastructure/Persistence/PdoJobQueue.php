<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Durable MySQL queue for deployments that do not yet run Redis or SQS.
 *
 * Claims use a short transaction and row-level locking. A lease makes work
 * recoverable after a worker crash; completion/failure updates require the
 * same lease token so an expired worker cannot overwrite a newer claim.
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

        if ($dedupeKey === null) {
            $statement = $this->db->prepare(
                'INSERT INTO ue_background_jobs '
                . '(queue_name, job_type, payload_json, priority, status, available_at, max_attempts, created_by) '
                . 'VALUES (?, ?, ?, ?, "queued", ?, ?, ?)'
            );
            $statement->execute([
                $queue,
                $type,
                $payloadJson,
                $priority,
                $availableAt->format('Y-m-d H:i:s'),
                $maxAttempts,
                $createdBy,
            ]);

            return (int)$this->db->lastInsertId();
        }

        // The unique queue/dedupe key only exists while a job is active. An
        // existing queued/running job is returned unchanged; terminal jobs
        // clear dedupe_key and may be scheduled again as new work.
        $statement = $this->db->prepare(
            'INSERT INTO ue_background_jobs '
            . '(queue_name, job_type, payload_json, priority, status, available_at, max_attempts, dedupe_key, created_by) '
            . 'VALUES (?, ?, ?, ?, "queued", ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), updated_at=updated_at'
        );
        $statement->execute([
            $queue,
            $type,
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
        $leaseToken = bin2hex(random_bytes(16));
        $now = self::now();
        $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds');

        $this->db->beginTransaction();
        try {
            $this->requeueExpiredLeases($queue, $now);

            $statement = $this->db->prepare(
                'SELECT * FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND available_at<=? '
                . 'ORDER BY priority ASC, available_at ASC, id ASC '
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
                . 'SET status="running", attempts=attempts+1, worker_id=?, lease_token=?, leased_at=?, lease_expires_at=?, updated_at=? '
                . 'WHERE id=? AND status="queued"'
            );
            $update->execute([
                $workerId,
                $leaseToken,
                $now->format('Y-m-d H:i:s'),
                $leaseExpiresAt->format('Y-m-d H:i:s'),
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
                $leaseExpiresAt
            );
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function complete(ClaimedJob $job, array $result = []): void
    {
        $now = self::now()->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs '
            . 'SET status="completed", result_json=?, dedupe_key=NULL, lease_token=NULL, lease_expires_at=NULL, completed_at=?, updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([
            self::encodeJson($result),
            $now,
            $now,
            $job->id,
            $job->leaseToken,
        ]);
        $this->assertLeaseUpdate($statement->rowCount(), $job);
    }

    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): void
    {
        $now = self::now();
        $error = self::trimError($exception);
        $retry = $job->attempt < $job->maxAttempts && $retryDelaySeconds > 0;

        if ($retry) {
            $availableAt = $now->modify('+' . min(3600, $retryDelaySeconds) . ' seconds');
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs '
                . 'SET status="queued", available_at=?, lease_token=NULL, lease_expires_at=NULL, last_error=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                $availableAt->format('Y-m-d H:i:s'),
                $error,
                $now->format('Y-m-d H:i:s'),
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertLeaseUpdate($statement->rowCount(), $job);
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs '
            . 'SET status="failed", dedupe_key=NULL, lease_token=NULL, lease_expires_at=NULL, last_error=?, completed_at=?, updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([
            $error,
            $now->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $job->id,
            $job->leaseToken,
        ]);
        $this->assertLeaseUpdate($statement->rowCount(), $job);
    }

    public function heartbeat(ClaimedJob $job, int $leaseSeconds): bool
    {
        $leaseSeconds = max(15, min($leaseSeconds, 3600));
        $now = self::now();
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET lease_expires_at=?, updated_at=? '
            . 'WHERE id=? AND status="running" AND lease_token=?'
        );
        $statement->execute([
            $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $job->id,
            $job->leaseToken,
        ]);

        return $statement->rowCount() === 1;
    }

    private function requeueExpiredLeases(string $queue, DateTimeImmutable $now): void
    {
        $timestamp = $now->format('Y-m-d H:i:s');
        $retry = $this->db->prepare(
            'UPDATE ue_background_jobs '
            . 'SET status="queued", worker_id=NULL, lease_token=NULL, leased_at=NULL, lease_expires_at=NULL, available_at=?, updated_at=? '
            . 'WHERE queue_name=? AND status="running" AND lease_expires_at<? AND attempts<max_attempts'
        );
        $retry->execute([$timestamp, $timestamp, $queue, $timestamp]);

        $fail = $this->db->prepare(
            'UPDATE ue_background_jobs '
            . 'SET status="failed", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, leased_at=NULL, lease_expires_at=NULL, '
            . 'last_error=COALESCE(last_error, "Worker lease expired after maximum attempts."), completed_at=?, updated_at=? '
            . 'WHERE queue_name=? AND status="running" AND lease_expires_at<? AND attempts>=max_attempts'
        );
        $fail->execute([$timestamp, $timestamp, $queue, $timestamp]);
    }

    private function assertLeaseUpdate(int $affectedRows, ClaimedJob $job): void
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
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
        $text = get_class($exception) . ': ' . $exception->getMessage();
        return substr($text, 0, 60000);
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
