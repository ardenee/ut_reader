<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Worker-facing queue adapter.
 *
 * All queue operations delegate to PdoJobQueue except completion. Completion is
 * implemented here with an exact seven-parameter UPDATE so a successfully
 * consumed staged upload cannot be incorrectly requeued by PDO HY093.
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
                'SELECT cancel_requested_at,cancel_reason FROM ue_background_jobs '
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
                    . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, '
                    . 'cancel_requested_at=COALESCE(cancel_requested_at,?), '
                    . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END, '
                    . 'completed_at=?, updated_at=? WHERE id=? AND status="running" AND lease_token=?'
                );
                $statement->execute([$now, substr($reason, 0, 1000), $now, $now, $job->id, $job->leaseToken]);
                $this->assertUpdated($statement->rowCount(), $job);
                $this->db->commit();
                return 'cancelled';
            }

            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="completed", result_json=?, dedupe_key=NULL, '
                . 'worker_id=NULL, lease_token=NULL, leased_at=NULL, lease_expires_at=NULL, '
                . 'progress_json=?, progress_updated_at=?, completed_at=?, updated_at=? '
                . 'WHERE id=? AND status="running" AND lease_token=?'
            );
            $statement->execute([
                self::encodeJson($result),
                self::encodeJson(['stage' => 'completed']),
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
        return $this->inner->heartbeat($job, $leaseSeconds, $progress);
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
