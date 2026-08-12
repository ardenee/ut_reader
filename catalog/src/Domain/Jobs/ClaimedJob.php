<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the domain class `ClaimedJob` for claimed job.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Domain model/contract code representing core catalog behavior without presentation concerns.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

use DateTimeImmutable;

/**
 * Immutable lease returned to a worker after a successful queue claim.
 */
final class ClaimedJob
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $resumeProgress Last durable progress snapshot from a prior attempt/recovery.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $type,
        public readonly array $payload,
        public readonly string $leaseToken,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $leaseExpiresAt,
        public readonly string $resourceClass = 'default',
        public readonly int $resourceLimit = 1,
        public readonly ?string $concurrencyKey = null,
        public readonly array $resumeProgress = []
    ) {
    }
}
