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
     */
    public function complete(ClaimedJob $job, array $result = []): void;

    public function fail(ClaimedJob $job, \Throwable $exception, int $retryDelaySeconds): void;

    public function heartbeat(ClaimedJob $job, int $leaseSeconds): bool;
}
