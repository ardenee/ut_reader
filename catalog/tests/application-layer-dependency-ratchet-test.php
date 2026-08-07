<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Prevents new dependency-direction violations while legacy Application-layer coupling is removed incrementally.
 * Why: The codebase is a partial modular monolith; without a ratchet, new PDO/Infrastructure dependencies can silently
 *      spread through Application services faster than existing violations are refactored.
 * Role: Architecture regression test. Existing violations are an allow-list, not an endorsement; deleting an entry is safe.
 * Audit: Shrink the allow-list whenever a service is inverted. Never add a new entry instead of introducing an application port.
 */
declare(strict_types=1);

function dependency_ratchet_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$applicationRoot = realpath(__DIR__ . '/../src/Application');
dependency_ratchet_expect(is_string($applicationRoot), 'Application source directory could not be resolved.');

$legacyAllowed = array_fill_keys([
    'Catalog/CatalogGameFileListService.php',
    'Catalog/CatalogPackageTablePageService.php',
    'Dependency/CatalogAffectedDependencyRefreshService.php',
    'Dependency/CatalogDependencyReadSource.php',
    'Dependency/CatalogDependencyResolver.php',
    'Dependency/CatalogMissingDetailListService.php',
    'Dependency/CatalogMissingFileListService.php',
    'Dependency/CatalogPostImportDependencyQueue.php',
    'Federation/CatalogFederationConflictListService.php',
    'Federation/CatalogFederationHistoryPageService.php',
    'Federation/CatalogFederationInventoryListService.php',
    'Jobs/CatalogBackgroundJobPageService.php',
    'Maintenance/CatalogProjectionReconciliationQueue.php',
    'Search/CatalogCompactSearchService.php',
    'Search/CatalogSearchService.php',
    'Telemetry/CatalogExactCountBenchmark.php',
    'Telemetry/CatalogExactCountQueryCatalog.php',
], true);

$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($applicationRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
        continue;
    }
    $path = $entry->getPathname();
    $source = file_get_contents($path);
    dependency_ratchet_expect(is_string($source), 'Could not read Application source: ' . $path);

    $importsInfrastructure = str_contains($source, 'UnrealDb\\Catalog\\Infrastructure\\');
    $importsPdo = preg_match('/^use\\s+PDO\\s*;/m', $source) === 1;
    if (!$importsInfrastructure && !$importsPdo) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($path, strlen($applicationRoot) + 1));
    $violations[$relative] = [
        'infrastructure' => $importsInfrastructure,
        'pdo' => $importsPdo,
    ];
}

$newViolations = array_diff_key($violations, $legacyAllowed);
dependency_ratchet_expect(
    $newViolations === [],
    'New Application-layer dependency violation(s): ' . implode(', ', array_keys($newViolations))
);

$staleAllowed = array_diff_key($legacyAllowed, $violations);
dependency_ratchet_expect(
    $staleAllowed === [],
    'Resolved Application-layer violation(s) still listed in the ratchet: ' . implode(', ', array_keys($staleAllowed))
);

$domainRoot = realpath(__DIR__ . '/../src/Domain');
dependency_ratchet_expect(is_string($domainRoot), 'Domain source directory could not be resolved.');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainRoot, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
        continue;
    }
    $source = file_get_contents($entry->getPathname());
    dependency_ratchet_expect(is_string($source), 'Could not read Domain source: ' . $entry->getPathname());
    dependency_ratchet_expect(
        !str_contains($source, 'UnrealDb\\Catalog\\Application\\')
            && !str_contains($source, 'UnrealDb\\Catalog\\Infrastructure\\')
            && preg_match('/^use\\s+PDO\\s*;/m', $source) !== 1,
        'Domain layer depends outward: ' . str_replace('\\', '/', $entry->getPathname())
    );
}

echo 'Application dependency ratchet passed; remaining legacy violations: ' . count($violations) . ".\n";
