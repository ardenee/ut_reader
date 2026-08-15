<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\System;

/** Immutable result for one production-readiness dependency. */
final readonly class ReadinessCheck
{
    public function __construct(
        public string $name,
        public bool $ready,
        public int $latencyMicros
    ) {
    }

    /** @return array{name:string,status:string,latency_ms:float} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->ready ? 'ready' : 'not_ready',
            'latency_ms' => round($this->latencyMicros / 1000, 3),
        ];
    }
}
