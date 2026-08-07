<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `JobCancellationRequested` for job cancellation requested.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

final class JobCancellationRequested extends \RuntimeException
{
}
