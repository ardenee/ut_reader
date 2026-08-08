<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Signals that Upload Bucket finalization cannot proceed while one of its worker queues is active.
 * Why: Queue orchestration belongs outside HTTP while the API must still preserve its existing 409 response/details contract.
 * Role: Infrastructure orchestration exception carrying only queue identifiers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogBucketProcessingActive extends \RuntimeException
{
    /** @param list<string> $activeQueues */
    public function __construct(public readonly array $activeQueues)
    {
        parent::__construct(
            'Upload Bucket processing is still active in ' . implode(', ', $activeQueues)
            . '. Wait for the current job to finish or stop that job, then retry file finalisation.'
        );
    }
}
