<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies legacy reader memory behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function legacy_memory_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$resolver = file_get_contents($root . '/src/Infrastructure/Readers/CatalogReaderResolver.php');
$reader = file_get_contents($root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php');
$factory = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');

legacy_memory_expect(is_string($resolver), 'Catalog reader resolver is missing.');
legacy_memory_expect(is_string($reader), 'Streaming legacy reader is missing.');
legacy_memory_expect(is_string($factory), 'Worker factory is missing.');

legacy_memory_expect(
    !str_contains($resolver, 'eval(')
        && !str_contains($resolver, 'file_get_contents($readerPath)')
        && str_contains($resolver, 'CatalogLegacyPackageReader.php')
        && str_contains($resolver, 'CatalogUE1PackageReader')
        && str_contains($resolver, 'CatalogUE2PackageReader'),
    'UE1/UE2 catalog reader resolution still evaluates rewritten source at runtime.'
);
legacy_memory_expect(
    str_contains($reader, "fopen(\$path, 'rb')")
        && str_contains($reader, 'fseek($this->handle')
        && !str_contains($reader, 'file_get_contents($path)')
        && !str_contains($reader, 'private string $bytes'),
    'Legacy catalog inventory still retains the complete package in a PHP string.'
);
legacy_memory_expect(
    str_contains($reader, "seek((int)\$this->header['nameOffset'])")
        && str_contains($reader, "seek((int)\$this->header['importOffset'])")
        && str_contains($reader, "seek((int)\$this->header['exportOffset'])"),
    'Legacy Names/Imports/Exports are not read by bounded random-access seeks.'
);
legacy_memory_expect(
    str_contains($factory, "worker_memory_limit'] ?? '512M'")
        && str_contains($factory, 'currentBytes >= $targetBytes')
        && str_contains($factory, "ini_set('memory_limit', \$target)"),
    'Detached worker memory safety limit is missing or can lower an existing limit.'
);

echo "Memory-bounded legacy reader contract tests passed.\n";
