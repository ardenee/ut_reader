<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the explicit Upload Bucket package-operations boundary and isolates its temporary reflection compatibility shim.
 * Why: Identity-aware processing and metadata repair must not depend directly on private CatalogBucketUploadProcessor methods.
 * Role: Architecture regression test for the Infrastructure package-operations boundary.
 * Audit: The legacy adapter is the only allowed ReflectionMethod bridge; remove that allowance when hash/store/index are extracted.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketPackageOperations;
use UnrealDb\Catalog\Infrastructure\Import\LegacyCatalogBucketPackageOperations;

function bucket_operations_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

bucket_operations_expect(interface_exists(CatalogBucketPackageOperations::class), 'Upload Bucket operations contract is not autoloadable.');
bucket_operations_expect(is_subclass_of(LegacyCatalogBucketPackageOperations::class, CatalogBucketPackageOperations::class), 'Legacy adapter does not implement the operations contract.');

$importDir = realpath(__DIR__ . '/../src/Infrastructure/Import');
bucket_operations_expect(is_string($importDir), 'Import source directory could not be resolved.');
$reflectionFiles = [];
foreach (glob($importDir . '/*.php') ?: [] as $path) {
    $source = file_get_contents($path);
    if (is_string($source) && (str_contains($source, 'use ReflectionMethod;') || str_contains($source, 'new ReflectionMethod('))) {
        $reflectionFiles[] = basename($path);
    }
}
sort($reflectionFiles);
bucket_operations_expect(
    $reflectionFiles === ['LegacyCatalogBucketPackageOperations.php'],
    'Upload Bucket private-method reflection escaped its compatibility adapter: ' . implode(', ', $reflectionFiles)
);

echo "Upload Bucket package operations contract tests passed.\n";
