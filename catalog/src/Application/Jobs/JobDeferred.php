<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

final class JobDeferred extends \RuntimeException
{
    public readonly bool $retainWorkerAffinity;

    /** @param array<string,mixed> $progress */
    public function __construct(
        public readonly int $delaySeconds,
        public readonly array $progress = [],
        ?bool $retainWorkerAffinity = null
    ) {
        $children = is_array($progress['children'] ?? null) ? $progress['children'] : [];
        $blocked = (int)($children['failed'] ?? 0) > 0
            || (int)($children['dead_letter'] ?? 0) > 0
            || (int)($children['cancelled'] ?? 0) > 0;
        $this->retainWorkerAffinity = $retainWorkerAffinity ?? !$blocked;
        parent::__construct('Background workflow deferred without failure.');
    }
}
