<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

interface JobHandler
{
    public function supports(string $jobType): bool;

    /**
     * @param callable():bool $heartbeat
     * @return array<string, mixed>
     */
    public function handle(ClaimedJob $job, callable $heartbeat): array;
}
