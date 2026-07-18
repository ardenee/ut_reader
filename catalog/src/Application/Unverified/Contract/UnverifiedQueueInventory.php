<?php
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
