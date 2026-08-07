<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application interface `UnverifiedQueueInventory` for unverified queue inventory.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

/** Supplies physical files from every unverified queue. */
interface UnverifiedQueueInventory
{
    /**
     * @return list<array{
     *   queue_game_id:int,
     *   queue_name:string,
     *   queue_name_label:string,
     *   queue_key:string,
     *   original_name:string,
     *   path:string,
     *   reason_path:string,
     *   size:int,
     *   modified_at:int
     * }>
     */
    public function all(): array;
}
