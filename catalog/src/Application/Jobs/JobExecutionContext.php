<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class JobExecutionContext
{
    private int $lastHeartbeatAt;
    private readonly int $heartbeatIntervalSeconds;
    private readonly int $leaseSeconds;
    /** @var array<string,mixed> */
    private array $pendingProgress = [];

    public function __construct(
        private readonly JobQueue $queue,
        private readonly ClaimedJob $job,
        int $leaseSeconds
    ) {
        // Staged package imports can spend several minutes in redirect archive
        // decompression, a large file copy, or a parser operation before another
        // progress callback is available. Give both ordinary packages and PAKs
        // the queue's supported one-hour lease so another worker cannot reclaim
        // the same durable staged file while the original import is still alive.
        $longStagedImport = in_array(
            $job->type,
            [JobType::IMPORT_STAGED_PACKAGE, JobType::IMPORT_STAGED_PAK],
            true
        );
        $this->leaseSeconds = $longStagedImport
            ? max($leaseSeconds, 3600)
            : $leaseSeconds;
        $this->lastHeartbeatAt = time();
        $this->heartbeatIntervalSeconds = max(5, min(30, intdiv(max(15, $this->leaseSeconds), 3)));
    }

    /** @param array<string,mixed> $progress */
    public function heartbeatIfDue(array $progress = []): void
    {
        if ($progress !== []) {
            $this->pendingProgress = $progress;
        }
        if ((time() - $this->lastHeartbeatAt) >= $this->heartbeatIntervalSeconds) {
            $this->heartbeat();
        }
    }

    /** @param array<string,mixed> $progress */
    public function checkpoint(array $progress = []): void
    {
        if ($progress !== []) {
            $this->pendingProgress = $progress;
        }
        $this->heartbeat();
    }

    /** @param array<string,mixed> $progress */
    public function heartbeat(array $progress = []): void
    {
        if ($progress !== []) {
            $this->pendingProgress = $progress;
        }

        $state = $this->queue->heartbeat($this->job, $this->leaseSeconds, $this->pendingProgress);
        if ($state === 'cancel_requested') {
            throw new JobCancellationRequested('Job cancellation was requested: ' . $this->job->id);
        }
        if ($state !== 'active') {
            throw new \RuntimeException('Job lease is no longer owned by this worker: ' . $this->job->id);
        }

        $this->pendingProgress = [];
        $this->lastHeartbeatAt = time();
    }
}
