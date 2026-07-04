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
            $this->queue->fail($job, $exception, 0);
            return ['status' => 'failed', 'job_id' => $job->id, 'type' => $job->type, 'error' => $exception->getMessage()];
        }

        try {
            $context = new JobExecutionContext($this->queue, $job, $this->leaseSeconds);
            $context->heartbeat();
            $result = $handler->handle($job, $context);
            $this->queue->complete($job, $result);
            return ['status' => 'completed', 'job_id' => $job->id, 'type' => $job->type, 'result' => $result];
        } catch (\Throwable $exception) {
            $delay = min(300, max(1, 2 ** min(8, $job->attempt)));
            $this->queue->fail($job, $exception, $delay);
            return ['status' => 'failed', 'job_id' => $job->id, 'type' => $job->type, 'error' => $exception->getMessage()];
        }
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
