<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Worker-facing queue adapter.
 *
 * All queue operations delegate to PdoJobQueue except completion and heartbeat.
 * These exact worker updates avoid placeholder ambiguity and permit renewable
 * long-running package leases without changing browser/API queue behaviour.
 */
final class WorkerJobQueue implements JobQueue
{
    private readonly PdoJobQueue $inner;

    public function __construct(private readonly PDO $db)
    {
        $this->inner = new PdoJobQueue($db);
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
        return $this->inner->enqueue(
            $queue,
            $type,
            $payload,
            $priority,
            $availableAt,
            $dedupeKey,
            $createdBy,
            $maxAttempts
        );
    }

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        return $this->inner->claim($queue, $workerId, $leaseSeconds);
    }

    public function complete(ClaimedJob $job, array $result = []): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            $select = $this->db->prepare(
                'SELECT cancel_requested_at,cancel_reason,leased_at,progress_json FROM ue_background_jobs '
                . 'WHERE id=? AND status="running" AND lease_token=? FOR UPDATE'
            );
            $select->execute([$job->id, $job->leaseToken]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
            }

            if (!empty($row['cancel_requested_at'])) {
                $reason = trim((string)($row['cancel_reason'] ?? ''));
                if ($reason === '') {
                    $reason = 'Cancellation requested.';
                }
                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
                    . 'lease_expires_at=NULL, last_heartbeat_at=NULL, '
                    . 'cancel_requested_at=COALESCE(cancel_requested_at,?), '
                    . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END, '
                    . 'completed_at=?, updated_at=? WHERE id=? AND status="running" AND lease_token=?'
                );
                $statement->execute([$now, substr($reason, 0, 1000), $now, $now, $job->id, $job->leaseToken]);
                $this->assertUpdated($statement->rowCount(), $job);
                $this->db->commit();
                return 'cancelled';
            }

            // Bind terminal output to this exact claimed row. This makes it
            // impossible for a list/API renderer to silently treat another job's
            // completion payload as belonging to the current row.
            $result['job_id'] = $job->id;
            $expectedOriginalName = trim((string)($job->payload['original_name'] ?? ''));
            if ($expectedOriginalName !== '') {
                $result['job_original_name'] = $expectedOriginalName;
            }
            $leasedAt = trim((string)($row['leased_at'] ?? ''));
            if ($leasedAt !== '') {
                $result['file_started_at'] = $leasedAt;
            }
            $result['file_completed_at'] = $now;

            $progress = [];
            if (!empty($row['progress_json'])) {
                $decoded = json_decode((string)$row['progress_json'], true);
                if (is_array($decoded)) {
                    $progress = $decoded;
                }
            }
            $progress['stage'] = 'completed';
            $progress['done'] = 100;
            $progress['total'] = 100;
            $progress['percent'] = 100;
            $progress['job_id'] = $job->id;
            if ($leasedAt !== '') {
                $progress['file_started_at'] = $progress['file_started_at'] ?? $leasedAt;
            }
            $progress['file_completed_at'] = $now;
            $resultMessage = trim((string)($result['message'] ?? ''));
            if ($resultMessage !== '') {
                $progress['message'] = $resultMessage;
            } elseif (trim((string)($progress['message'] ?? '')) === '') {
                $progress['message'] = 'Job #' . $job->id . ' completed.';
            }

            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="completed", result_json=?, dedupe_key=NULL, '
                . 'worker_id=NULL, lease_token=NULL, lease_expires_at=NULL, '
                . 'progress_json=?, progress_updated_at=?, completed_at=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                self::encodeJson($result),
                self::encodeJson($progress),
                $now,
                $now,
                $now,
                $job->id,
                $job->leaseToken,
            ]);
            $this->assertUpdated($statement->rowCount(), $job);
            $this->db->commit();
            return 'completed';
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): string
    {
        return $this->inner->fail($job, $exception, $retryDelaySeconds);
    }

    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress = []): string
    {
        $leaseSeconds = max(15, min($leaseSeconds, 6 * 3600));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $expires = $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s');

        if ($progress === []) {
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET lease_expires_at=?,last_heartbeat_at=?,updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([$expires, $timestamp, $timestamp, $job->id, $job->leaseToken]);
        } else {
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET lease_expires_at=?,last_heartbeat_at=?,progress_json=?,progress_updated_at=?,updated_at=? '
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
        if ($statement->rowCount() !== 1) {
            return 'lost';
        }

        $check = $this->db->prepare(
            'SELECT cancel_requested_at FROM ue_background_jobs '
            . 'WHERE id=? AND status="running" AND lease_token=?'
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
        return $this->inner->requestCancellation($jobId, $requestedBy, $reason);
    }

    public function cancelClaimed(ClaimedJob $job, string $reason = ''): void
    {
        $this->inner->cancelClaimed($job, $reason);
    }

    public function recoverExpiredLeases(string $queue): array
    {
        return $this->inner->recoverExpiredLeases($queue);
    }

    public function retryDeadLetter(int $jobId, ?DateTimeImmutable $availableAt = null): bool
    {
        return $this->inner->retryDeadLetter($jobId, $availableAt);
    }

    private function assertUpdated(int $affectedRows, ClaimedJob $job): void
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException('Job lease no longer belongs to this worker: ' . $job->id);
        }
    }

    /** @param array<string,mixed> $value */
    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
