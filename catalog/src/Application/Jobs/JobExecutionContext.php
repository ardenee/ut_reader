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
    private string $lastEventStage = '';
    private int $lastEventPercent = -1;
    private int $lastEventAt = 0;

    public function __construct(
        private readonly JobQueue $queue,
        private readonly ClaimedJob $job,
        int $leaseSeconds,
        private readonly ?\Closure $eventAppender = null
    ) {
        // Redirect decompression, staged imports and large PAK work can spend
        // several minutes in CPU, disk or parser operations before another
        // progress callback is available. Use the queue's supported one-hour
        // lease so a healthy job cannot be reclaimed by a second worker.
        $longRunningImport = in_array(
            $job->type,
            [JobType::PREPARE_BUCKET_REDIRECT, JobType::IMPORT_STAGED_PACKAGE, JobType::IMPORT_STAGED_PAK],
            true
        );
        $this->leaseSeconds = $longRunningImport
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
        $this->heartbeat([], true);
    }

    /** @param array<string,mixed> $progress */
    public function heartbeat(array $progress = [], bool $forceEvent = false): void
    {
        if ($progress !== []) {
            $this->pendingProgress = $progress;
        }

        $snapshot = $this->pendingProgress;
        $state = $this->queue->heartbeat($this->job, $this->leaseSeconds, $snapshot);
        if ($state === 'cancel_requested') {
            throw new JobCancellationRequested('Job cancellation was requested: ' . $this->job->id);
        }
        if ($state !== 'active') {
            throw new \RuntimeException('Job lease is no longer owned by this worker: ' . $this->job->id);
        }

        if ($snapshot !== []) {
            $this->emitProgressEvent($snapshot, $forceEvent);
        }
        $this->pendingProgress = [];
        $this->lastHeartbeatAt = time();
    }

    /** @param array<string,mixed> $progress */
    private function emitProgressEvent(array $progress, bool $force): void
    {
        if (!$this->eventAppender instanceof \Closure) {
            return;
        }

        $stage = trim((string)($progress['stage'] ?? 'running')) ?: 'running';
        $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));
        $now = time();
        $importantStage = in_array($stage, ['complete', 'failed', 'unverified', 'cancelled'], true);
        $shouldEmit = $force
            || $importantStage
            || $stage !== $this->lastEventStage
            || $this->lastEventPercent < 0
            || abs($percent - $this->lastEventPercent) >= 2
            || ($now - $this->lastEventAt) >= 5;
        if (!$shouldEmit) {
            return;
        }

        $message = trim((string)($progress['message'] ?? ''));
        if ($message === '') {
            $message = ucfirst(str_replace('_', ' ', $stage)) . '.';
        }
        $file = trim((string)($this->job->payload['source_relative_path'] ?? $this->job->payload['original_name'] ?? ''));
        $meta = $progress;
        unset($meta['message']);
        $eventStatus = $stage === 'complete' ? 'completed' : ($importantStage ? $stage : 'running');

        try {
            ($this->eventAppender)(
                $this->job->id,
                [
                    'status' => $eventStatus,
                    'file' => $file,
                    'message' => '[' . str_replace('_', ' ', $stage) . '] ' . $message . ' (' . $percent . '%)',
                    'file_id' => max(0, (int)($progress['file_id'] ?? 0)),
                    'meta' => $meta,
                ]
            );
        } catch (\Throwable $error) {
            error_log('[UnrealDB job progress event] ' . $error->getMessage());
        }

        $this->lastEventStage = $stage;
        $this->lastEventPercent = $percent;
        $this->lastEventAt = $now;
    }
}
