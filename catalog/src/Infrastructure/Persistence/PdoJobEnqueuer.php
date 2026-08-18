<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists new durable background jobs and active deduplication keys.
 * Why: Enqueue policy is independent from claiming, leasing, recovery and worker lifecycle concerns.
 * Role: Infrastructure persistence collaborator used by PdoJobQueue.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;

final class PdoJobEnqueuer
{
    private const WORKFLOW_INSERT_BATCH_SIZE = 100;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(
        string $queue,
        string $type,
        array $payload,
        int $priority = 100,
        ?DateTimeImmutable $availableAt = null,
        ?string $dedupeKey = null,
        ?int $createdBy = null,
        int $maxAttempts = 3,
        ?int $parentJobId = null,
        ?string $workflowUnitKey = null
    ): int {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $type = PdoJobQueueSupport::requiredIdentifier($type, 'type');
        $dedupeKey = $dedupeKey === null
            ? null
            : PdoJobQueueSupport::optionalIdentifier($dedupeKey, 'dedupe key');
        $parentJobId = $parentJobId !== null && $parentJobId > 0 ? $parentJobId : null;
        $workflowUnitKey = $workflowUnitKey === null
            ? null
            : PdoJobQueueSupport::optionalIdentifier($workflowUnitKey, 'workflow unit key');
        if ($workflowUnitKey !== null && $parentJobId === null) {
            throw new \InvalidArgumentException('A workflow unit key requires a parent job id.');
        }
        $priority = max(0, min($priority, 1000));
        $maxAttempts = max(1, min($maxAttempts, 20));
        $payloadJson = PdoJobQueueSupport::encodeJson($payload);
        $availableAt = ($availableAt ?? PdoJobQueueSupport::now())->setTimezone(PdoJobQueueSupport::utc());
        $resource = JobResourcePolicy::for($type, $payload);

        $columns = '(parent_job_id,workflow_unit_key,queue_name,job_type,resource_class,resource_limit,'
            . 'concurrency_key,payload_json,priority,status,available_at,max_attempts,dedupe_key,created_by)';
        $values = [
            $parentJobId,
            $workflowUnitKey,
            $queue,
            $type,
            $resource->resourceClass,
            $resource->limit,
            $resource->concurrencyKey,
            $payloadJson,
            $priority,
            $availableAt->format('Y-m-d H:i:s'),
            $maxAttempts,
            $dedupeKey,
            $createdBy,
        ];

        if ($dedupeKey === null && $workflowUnitKey === null) {
            $statement = $this->db->prepare(
                'INSERT INTO ue_background_jobs ' . $columns
                . ' VALUES (?,?,?,?,?,?,?,?,?,"queued",?,?,?,?)'
            );
            $statement->execute($values);
            return (int)$this->db->lastInsertId();
        }

        // Both active-dedupe identity and parent/unit identity are idempotent.
        // If either unique key already exists, return that exact durable row.
        $statement = $this->db->prepare(
            'INSERT INTO ue_background_jobs ' . $columns
            . ' VALUES (?,?,?,?,?,?,?,?,?,"queued",?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),updated_at=updated_at'
        );
        $statement->execute($values);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Persist many children of one durable workflow without paying one network /
     * statement / commit round-trip for every unit. Parent + workflow_unit_key
     * remains the idempotency boundary, so replaying a partially planned page is
     * safe and does not create duplicate child jobs.
     *
     * @param list<array{payload:array<string,mixed>,workflow_unit_key:string}> $units
     */
    public function enqueueWorkflowUnits(
        string $queue,
        string $type,
        array $units,
        int $priority,
        ?DateTimeImmutable $availableAt,
        ?int $createdBy,
        int $maxAttempts,
        int $parentJobId
    ): int {
        if ($units === []) {
            return 0;
        }
        if ($parentJobId < 1) {
            throw new \InvalidArgumentException('Workflow unit batching requires a positive parent job id.');
        }

        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $type = PdoJobQueueSupport::requiredIdentifier($type, 'type');
        $priority = max(0, min($priority, 1000));
        $maxAttempts = max(1, min($maxAttempts, 20));
        $availableAt = ($availableAt ?? PdoJobQueueSupport::now())->setTimezone(PdoJobQueueSupport::utc());
        $availableAtSql = $availableAt->format('Y-m-d H:i:s');
        $createdBy = $createdBy !== null && $createdBy > 0 ? $createdBy : null;

        $columns = '(parent_job_id,workflow_unit_key,queue_name,job_type,resource_class,resource_limit,'
            . 'concurrency_key,payload_json,priority,status,available_at,max_attempts,dedupe_key,created_by)';
        $processed = 0;

        foreach (array_chunk(array_values($units), self::WORKFLOW_INSERT_BATCH_SIZE) as $chunk) {
            $tuples = [];
            $values = [];
            foreach ($chunk as $unit) {
                if (!is_array($unit)) {
                    throw new \InvalidArgumentException('Workflow unit must be an array.');
                }
                $payload = $unit['payload'] ?? null;
                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('Workflow unit payload must be an array.');
                }
                $workflowUnitKey = PdoJobQueueSupport::optionalIdentifier(
                    (string)($unit['workflow_unit_key'] ?? ''),
                    'workflow unit key'
                );
                if ($workflowUnitKey === null) {
                    throw new \InvalidArgumentException('Workflow unit key is required.');
                }

                $resource = JobResourcePolicy::for($type, $payload);
                $tuples[] = '(?,?,?,?,?,?,?,?,?,"queued",?,?,NULL,?)';
                array_push(
                    $values,
                    $parentJobId,
                    $workflowUnitKey,
                    $queue,
                    $type,
                    $resource->resourceClass,
                    $resource->limit,
                    $resource->concurrencyKey,
                    PdoJobQueueSupport::encodeJson($payload),
                    $priority,
                    $availableAtSql,
                    $maxAttempts,
                    $createdBy
                );
            }

            $statement = $this->db->prepare(
                'INSERT INTO ue_background_jobs ' . $columns
                . ' VALUES ' . implode(',', $tuples)
                . ' ON DUPLICATE KEY UPDATE id=id,updated_at=updated_at'
            );
            $statement->execute($values);
            $processed += count($chunk);
        }

        return $processed;
    }
}
