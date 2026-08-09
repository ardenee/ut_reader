<?php
/**
 * Purpose: Encodes dependency status/source/confidence values used by compact metadata containers and lookup projections.
 * Why: Current metadata encoding must not depend on the retired SQL snapshot/conversion implementation.
 * Role: Small format-level value mapper shared by compact container and projection writers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

final class CompactDependencyEncoding
{
    /** @return array{0:int,1:int,2:int} */
    public static function codes(string $status): array
    {
        return match (strtolower(trim($status))) {
            'resolved' => [1, 1, 100],
            'package_only' => [2, 2, 75],
            'common' => [3, 3, 100],
            default => [0, 0, 0],
        };
    }
}
