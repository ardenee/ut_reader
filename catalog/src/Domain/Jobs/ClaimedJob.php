<?php
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
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $type,
        public readonly array $payload,
        public readonly string $leaseToken,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $leaseExpiresAt
    ) {
    }
}
