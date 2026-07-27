<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\Dependency\CatalogDependencyResolver;

function dependency_numeric_lookup_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(__DIR__ . '/../src/Application/Dependency/CatalogDependencyResolver.php');
dependency_numeric_lookup_expect(is_string($source), 'CatalogDependencyResolver.php could not be read.');

dependency_numeric_lookup_expect(
    str_contains($source, '$packageNames[$packageKey] = $rootPackage;'),
    'Package lookup collection no longer preserves the original string value separately from its normalized key.'
);
dependency_numeric_lookup_expect(
    str_contains($source, 'array_values($packageNames)'),
    'Package lookups returned to using PHP array keys as SQL values.'
);
dependency_numeric_lookup_expect(
    str_contains($source, '$stringValue = (string)$value;'),
    'Missing package lookup checks no longer normalize legacy integer keys safely.'
);

$method = new ReflectionMethod(CatalogDependencyResolver::class, 'normalizeLookup');
$result = $method->invoke(null, 12345);
dependency_numeric_lookup_expect(
    $result === '12345',
    'Numeric dependency lookup values are not preserved as strings.'
);

echo "Numeric dependency lookup contract tests passed.\n";
