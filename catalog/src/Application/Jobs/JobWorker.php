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
        private readonly int $leaseSeconds = 120
    ) {
    }

    /**
     * @return array{status:string,job_id?:int,type?:string,result?:array<string,mixed>,error?:string}
     */
    public function runOne(): array
    {
        $job = $this->queue->claim($this->queueName, $this->workerId, $this->leaseSeconds);
        if ($job === null) {
            return ['status' => 'idle'];
        }

        $handler = $this->findHandler($job->type);
        if ($handler === null) {
            $exception = new \RuntimeException('No job handler registered for type: ' . $job->type);
            return $this->recordFailure($job, $exception, 0);
        }

        try {
            $context = new JobExecutionContext($this->queue, $job, $this->leaseSeconds);
            $context->heartbeat(['stage' => 'started', 'attempt' => $job->attempt]);
            $result = $handler->handle($job, $context);

            /*
             * Cancellation is cooperative and is observed at handler checkpoints.
             * Once a handler returns successfully, its side effects may already be
             * committed or its artifact atomically published. A cancellation that
             * arrives after that boundary is too late and must not relabel completed
             * work as cancelled.
             */
            $this->queue->complete($job, $result);
            return ['status' => 'completed', 'job_id' => $job->id, 'type' => $job->type, 'result' => $result];
        } catch (JobCancellationRequested $exception) {
            try {
                $this->queue->cancelClaimed($job, $exception->getMessage());
            } catch (\Throwable $leaseError) {
                return [
                    'status' => 'lease_lost',
                    'job_id' => $job->id,
                    'type' => $job->type,
                    'error' => $leaseError->getMessage(),
                ];
            }
            return ['status' => 'cancelled', 'job_id' => $job->id, 'type' => $job->type, 'error' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            $delay = min(300, max(1, 2 ** min(8, $job->attempt)));
            return $this->recordFailure($job, $exception, $delay);
        }
    }

    /**
     * @return array{status:string,job_id:int,type:string,error:string}
     */
    private function recordFailure(\UnrealDb\Catalog\Domain\Jobs\ClaimedJob $job, \Throwable $exception, int $delay): array
    {
        try {
            $disposition = $this->queue->fail($job, $exception, $delay);
        } catch (\Throwable $leaseError) {
            return [
                'status' => 'lease_lost',
                'job_id' => $job->id,
                'type' => $job->type,
                'error' => $leaseError->getMessage(),
            ];
        }

        return [
            'status' => $disposition,
            'job_id' => $job->id,
            'type' => $job->type,
            'error' => $exception->getMessage(),
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
}
