<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\System;

/** Aggregate readiness state across all critical application dependencies. */
final readonly class SystemReadinessReport
{
    /** @param list<ReadinessCheck> $checks */
    public function __construct(
        public bool $ready,
        public array $checks
    ) {
    }

    /** @return list<array{name:string,status:string,latency_ms:float}> */
    public function checkData(): array
    {
        return array_map(
            static fn(ReadinessCheck $check): array => $check->toArray(),
            $this->checks
        );
    }
}
