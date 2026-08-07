<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `JobExecutionContext` for job execution context.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
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
        $longRunningImport = in_array(
            $job->type,
            [
                JobType::PREPARE_BUCKET_REDIRECT,
                JobType::PROCESS_BUCKET_UPLOAD,
                JobType::REPAIR_UNVERIFIED_METADATA,
                JobType::IMPORT_STAGED_PACKAGE,
                JobType::IMPORT_STAGED_PAK,
            ],
            true
        );
        // Package readers can legitimately spend a long time inside one table
        // method before another checkpoint is possible. Keep the lease renewable
        // for six hours; operators still retain the explicit Stop job control.
        $this->leaseSeconds = $longRunningImport
            ? max($leaseSeconds, 6 * 3600)
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
        $importantStage = in_array($stage, ['complete', 'failed', 'unverified', 'cancelled', 'duplicate'], true);
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
