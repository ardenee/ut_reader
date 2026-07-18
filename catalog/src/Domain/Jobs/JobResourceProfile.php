<?php
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
