<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `JobHandler` for job handler.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

interface JobHandler
{
    public function supports(string $jobType): bool;

    /**
     * @return array<string, mixed>
     */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array;
}
