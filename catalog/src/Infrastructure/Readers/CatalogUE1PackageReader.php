<?php
/** Engine-specific package reader entry point. Shared bounded byte primitives remain in CatalogLegacyPackageReader.php. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
require_once __DIR__ . '/CatalogLegacyPackageReader.php';

final class CatalogUE1PackageReader extends CatalogLegacyPackageReaderBase
{
    public function __construct(string $path, bool $unrealV227SummarySemantics = false)
    {
        parent::__construct($path, 'UE1', $unrealV227SummarySemantics);
    }
}
