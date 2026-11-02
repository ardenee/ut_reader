<?php
/** Engine-specific package reader entry point. Shared bounded byte primitives remain in CatalogLegacyPackageReader.php. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
require_once __DIR__ . '/CatalogLegacyPackageReader.php';

final class CatalogUE2PackageReader extends CatalogLegacyPackageReaderBase{
    public function __construct(string $path)
    {
        parent::__construct($path, 'UE2');
    }
}
