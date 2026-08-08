<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Carries structured stale-worker restart failure details across the worker-pool orchestration boundary.
 * Why: HTTP controllers need to preserve the existing 409 response without owning process-control policy.
 * Role: Infrastructure exception used by CatalogWorkerPoolReconciler and translated by the job-run API.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogWorkerPoolStaleRestartFailed extends \RuntimeException
{
    /**
     * @param array<string,mixed> $worker
     * @param array<string,mixed> $restart
     */
    public function __construct(
        public readonly array $worker,
        public readonly array $restart
    ) {
        parent::__construct(
            'The detached worker pool is running old code and could not be restarted automatically. '
            . 'Use Stop workers, then Start queued.'
        );
    }
}
