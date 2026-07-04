<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

final class JobExecutionContext
{
    private int $lastHeartbeatAt;
    private readonly int $heartbeatIntervalSeconds;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly ClaimedJob $job,
        private readonly int $leaseSeconds
    ) {
        $this->lastHeartbeatAt = time();
        $this->heartbeatIntervalSeconds = max(5, min(30, intdiv(max(15, $leaseSeconds), 3)));
    }

    public function heartbeatIfDue(): void
    {
        if ((time() - $this->lastHeartbeatAt) >= $this->heartbeatIntervalSeconds) {
            $this->heartbeat();
        }
    }

    public function heartbeat(): void
    {
        if (!$this->queue->heartbeat($this->job, $this->leaseSeconds)) {
            throw new \RuntimeException('Job lease is no longer owned by this worker: ' . $this->job->id);
        }
        $this->lastHeartbeatAt = time();
    }
}
