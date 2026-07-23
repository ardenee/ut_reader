<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

/**
 * Executes one leased job at a time. The worker deliberately has no HTTP
 * concerns and can run under CLI, supervisor, cron, or a container process.
 */
final class JobWorker
{
    /**
     * @param list<JobHandler> $handlers
     */
    public function __construct(
        private readonly JobQueue $queue,
        private readonly array $handlers,
        private readonly string $queueName,
        private readonly string $workerId,
        private readonly int $leaseSeconds = 120,
        private readonly ?\Closure $eventAppender = null
    ) {
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
            $exception = new \RuntimeException('No job handler registered for type: ' . $job->type);
            return $this->recordFailure($job, $exception, 0);
        }

        try {
            $context = new JobExecutionContext($this->queue, $job, $this->leaseSeconds, $this->eventAppender);
            $this->diagnostic('heartbeat_start', $job, 'Writing the initial worker heartbeat.');
            $context->heartbeat([
                'stage' => 'worker_start',
                'done' => 1,
                'total' => 100,
                'percent' => 1,
                'attempt' => $job->attempt,
                'message' => 'Worker claimed job #' . $job->id . '; starting ' . $job->type . '.',
            ], true);
            $this->diagnostic('handler_start', $job, 'Initial heartbeat stored; entering the job handler.');
            $result = $handler->handle($job, $context);

            $this->queue->complete($job, $result);
            $this->diagnostic('completed', $job, 'Handler returned and the job was completed.');
            return ['status' => 'completed', 'job_id' => $job->id, 'type' => $job->type, 'result' => $result];
        } catch (JobCancellationRequested $exception) {
            $this->diagnostic('cancelled', $job, $exception->getMessage());
            try {
                $this->queue->cancelClaimed($job, $exception->getMessage());
            } catch (\Throwable $leaseError) {
                return $this->failureResult('lease_lost', $job, $leaseError);
            }
            return $this->failureResult('cancelled', $job, $exception);
        } catch (\Throwable $exception) {
            $this->diagnostic(
                'exception',
                $job,
                get_class($exception) . ': ' . $exception->getMessage() . ' at '
                    . str_replace('\\', '/', $exception->getFile()) . ':' . $exception->getLine()
            );
            $delay = min(300, max(1, 2 ** min(8, $job->attempt)));
            return $this->recordFailure($job, $exception, $delay);
        }
    }

    /**
     * @return array{status:string,job_id:int,type:string,error:string,error_file:string,error_line:int}
     */
    private function recordFailure(\UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job, \Throwable $exception, int $delay): array
    {
        try {
            $disposition = $this->queue->fail($job, $exception, $delay);
        } catch (\Throwable $leaseError) {
            return $this->failureResult('lease_lost', $job, $leaseError);
        }

        return $this->failureResult($disposition, $job, $exception);
    }

    /**
     * @return array{status:string,job_id:int,type:string,error:string,error_file:string,error_line:int}
     */
    private function failureResult(
        string $status,
        \UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job,
        \Throwable $exception
    ): array {
        return [
            'status' => $status,
            'job_id' => $job->id,
            'type' => $job->type,
            'error' => $exception->getMessage(),
            'error_file' => str_replace('\\', '/', $exception->getFile()),
            'error_line' => $exception->getLine(),
        ];
    }

    private function findHandler(string $type): ?JobHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                return $handler;
            }
        }

        return null;
    }

    private function diagnostic(
        string $stage,
        \UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job,
        string $message
    ): void {
        $payload = [
            'time' => gmdate(DATE_ATOM),
            'worker_id' => $this->workerId,
            'queue' => $this->queueName,
            'job_id' => $job->id,
            'job_type' => $job->type,
            'stage' => $stage,
            'message' => $message,
        ];
        try {
            error_log('[UnrealDB worker] ' . json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } catch (\Throwable) {
            error_log('[UnrealDB worker] job #' . $job->id . ' ' . $stage . ': ' . $message);
        }
    }
}
