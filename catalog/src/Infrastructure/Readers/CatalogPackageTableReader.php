<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

/** Stable table data contract only; each engine owns its parser and source-version policies. */
interface CatalogPackageTableReader
{
    public function getHeader(): array;
    public function getNames(): array;
    public function getImports(): array;
    public function getExports(): array;
    public function validatePackage(): array;
}
