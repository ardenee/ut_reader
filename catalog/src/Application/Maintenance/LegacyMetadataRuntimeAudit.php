<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Audits runtime references to retired/legacy metadata tables.
 * Why: Verified catalogue reads must use current compact metadata while explicitly identified unverified staging,
 *      conversion, schema and cleanup paths may still touch legacy tables during the staged retirement.
 * Role: Application maintenance audit enforcing the compact-only verified-runtime boundary.
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
     * Explicit exceptions only: schema compatibility, unverified/recovery staging,
     * historical conversion/repair and bounded deletion/cleanup code. Current
     * verified-file readers and rebuilders must never be placed on this list.
     *
     * The retired ue_search_documents table is never approved, including here.
     *
     * @var list<string>
     */
    private const ALLOWED_FILES = [
        'lib/Scanner/CatalogScannerImport.php',
        'lib/CatalogCompactDependencies.php',
        'lib/CatalogPerformance.php',
        'lib/CatalogRuntimeSqlCompatibility.php',
        'federation/docs.php',
        'src/Infrastructure/Games/PdoCatalogGameTableMaintenance.php',
        'src/Infrastructure/Maintenance/CatalogLegacyDataAuditService.php',
        'src/Infrastructure/Persistence/PdoCatalogPackageTableWriter.php',
        'src/Infrastructure/Persistence/PdoDependencySchemaManager.php',
        'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
        'src/Infrastructure/Metadata/CatalogCompactMetadataCompatibilityService.php',
        'src/Infrastructure/Metadata/CatalogCompactMetadataMutationService.php',
        'src/Infrastructure/Metadata/CompressedMetadataLegacySnapshot.php',
        'src/Infrastructure/Metadata/CompressedFileMetadataConverter.php',
        'src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php',
        'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
        'src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php',
        'src/Infrastructure/Unverified/CatalogUnverifiedMetadataRepairService.php',
        'src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php',
        'src/Infrastructure/Unverified/CatalogUnverifiedRenameService.php',
        'src/Infrastructure/Unverified/PdoUnverifiedFileDetailsQuery.php',
        'src/Infrastructure/Unverified/PdoUnverifiedGameMatchQuery.php',
        'lib/UnverifiedMetadataRepair.php',
        'bin/plan-legacy-table-space-reclaim.php',
        'bin/plan-mysql-space-release.php',
        'bin/reclaim-legacy-table-space.php',
        'bin/audit-legacy-runtime-references.php',
    ];

    /**
     * Historical dependency SQL shapes in these callers execute through catalog_one,
     * catalog_all or catalog_count and are rewritten centrally to the compact-only
     * dependency source. Mutations are not approved by this list.
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
        'game-page.php',
        'lib/CatalogSourceIdentity.php',
        'lib/FederationDependencyDownloads.php',
        'lib/UnverifiedObjectCheck.php',
        'library.php',
        'missing.php',
        'src/Application/Dashboard/CatalogDashboardStats.php',
        'src/Infrastructure/Federation/CatalogFederationDependencyNeedQuery.php',
        'src/Infrastructure/Telemetry/CatalogExactCountQueryCatalog.php',
        'src/Infrastructure/Unverified/CatalogUnverifiedQueueStorage.php',
    ];

    /**
     * Historical Names/Imports/Exports SQL shapes in these callers are routed
     * through CatalogCompactMetadataCompatibility. Verified files are required
     * to use format 2; only non-verified staging can reach its legacy fallback.
     *
     * @var list<string>
     */
    private const CENTRAL_METADATA_READ_FILES = [
        'duplicates-keep.php',
        'duplicates.php',
        'game-upks.php',
        'lib/CatalogAssetMetadata.php',
        'lib/CatalogSourceIdentity.php',
        'package-normalize.php',
        'upk-info.php',
        'src/Infrastructure/Maintenance/CatalogLegacyPackageNormalizationService.php',
        'src/Infrastructure/Metadata/CatalogAssetMetadataService.php',
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
                        'snippet' => function_exists('mb_substr')
                            ? mb_substr(trim($line), 0, 300)
                            : substr(trim($line), 0, 300),
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
        if (str_starts_with($relative, 'bin/verify-')) {
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
