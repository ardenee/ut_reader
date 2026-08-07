<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `UnverifiedRecordStore` for unverified record store.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

/** Database operations required by unverified duplicate cleanup. */
interface UnverifiedRecordStore
{
    /** @return array<string, true> */
    public function indexedQueueKeys(): array;

    public function deleteByQueue(int $queueGameId, string $queueName): void;
}
