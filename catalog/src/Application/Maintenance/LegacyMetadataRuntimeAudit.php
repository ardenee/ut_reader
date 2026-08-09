<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Audits runtime references to retired/legacy metadata tables.
 * Why: Verified catalogue reads use current compact metadata; only explicitly bounded migration, staging and cleanup paths may touch retired storage.
 * Role: Application maintenance audit enforcing the compact-only runtime boundary.
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
        'federation/docs.php',
        'src/Application/Maintenance/RetiredDuplicateLegacyMetadataPurger.php',
        'src/Infrastructure/Games/PdoCatalogGameTableMaintenance.php',
        'src/Infrastructure/Maintenance/CatalogLegacyDataAuditService.php',
        'src/Infrastructure/Persistence/PdoCatalogPackageTableWriter.php',
        'src/Infrastructure/Persistence/PdoDependencySchemaManager.php',
        'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
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
        'bin/reclaim-legacy-table-space.php',
        'bin/purge-retired-duplicate-legacy-metadata.php',
        'bin/audit-legacy-runtime-references.php',
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
                    if (self::approvedMatch($relative, $table)) {
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

    private static function approvedMatch(string $relative, string $table): bool
    {
        if ($table === 'ue_search_documents') {
            return false;
        }
        return in_array($relative, self::ALLOWED_FILES, true);
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
