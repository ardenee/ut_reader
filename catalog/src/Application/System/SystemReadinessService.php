<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\System;

use Throwable;
use UnrealDb\Catalog\Application\System\Contract\ReadinessProbe;

/**
 * Runs the critical dependency probes without knowing how any dependency is
 * implemented. This is intentionally synchronous and bounded: readiness checks
 * must remain cheap enough for load balancers and service managers.
 */
final class SystemReadinessService
{
    /** @param list<ReadinessProbe> $probes */
    public function __construct(private readonly array $probes)
    {
    }

    public function check(): SystemReadinessReport
    {
        $checks = [];
        $ready = true;

        foreach ($this->probes as $probe) {
            $started = hrtime(true);
            try {
                $probeReady = $probe->ready();
            } catch (Throwable) {
                $probeReady = false;
            }
            $latencyMicros = max(0, (int)round((hrtime(true) - $started) / 1000));
            $checks[] = new ReadinessCheck($probe->name(), $probeReady, $latencyMicros);
            $ready = $ready && $probeReady;
        }

        return new SystemReadinessReport($ready, $checks);
    }
}
