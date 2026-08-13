<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `JobWorker` for job worker.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use UnrealDb\Catalog\Domain\Jobs\JobType;

/** Executes one leased job at a time. */
final class JobWorker
{
    /** @param array<string, JobHandler> $handlersByType Exact JobType => handler routes. */
    public function __construct(
        private readonly JobQueue $queue,
        private readonly array $handlersByType,
        private readonly string $queueName,
        private readonly string $workerId,
        private readonly int $leaseSeconds = 120,
        private readonly ?\Closure $eventAppender = null,
        private readonly ?\Closure $diagnosticEnabled = null,
        private readonly ?\Closure $failureReporter = null
    ) {
        self::assertCompleteHandlerMap($handlersByType);
    }

    /**
     * @return array{status:string,job_id?:int,type?:string,result?:array<string,mixed>,error?:string,error_file?:string,error_line?:int}
     */
    public function runOne(): array
    {
        $job = $this->queue->claim($this->queueName, $this->workerId, $this->leaseSeconds);
        if ($job === null) {
            return ['status' => 'idle'];
        }

        $this->diagnostic('claimed', $job, 'Worker claimed the job row.');
        $handler = $this->findHandler($job->type);
        if ($handler === null) {
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
                $this->diagnostic('deferred', $job, 'Coordinator released its worker while child work advances.');
                return ['status' => 'deferred', 'job_id' => $job->id, 'type' => $job->type];
            } catch (\Throwable $leaseError) {
                $this->reportTerminalFailure($job, $leaseError, 'defer_persist_failed');
                return $this->failureResult('lease_lost', $job, $leaseError);
            }
        } catch (JobCancellationRequested $exception) {
            $message = $this->errorText($exception);
            $this->diagnostic('cancelled', $job, $message);
            try {
                $this->queue->cancelClaimed($job, $message);
            } catch (\Throwable $leaseError) {
                return $this->failureResult('lease_lost', $job, $leaseError);
            }
            return $this->failureResult('cancelled', $job, $exception);
        } catch (\Throwable $exception) {
            $this->diagnostic(
                'exception',
                $job,
                get_class($exception) . ': ' . $this->errorText($exception) . ' at '
                    . str_replace('\\', '/', $exception->getFile()) . ':' . $exception->getLine()
            );
            $delay = min(300, max(1, 2 ** min(8, $job->attempt)));
            return $this->recordFailure($job, $exception, $delay);
        }

        try {
            $disposition = $this->queue->complete($job, $result);
            $this->diagnostic(
                $disposition === 'cancelled' ? 'completed_as_cancelled' : 'completed',
                $job,
                $disposition === 'cancelled'
                    ? 'Handler returned successfully, but a prior cancellation request won the terminal transition.'
                    : 'Handler returned and the job was completed.'
            );
            if ($disposition === 'cancelled') {
                return [
                    'status' => 'cancelled',
                    'job_id' => $job->id,
                    'type' => $job->type,
                    'result' => $result,
                ];
            }
            return ['status' => 'completed', 'job_id' => $job->id, 'type' => $job->type, 'result' => $result];
        } catch (\Throwable $completionError) {
            $this->diagnostic(
                'completion_persist_failed',
                $job,
                get_class($completionError) . ': handler already returned successfully; terminal queue state could not be persisted: '
                    . $this->errorText($completionError)
            );
            $this->reportTerminalFailure($job, $completionError, 'completion_persist_failed');
            return $this->failureResult('completion_persist_failed', $job, $completionError);
        }
    }

    /** @return array{status:string,job_id:int,type:string} */
    private function deferUnknownJobType(\UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job): array
    {
        $progress = $job->resumeProgress;
        $progress['queue_wait_reason'] = 'handler_unavailable';
        $progress['queue_wait_job_type'] = $job->type;
        $progress['message'] = 'This worker does not have a handler for ' . $job->type
            . '; leaving the job queued for a newer/restarted worker.';
        try {
            // A rolling deployment can leave an older worker alive briefly after
            // newer code has started enqueueing a new job type. That is not job
            // failure and must not consume an attempt or dead-letter the work.
            $this->queue->defer($job, 30, $progress);
            $this->diagnostic(
                'handler_unavailable_deferred',
                $job,
                'No handler is loaded for ' . $job->type . '; job returned to the queue without consuming an attempt.'
            );
            return ['status' => 'deferred', 'job_id' => $job->id, 'type' => $job->type];
        } catch (\Throwable $leaseError) {
            $this->reportTerminalFailure($job, $leaseError, 'unknown_handler_defer_failed');
            return [
                'status' => 'lease_lost',
                'job_id' => $job->id,
                'type' => $job->type,
            ];
        }
    }

    /** @return array{status:string,job_id:int,type:string,error:string,error_file:string,error_line:int} */
    private function recordFailure(\UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job, \Throwable $exception, int $delay): array
    {
        try {
            $disposition = $this->queue->fail($job, $exception, $delay);
        } catch (\Throwable $leaseError) {
            $this->reportTerminalFailure($job, $leaseError, 'lease_lost');
            return $this->failureResult('lease_lost', $job, $leaseError);
        }
        if ($disposition === 'dead_letter') {
            $this->reportTerminalFailure($job, $exception, 'dead_letter');
        }
        return $this->failureResult($disposition, $job, $exception);
    }

    /** @return array{status:string,job_id:int,type:string,error:string,error_file:string,error_line:int} */
    private function failureResult(
        string $status,
        \UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job,
        \Throwable $exception
    ): array {
        return [
            'status' => $status,
            'job_id' => $job->id,
            'type' => $job->type,
            'error' => $this->errorText($exception),
            'error_file' => str_replace('\\', '/', $exception->getFile()),
            'error_line' => $exception->getLine(),
        ];
    }

    private function reportTerminalFailure(
        \UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job,
        \Throwable $exception,
        string $disposition
    ): void {
        if (!$this->failureReporter instanceof \Closure) {
            return;
        }
        try {
            ($this->failureReporter)($job, $exception, $disposition);
        } catch (\Throwable $reportError) {
            error_log('[UnrealDB worker] terminal failure reporter failed: ' . $reportError->getMessage());
        }
    }

    private function errorText(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '' || $message === "''" || $message === '""') {
            return get_class($exception) . ' was thrown without an error message.';
        }
        return $message;
    }

    private function findHandler(string $type): ?JobHandler
    {
        return $this->handlersByType[$type] ?? null;
    }

    /** @param array<string, JobHandler> $handlersByType */
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
            if (!$handler instanceof JobHandler) {
                throw new \LogicException('Invalid job handler registered for type: ' . $type);
            }
            if (!$handler->supports($type)) {
                throw new \LogicException(get_class($handler) . ' does not support routed job type: ' . $type);
            }
        }
    }

    private function diagnostic(
        string $stage,
        \UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job,
        string $message
    ): void {
        if ($this->diagnosticEnabled instanceof \Closure) {
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
            'job_type' => $job->type,
            'stage' => $stage,
            'message' => trim($message) !== '' ? $message : 'Worker diagnostic message was empty.',
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
