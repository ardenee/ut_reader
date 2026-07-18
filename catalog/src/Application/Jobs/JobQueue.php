<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use DateTimeImmutable;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

interface JobQueue
{
    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $queue,
        string $type,
        array $payload,
        int $priority = 100,
        ?DateTimeImmutable $availableAt = null,
        ?string $dedupeKey = null,
        ?int $createdBy = null,
        int $maxAttempts = 3
    ): int;

    public function claim(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob;

    /**
     * @param array<string, mixed> $result
     * @return 'completed'|'cancelled'
     */
    public function complete(ClaimedJob $job, array $result = []): string;

    /**
     * @return 'retry_queued'|'dead_letter'|'cancelled'
     */
    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): string;

    /**
     * @param array<string,mixed> $progress
     * @return 'active'|'cancel_requested'|'lost'
     */
    public function heartbeat(ClaimedJob $job, int $leaseSeconds, array $progress = []): string;

    /**
     * @return 'cancelled'|'cancel_requested'|'not_found'|'completed'|'failed'|'dead_letter'
     */
    public function requestCancellation(int $jobId, ?int $requestedBy = null, string $reason = ''): string;

    public function cancelClaimed(ClaimedJob $job, string $reason = ''): void;

    /** @return array{requeued:int,cancelled:int,dead_lettered:int} */
    public function recoverExpiredLeases(string $queue): array;

    public function retryDeadLetter(int $jobId, ?DateTimeImmutable $availableAt = null): bool;
}
