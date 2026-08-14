<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified;

interface CatalogUnverifiedQueueMutation
{
    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function move(array $source, int $targetGameId): array;

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function discard(array $source): array;
}
