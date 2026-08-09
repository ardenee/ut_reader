<?php
/**
 * Purpose: Retains the historical compact dependency-code API after SQL snapshot conversion retirement.
 * Why: Current format writers still share the status/source/confidence encoding contract, but no executable path may read retired metadata tables.
 * Role: Compatibility shim; historical SQL snapshot capture is intentionally unavailable.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class CompressedMetadataLegacySnapshot
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function capture(int $fileId): array
    {
        throw new RuntimeException(
            'Historical SQL metadata snapshot conversion has been retired. '
            . 'Only authoritative format-2 metadata is supported.'
        );
    }

    /** @return array{0:int,1:int,2:int} */
    public static function dependencyCodes(string $status): array
    {
        return CompactDependencyEncoding::codes($status);
    }
}
