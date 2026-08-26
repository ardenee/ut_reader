<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes durable jobs while keeping a worker attached to one root workflow until that workflow is terminal.
 * Role: Application-layer orchestration shared by detached workers and other job runners.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use Closure;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class JobWorker
{
    private ?int $preferredRootJobId = null;

    /** @var array<string,JobHandler|Closure> */
    private array $handlersByType;

    /**
     * @param array<string,JobHandler|Closure> $handlersByType
     */
    public function __construct(
        private readonly JobQueue $queue,
        array $handlersByType,
        private readonly string $queueName,
        private readonly string $workerId,
        private readonly int $leaseSeconds = 120,
        private readonly ?Closure $eventAppender = null,
        private readonly ?Closure $diagnosticEnabled = null,
        private readonly ?Closure $failureReporter = null
    ) {
        self::assertCompleteHandlerMap($handlersByType);
        $this->handlersByType = $handlersByType;
    }

    /** @return array<string,mixed> */
    public function runOne(): array
    {
        $job = $this->queue->claim(
            $this->queueName,
            $this->workerId,
            $this->leaseSeconds,
            $this->preferredRootJobId
        );
        if ($job === null) {
            return [
                'status' => 'idle',
                'root_job_id' => $this->preferredRootJobId,
                'affinity_held' => $this->preferredRootJobId !== null,
            ];
        }

        $rootJobId = $job->rootJobId();
        $this->retainAffinity($rootJobId);
        $this->diagnostic(
            'claimed',
            $job,
            'Worker claimed the job row for root job #' . $rootJobId . '.'
        );

        $handler = $this->findHandler($job->type);
        if ($handler === null) {
            $this->releaseAffinity();
            return $this->deferUnknownJobType($job);
        }

        $context = new JobExecutionContext($this->queue, $job, $this->leaseSeconds, $this->eventAppender);
        try {
            $this->diagnostic('heartbeat_start', $job, 'Writing the initial worker heartbeat.');
            $resume = $context->resumeProgress();
            $context->heartbeat($resume !== [] ? $resume : [
                'stage' => 'worker_start',
                'done' => 1,
                'total' => 100,
                'percent' => 1,
                'attempt' => $job->attempt,
                'message' => 'Worker claimed job #' . $job->id . '; starting ' . $job->type . '.',
            ], true);
            $this->diagnostic('handler_start', $job, 'Initial heartbeat stored; entering the job handler.');
            $result = $handler->handle($job, $context);
        } catch (JobDeferred $deferred) {
            try {
                $this->queue->defer($job, $deferred->delaySeconds, $deferred->progress);
                if ($deferred->retainWorkerAffinity) {
                    $this->retainAffinity($rootJobId);
                } else {
                    $this->releaseAffinity();
                }
                $this->diagnostic(
                    'deferred',
                    $job,
                    $deferred->retainWorkerAffinity
                        ? 'Coordinator released its current row but kept worker affinity with root job #' . $rootJobId . '.'
                        : 'Coordinator released its worker because the root workflow is blocked.'
                );
                return [
                    'status' => 'deferred',
                    'job_id' => $job->id,
                    'root_job_id' => $rootJobId,
                    'type' => $job->type,
                    'affinity_held' => $deferred->retainWorkerAffinity,
                ];
            } catch (\Throwable $leaseError) {
                $this->releaseAffinity();
                $this->reportFailure($job, $leaseError, 'defer_persist_failed');
                return $this->failureResult('lease_lost', $job, $leaseError);
            }
        } catch (JobCancellationRequested $exception) {
            $message = $this->errorText($exception);
            $this->diagnostic('cancelled', $job, $message);
            try {
                $this->queue->cancelClaimed($job, $message);
            } catch (\Throwable $leaseError) {
                $this->releaseAffinity();
                $this->reportFailure($job, $leaseError, 'cancel_persist_failed');
                return $this->failureResult('lease_lost', $job, $leaseError);
            }
            if ($job->parentJobId === null) {
                $this->releaseAffinity();
            } else {
                $this->retainAffinity($rootJobId);
            }
            return $this->failureResult('cancelled', $job, $exception);
        } catch (\Throwable $exception) {
            $this->diagnostic(
                'exception',
                $job,
                get_class($exception) . ': ' . $this->errorText($exception) . ' at '
                    . str_replace('\\', '/', $exception->getFile()) . ':' . $exception->getLine()
            );
            $delay = JobFailureRetryPolicy::retryDelaySeconds($job, $exception);
            $failure = $this->recordFailure($job, $exception, $delay);
            $failureStatus = (string)($failure['status'] ?? 'failed');
            if ($job->parentJobId !== null || $failureStatus === 'retry_queued') {
                $this->retainAffinity($rootJobId);
            } else {
                $this->releaseAffinity();
            }
            return $failure;
        }

        try {
            $disposition = $this->queue->complete($job, $result);
            $this->diagnostic(
                $disposition === 'cancelled' ? 'completed_as_cancelled' : 'completed',
                $job,
                $disposition === 'cancelled'
                    ? 'Handler returned successfully, but a prior cancellation request won the terminal transition.'
                    : 'Handler returned and the queue row completed.'
            );

            if ($disposition === 'cancelled') {
                if ($job->parentJobId === null) {
                    $this->releaseAffinity();
                } else {
                    $this->retainAffinity($rootJobId);
                }
                return [
                    'status' => 'cancelled',
                    'job_id' => $job->id,
                    'root_job_id' => $rootJobId,
                    'type' => $job->type,
                    'result' => $result,
                    'affinity_held' => $job->parentJobId !== null,
                ];
            }

            if ($job->parentJobId === null) {
                $this->releaseAffinity();
            } else {
                $this->retainAffinity($rootJobId);
            }

            return [
                'status' => 'completed',
                'job_id' => $job->id,
                'root_job_id' => $rootJobId,
                'type' => $job->type,
                'result' => $result,
                'affinity_held' => $job->parentJobId !== null,
            ];
        } catch (\Throwable $completionError) {
            $this->releaseAffinity();
            $this->diagnostic(
                'completion_persist_failed',
                $job,
                get_class($completionError) . ': handler already returned successfully; terminal queue state could not be persisted: '
                    . $this->errorText($completionError)
            );
            $this->reportFailure($job, $completionError, 'completion_persist_failed');
            return $this->failureResult('completion_persist_failed', $job, $completionError);
        }
    }

    /** @return array{status:string,job_id:int,type:string} */
    private function deferUnknownJobType(ClaimedJob $job): array
    {
        $progress = $job->resumeProgress;
        $progress['queue_wait_reason'] = 'handler_unavailable';
        $progress['queue_wait_job_type'] = $job->type;
        $progress['message'] = 'This worker does not have a handler for ' . $job->type
            . '; leaving the job queued for a newer/restarted worker.';
        try {
            $this->queue->defer($job, 30, $progress);
            $this->diagnostic(
                'handler_unavailable_deferred',
                $job,
                'No handler is loaded for ' . $job->type . '; job returned to the queue without consuming an attempt.'
            );
            return ['status' => 'deferred', 'job_id' => $job->id, 'type' => $job->type];
        } catch (\Throwable $leaseError) {
            $this->reportFailure($job, $leaseError, 'unknown_handler_defer_failed');
            return ['status' => 'lease_lost', 'job_id' => $job->id, 'type' => $job->type];
        }
    }

    /** @return array<string,mixed> */
    private function recordFailure(ClaimedJob $job, \Throwable $exception, int $delay): array
    {
        try {
            $disposition = $this->queue->fail($job, $exception, $delay);
        } catch (\Throwable $leaseError) {
            $this->reportFailure($job, $leaseError, 'lease_lost');
            return $this->failureResult('lease_lost', $job, $leaseError);
        }

        if ($disposition !== 'cancelled') {
            $this->reportFailure($job, $exception, $disposition);
        }
        return $this->failureResult($disposition, $job, $exception);
    }

    /** @return array<string,mixed> */
    private function failureResult(string $status, ClaimedJob $job, \Throwable $exception): array
    {
        return [
            'status' => $status,
            'job_id' => $job->id,
            'root_job_id' => $job->rootJobId(),
            'type' => $job->type,
            'error' => $this->errorText($exception),
            'error_file' => str_replace('\\', '/', $exception->getFile()),
            'error_line' => $exception->getLine(),
        ];
    }

    private function retainAffinity(int $rootJobId): void
    {
        $this->preferredRootJobId = max(1, $rootJobId);
    }

    private function releaseAffinity(): void
    {
        $this->preferredRootJobId = null;
    }

    private function reportFailure(ClaimedJob $job, \Throwable $exception, string $disposition): void
    {
        if (!$this->failureReporter instanceof Closure) {
            return;
        }

        // Queue retries belong to job/event diagnostics, not the System Error
        // ledger. A retry has not reached a terminal failure and otherwise creates
        // a second red error row when the same attempt sequence eventually fails.
        if ($disposition === 'retry_queued') {
            return;
        }

        // Deterministic failures describe immutable source/package/archive bytes.
        // They remain retained and visible in Background Jobs -> Issues, where an
        // operator can replace/retry the source. They are not application defects
        // and should not obscure genuine code/infrastructure failures in the
        // System Error ledger.
        if (JobFailureRetryPolicy::isDeterministicFailure($job, $exception)) {
            return;
        }

        try {
            ($this->failureReporter)($job, $exception, $disposition);
        } catch (\Throwable $reportError) {
            error_log('[UnrealDB worker] failure reporter failed: ' . $reportError->getMessage());
        }
    }

    private function errorText(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '' || $message === "''" || $message === '\"\"') {
            return get_class($exception) . ' was thrown without an error message.';
        }
        return $message;
    }

    private function findHandler(string $type): ?JobHandler
    {
        $registered = $this->handlersByType[$type] ?? null;
        if ($registered instanceof JobHandler) {
            return $registered;
        }
        if (!$registered instanceof Closure) {
            return null;
        }

        $handler = $registered();
        if (!$handler instanceof JobHandler) {
            throw new \LogicException('Lazy job handler factory did not return JobHandler for type: ' . $type);
        }
        if (!$handler->supports($type)) {
            throw new \LogicException(get_class($handler) . ' does not support routed job type: ' . $type);
        }
        $this->handlersByType[$type] = $handler;
        return $handler;
    }

    /** @param array<string,JobHandler|Closure> $handlersByType */
    private static function assertCompleteHandlerMap(array $handlersByType): void
    {
        $knownTypes = array_fill_keys(JobType::all(), true);
        $unknownTypes = array_values(array_diff(array_keys($handlersByType), array_keys($knownTypes)));
        if ($unknownTypes !== []) {
            throw new \LogicException('Unknown job handler route(s): ' . implode(', ', $unknownTypes));
        }
        $missingTypes = array_values(array_diff(array_keys($knownTypes), array_keys($handlersByType)));
        if ($missingTypes !== []) {
            throw new \LogicException('Missing job handler route(s): ' . implode(', ', $missingTypes));
        }
        foreach ($handlersByType as $type => $handler) {
            if ($handler instanceof Closure) {
                continue;
            }
            if (!$handler instanceof JobHandler) {
                throw new \LogicException('Invalid job handler registered for type: ' . $type);
            }
            if (!$handler->supports($type)) {
                throw new \LogicException(get_class($handler) . ' does not support routed job type: ' . $type);
            }
        }
    }

    private function diagnostic(string $stage, ClaimedJob $job, string $message): void
    {
        if ($this->diagnosticEnabled instanceof Closure) {
            try {
                if (!(bool)($this->diagnosticEnabled)()) {
                    return;
                }
            } catch (\Throwable) {
                return;
            }
        }
        $payload = [
            'time' => gmdate(DATE_ATOM),
            'worker_id' => $this->workerId,
            'queue' => $this->queueName,
            'job_id' => $job->id,
            'root_job_id' => $job->rootJobId(),
            'job_type' => $job->type,
            'stage' => $stage,
            'message' => trim($message) !== '' ? trim($message) : 'Worker diagnostic message was empty.',
        ];
        try {
            error_log('[UnrealDB worker] ' . json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } catch (\Throwable) {
            error_log('[UnrealDB worker] job #' . $job->id . ' ' . $stage . ': ' . $payload['message']);
        }
    }
}
