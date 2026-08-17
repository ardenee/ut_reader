<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `JobExecutionContext` for job execution context.
 * Why: It keeps this responsibility in the application layer rather than repeating it in page/API/job entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
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
    private readonly JobPerformanceTrace $performance;
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
        $longRunningJob = in_array(
            $job->type,
            [
                JobType::FULL_SYNC_GAME,
                JobType::PREPARE_BUCKET_REDIRECT,
                JobType::PROCESS_BUCKET_UPLOAD,
                JobType::PROCESS_BUCKET_ARCHIVE,
                JobType::PROCESS_BUCKET_STAGED_PACKAGE,
                JobType::REPAIR_UNVERIFIED_METADATA,
                JobType::IMPORT_STAGED_PACKAGE,
                JobType::IMPORT_STAGED_PAK,
                JobType::IMPORT_STAGED_ARCHIVE,
                JobType::REBUILD_AFFECTED_DEPENDENCIES,
                JobType::SCAN_POSSIBLE_MISNAMED_FILES,
            ],
            true
        );
        $this->leaseSeconds = $longRunningJob
            ? max($leaseSeconds, 6 * 3600)
            : $leaseSeconds;
        $this->lastHeartbeatAt = time();
        $this->heartbeatIntervalSeconds = max(5, min(30, intdiv(max(15, $this->leaseSeconds), 3)));
        $this->performance = new JobPerformanceTrace();
    }

    /** @return array<string,mixed> */
    public function resumeProgress(): array
    {
        return $this->job->resumeProgress;
    }

    /**
     * Release the current queue row while durable child work advances. By
     * default the worker remains assigned to this root workflow, so its next
     * claim stays inside the same job instead of jumping to another parent.
     * A genuinely blocked workflow can explicitly release that affinity.
     *
     * @param array<string,mixed> $progress
     * @return never
     */
    public function defer(
        int $delaySeconds = 2,
        array $progress = [],
        bool $retainWorkerAffinity = true
    ): never {
        if ($progress === []) {
            $progress = $this->pendingProgress !== [] ? $this->pendingProgress : $this->job->resumeProgress;
        }
        throw new JobDeferred(max(1, $delaySeconds), $progress, $retainWorkerAffinity);
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

        $snapshot = $this->withTelemetry($this->pendingProgress);
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

    /**
     * Add diagnostic timing/resource data to the existing durable progress
     * payload. Telemetry is fail-open: instrumentation must never decide whether
     * useful job work succeeds, retries, cancels or completes.
     *
     * @param array<string,mixed> $progress
     * @return array<string,mixed>
     */
    private function withTelemetry(array $progress): array
    {
        if ($progress === []) {
            return $progress;
        }

        try {
            $stage = trim((string)($progress['stage'] ?? 'running')) ?: 'running';
            $this->performance->observe($stage);
            $progress['job_telemetry'] = $this->performance->snapshot();
        } catch (\Throwable) {
            // Diagnostics are intentionally non-functional and fail open.
        }

        return $progress;
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
