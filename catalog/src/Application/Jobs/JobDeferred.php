<?php
/**
 * Signals that a coordinator has no work to perform until its durable children advance.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

final class JobDeferred extends \RuntimeException
{
    /** @param array<string,mixed> $progress */
    public function __construct(
        public readonly int $delaySeconds,
        public readonly array $progress = []
    ) {
        parent::__construct('Background workflow deferred without failure.');
    }
}
