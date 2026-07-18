<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified\Contract;

/** Database operations required by unverified duplicate cleanup. */
interface UnverifiedRecordStore
{
    /** @return array<string, true> */
    public function indexedQueueKeys(): array;

    public function deleteByQueue(int $queueGameId, string $queueName): void;
}
