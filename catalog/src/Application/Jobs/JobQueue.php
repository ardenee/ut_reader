<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `JobQueue` for job queue.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
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
        int $maxAttempts = 3,
        ?int $parentJobId = null,
        ?string $workflowUnitKey = null
    ): int;

    public function claim(
        string $queue,
        string $workerId,
        int $leaseSeconds,
        ?int $preferredRootJobId = null,
        bool $requirePreferredRoot = false
    ): ?ClaimedJob;

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
     * Return a coordinator to the queue without treating waiting as an error or consuming an attempt.
     * @param array<string,mixed> $progress
     */
    public function defer(ClaimedJob $job, int $delaySeconds, array $progress = []): void;

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
