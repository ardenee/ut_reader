<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the domain class `JobResourceProfile` for job resource profile.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Domain model/contract code representing core catalog behavior without presentation concerns.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

final class JobResourceProfile
{
    public function __construct(
        public readonly string $resourceClass,
        public readonly int $limit,
        public readonly ?string $concurrencyKey = null
    ) {
        if ($resourceClass === '' || strlen($resourceClass) > 80) {
            throw new \InvalidArgumentException('Invalid job resource class.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid job resource limit.');
        }
        if ($concurrencyKey !== null && ($concurrencyKey === '' || strlen($concurrencyKey) > 191)) {
            throw new \InvalidArgumentException('Invalid job concurrency key.');
        }
    }
}
