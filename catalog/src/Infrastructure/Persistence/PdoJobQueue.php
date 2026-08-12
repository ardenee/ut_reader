<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the application JobQueue contract through focused PDO persistence collaborators.
 * Why: Queue enqueue, claim, lease and recovery responsibilities have different correctness/performance concerns and should not live in one monolithic repository.
 * Role: Infrastructure façade; contains no duplicated lifecycle SQL.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use UnrealDb\Catalog\Application\Jobs\JobQueue;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/** Durable MySQL queue façade. */
final class PdoJobQueue implements JobQueue
{
    private const CLAIM_CONTENTION_ATTEMPTS = 6;
    private const COMPLETE_CONTENTION_ATTEMPTS = 6;

    private readonly PdoJobEnqueuer $enqueuer;
    private readonly PdoJobRecovery $recovery;
    private readonly PdoJobClaimer $claimer;
    private readonly PdoJobLeaseStore $leases;

    public function __construct(PDO $db)
    {
        $this->enqueuer = new PdoJobEnqueuer($db);
        $this->recovery = new PdoJobRecovery($db);
        $this->claimer = new PdoJobClaimer($db, $this->recovery);
        $this->leases = new PdoJobLeaseStore($db);
    }

    public function enqueue(
        string $queue,
        string $type,
        array $payload,
        int $priority = 100,
        ?DateTimeImmutable $availableAt = null,
        ?string $dedupeKey = null,
        ?int $createdBy = null,
        int $maxAttempts = 3,
        ?int $parentJobId = null,
        ?string $workflowUnitKey = null
    ): int {
        return $this->enqueuer->enqueue(
            $queue,
            $type,
            $payload,
            $priority,
            $availableAt,
            $dedupeKey,
            $createdBy,
            $maxAttempts,
            $parentJobId,
            $workflowUnitKey
        );
    }

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->claimer->claim($queue, $workerId, $leaseSeconds);
            } catch (\Throwable $exception) {
                if (!PdoJobQueueSupport::retryableContention($exception)
                    || $attempt >= self::CLAIM_CONTENTION_ATTEMPTS) {
                    throw $exception;
                }
                usleep(PdoJobQueueSupport::contentionBackoffMicros($attempt));
            }
        }
    }

    public function complete(ClaimedJob $job, array $result = []): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->leases->complete($job, $result);
            } catch (\Throwable $exception) {
                if (!PdoJobQueueSupport::retryableContention($exception)
                    || $attempt >= self::COMPLETE_CONTENTION_ATTEMPTS) {
                    throw $exception;
                }
                usleep(PdoJobQueueSupport::contentionBackoffMicros($attempt));
            }
        }
    }

    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): string
    {
        return $this->leases->fail($job, $exception, $retryDelaySeconds);
    }

    public function defer(ClaimedJob $job, int $delaySeconds, array $progress = []): void
    {
        $this->leases->defer($job, $delaySeconds, $progress);
    }

    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress = []): string
    {
        return $this->leases->heartbeat($job, $leaseSeconds, $progress);
    }

    public function requestCancellation(int $jobId, ?int $requestedBy = null, string $reason = ''): string
    {
        return $this->leases->requestCancellation($jobId, $requestedBy, $reason);
    }

    public function cancelClaimed(ClaimedJob $job, string $reason = ''): void
    {
        $this->leases->cancelClaimed($job, $reason);
    }

    public function recoverExpiredLeases(string $queue): array
    {
        return $this->recovery->recoverExpiredLeases($queue);
    }

    public function retryDeadLetter(int $jobId, ?DateTimeImmutable $availableAt = null): bool
    {
        return $this->recovery->retryDeadLetter($jobId, $availableAt);
    }
}
