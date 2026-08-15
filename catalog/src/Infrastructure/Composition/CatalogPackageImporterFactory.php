<?php
/**
 * Composition root for the verified-package import adapter graph.
 *
 * Concrete PDO/parser/storage implementations are created here so the importer
 * itself depends only on Application ports.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Composition;

use PDO;
use UnrealDb\Catalog\Infrastructure\Import\CatalogFailedUploadPreserverAdapter;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageDependencyCoordinator;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageIdentityRepository;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackagePublisher;
use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;

final class CatalogPackageImporterFactory
{
    /** @param array<string,mixed> $config */
    public static function create(PDO $db, array $config): PdoCatalogPackageImporter
    {
        return new PdoCatalogPackageImporter(
            new CatalogVerifiedPackageInspector($db, $config),
            new CatalogVerifiedPackageIdentityRepository($db),
            new CatalogVerifiedPackagePublisher($db, $config),
            new CatalogVerifiedPackageDependencyCoordinator($db, $config),
            new CatalogFailedUploadPreserverAdapter($config)
        );
    }
}
