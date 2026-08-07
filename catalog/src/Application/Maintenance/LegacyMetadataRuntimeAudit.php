<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `LegacyMetadataRuntimeAudit` for legacy metadata runtime audit.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class LegacyMetadataRuntimeAudit
{
    /** @var list<string> */
    private const TABLES = [
        'ue_names',
        'ue_imports',
        'ue_exports',
        'ue_dependencies',
        'ue_search_documents',
    ];

    /**
     * Files that intentionally provide schema, unverified-file inventory,
     * transient import staging, or compact-first fallback. Verified catalogue
     * reads must otherwise use compact compatibility sources.
     *
     * The retired ue_search_documents table is never approved, including in
     * these files.
     *
     * @var list<string>
     */
    private const ALLOWED_FILES = [
        'lib/CatalogScanner.php',
        'lib/CatalogCompactDependencies.php',
        'lib/CatalogCompactMetadataCompatibility.php',
        'lib/CatalogCompactMetadataMutation.php',
        'lib/CatalogLegacyDataAudit.php',
        'lib/CatalogPerformance.php',
        'lib/CatalogRuntimeSqlCompatibility.php',
        'lib/CatalogUnverifiedGameMatches.php',
        'lib/CatalogUnverifiedIndex.php',
        'lib/GameManagerLifecycle.php',
        'unverified-file-details.php',
        'unverified-files-action.php',
        'federation/docs.php',
        'src/Application/Catalog/CatalogPackageTablePageService.php',
        'src/Application/Dependency/CatalogDependencyReadSource.php',
        'src/Application/Dependency/CatalogDependencyResolver.php',
        'src/Infrastructure/Import/CatalogBucketUploadProcessor.php',
        'src/Infrastructure/Metadata/CompressedMetadataLegacySnapshot.php',
        'src/Infrastructure/Metadata/CompressedFileMetadataConverter.php',
        'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
        'src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php',
        'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
        'src/Infrastructure/Persistence/PdoDependencySchemaManager.php',
        'lib/UnverifiedMetadataRepair.php',
        'bin/plan-legacy-table-space-reclaim.php',
        'bin/plan-mysql-space-release.php',
        'bin/reclaim-legacy-table-space.php',
        'bin/audit-legacy-runtime-references.php',
    ];

    /**
     * Read-only dependency SQL in these files is executed through catalog_one,
     * catalog_all or catalog_count and is rewritten centrally to the compact
     * mixed dependency source. Mutations are not approved by this list.
     *
     * @var list<string>
     */
    private const CENTRAL_DEPENDENCY_READ_FILES = [
        'download-info.php',
        'duplicates-keep.php',
        'duplicates.php',
        'federation/missing-files.php',
        'federation/peer-inventory.php',
        'federation/request-generate.php',
        'file-examine-core.php',
        'game-page.php',
        'lib/CatalogSourceIdentity.php',
        'lib/FederationDependencyDownloads.php',
        'lib/FederationWorker.php',
        'lib/ModPackageBuilder.php',
        'lib/UnverifiedFileManager.php',
        'lib/UnverifiedObjectCheck.php',
        'library.php',
        'missing.php',
        'src/Application/Dashboard/CatalogDashboardStats.php',
        'src/Application/Telemetry/CatalogExactCountQueryCatalog.php',
    ];

    /**
     * Read-only Names/Imports/Exports SQL in these files is routed through
     * CatalogCompactMetadataCompatibility by shared query helpers.
     *
     * @var list<string>
     */
    private const CENTRAL_METADATA_READ_FILES = [
        'duplicates-keep.php',
        'duplicates.php',
        'file-examine-core.php',
        'game-upks.php',
        'lib/CatalogAssetMetadata.php',
        'lib/CatalogSourceIdentity.php',
        'package-normalize.php',
        'upk-info.php',
    ];

    /** @return array{files:int,references:int,matches:list<array<string,mixed>>} */
    public static function scan(string $catalogRoot): array
    {
        $catalogRoot = realpath($catalogRoot) ?: '';
        if ($catalogRoot === '' || !is_dir($catalogRoot)) {
            throw new RuntimeException('Catalog source root is unavailable for legacy metadata audit.');
        }

        $matches = [];
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($catalogRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            if (strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen($catalogRoot) + 1));
            if (self::excluded($relative)) {
                continue;
            }

            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $index => $line) {
                $trimmed = trim((string)$line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }
                foreach (self::TABLES as $table) {
                    if (!preg_match('/\b' . preg_quote($table, '/') . '\b/i', $line)) {
                        continue;
                    }
                    $operation = self::operation($line);
                    if (self::approvedMatch($relative, $table, $operation)) {
                        continue;
                    }
                    $files[$relative] = true;
                    $matches[] = [
                        'file' => $relative,
                        'line' => $index + 1,
                        'table' => $table,
                        'operation' => $operation,
                        'snippet' => mb_substr(trim($line), 0, 300),
                    ];
                }
            }
        }

        usort($matches, static function (array $left, array $right): int {
            return strcmp((string)$left['file'], (string)$right['file'])
                ?: ((int)$left['line'] <=> (int)$right['line'])
                ?: strcmp((string)$left['table'], (string)$right['table']);
        });

        return [
            'files' => count($files),
            'references' => count($matches),
            'matches' => $matches,
        ];
    }

    private static function excluded(string $relative): bool
    {
        if ($relative === 'src/Application/Maintenance/LegacyMetadataRuntimeAudit.php') {
            return true;
        }
        foreach (['migrations/', 'tests/', 'storage/', 'vendor/'] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function approvedMatch(string $relative, string $table, string $operation): bool
    {
        if ($table === 'ue_search_documents') {
            return false;
        }
        if (in_array($relative, self::ALLOWED_FILES, true)) {
            return true;
        }
        if ($operation !== 'read') {
            return false;
        }
        if ($table === 'ue_dependencies') {
            return in_array($relative, self::CENTRAL_DEPENDENCY_READ_FILES, true);
        }
        if (in_array($table, ['ue_names', 'ue_imports', 'ue_exports'], true)) {
            return in_array($relative, self::CENTRAL_METADATA_READ_FILES, true);
        }
        return false;
    }

    private static function operation(string $line): string
    {
        $line = strtoupper($line);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'ALTER', 'DROP'] as $operation) {
            if (str_contains($line, $operation)) {
                return strtolower($operation);
            }
        }
        return 'read';
    }
}
