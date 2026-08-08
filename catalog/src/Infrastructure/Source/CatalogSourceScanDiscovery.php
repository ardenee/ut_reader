<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Discovers package-like files for a local source scan while excluding PAK containers handled by separate jobs.
 * Why: Recursive traversal and discovery progress are filesystem concerns and should not live inside package identity/import orchestration.
 * Role: Infrastructure source-scan discovery collaborator preserving the existing traversal, filter and progress behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CatalogSourceScanDiscovery
{
    public function __construct()
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSourceScan.php';
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $config
     * @param array<string,int> $counters
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{files:list<array{0:string,1:string}>,containers_skipped:int}
     */
    public function discover(
        string $basePath,
        array $profile,
        array $config,
        array $counters,
        ?callable $progress = null
    ): array {
        \catalog_source_scan_report($progress, [
            'stage' => 'discovering',
            'done' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => 'Discovering package files in ' . $basePath,
        ] + $counters);

        $files = [];
        $containersSkipped = (int)($counters['containers_skipped'] ?? 0);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $basePath,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            if (strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) === 'pak') {
                $containersSkipped++;
                continue;
            }
            if (!\catalog_source_scan_allowed_file($path, $profile, $config)) {
                continue;
            }

            $files[] = [
                $path,
                \catalog_source_scan_relative_path($basePath, $path),
            ];
            if ((count($files) % 250) === 0) {
                $stateCounters = $counters;
                $stateCounters['containers_skipped'] = $containersSkipped;
                \catalog_source_scan_report($progress, [
                    'stage' => 'discovering',
                    'done' => count($files),
                    'total' => 0,
                    'percent' => 0,
                    'message' => 'Discovered ' . count($files) . ' package-like files.',
                ] + $stateCounters);
            }
        }

        return [
            'files' => $files,
            'containers_skipped' => $containersSkipped,
        ];
    }
}
