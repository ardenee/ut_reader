<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for generated package artifact writers.
 * Why: Existing worker/tests retain stable modpkg_* entry points while active ZIP/UMOD/PAK I/O lives under src/.
 * Role: Transitional generated-package writer dispatcher with no archive-format implementation of its own.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedPackageDescriptor;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedUmodWriter;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportFormatPolicy;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPayloadZipWriter;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogUt4PakWriter;

function modpkg_generated_version(mixed $value): string
{
    return CatalogGeneratedPackageDescriptor::generatedVersion($value);
}

/** @return array<string,string> */
function modpkg_generated_umod_manifest(array $plan, array $options): array
{
    return (new CatalogGeneratedUmodWriter())->manifest($plan, $options);
}

function modpkg_write_generated_umod(string $outputPath, array $plan, array $options): array
{
    return (new CatalogGeneratedUmodWriter())->write($outputPath, $plan, $options);
}

function modpkg_write_payload_zip(string $outputPath, array $plan): array
{
    return (new CatalogPayloadZipWriter())->write($outputPath, $plan);
}

function modpkg_validate_payload_zip(string $path, array $plan): array
{
    return (new CatalogPayloadZipWriter())->validate($path, $plan);
}

function modpkg_build_generated_package(
    string $outputPath,
    array $plan,
    array $options,
    array $settings
): array {
    return match ((string)$plan['format']) {
        CatalogPackageExportFormatPolicy::DEPENDENCY_ZIP,
        CatalogPackageExportFormatPolicy::UT3_ZIP => (new CatalogPayloadZipWriter())->write(
            $outputPath,
            $plan
        ),
        CatalogPackageExportFormatPolicy::UMOD,
        CatalogPackageExportFormatPolicy::UT2MOD,
        CatalogPackageExportFormatPolicy::UT4MOD => (new CatalogGeneratedUmodWriter())->write(
            $outputPath,
            $plan,
            $options
        ),
        CatalogPackageExportFormatPolicy::UT4_PAK => (new CatalogUt4PakWriter())->write(
            $outputPath,
            $plan,
            $options,
            $settings
        ),
        default => throw new RuntimeException('Unsupported package format.'),
    };
}
