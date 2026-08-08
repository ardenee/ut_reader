<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Removes Game Manager managed storage and explicitly selected staged files.
 * Why: Reset/delete orchestration should not own recursive filesystem deletion or storage-containment checks.
 * Role: Infrastructure filesystem collaborator preserving the historical Game Manager storage behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class CatalogGameStorageCleanup
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    public function removeManagedGameTree(string $gameSlug, ?callable $progress = null): int
    {
        $storageRoot = $this->storageRoot();
        $gameStoragePath = $storageRoot
            . DIRECTORY_SEPARATOR . 'games'
            . DIRECTORY_SEPARATOR . $this->slug($gameSlug);
        return $this->removeStorageTree($gameStoragePath, $storageRoot, $progress);
    }

    /**
     * @param list<array{id:int,relative_path:string,file_size:int}> $rows
     */
    public function removeStagedRows(array $rows): int
    {
        $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        $catalogRoot = realpath(dirname(__DIR__, 3));
        if ($storageRoot === false || $catalogRoot === false) {
            return 0;
        }

        $storagePrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $removed = 0;
        foreach ($rows as $row) {
            $relative = ltrim(str_replace('\\', '/', (string)($row['relative_path'] ?? '')), '/');
            if ($relative === '') {
                continue;
            }

            $candidate = $catalogRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $resolved = realpath($candidate);
            if ($resolved === false
                || !is_file($resolved)
                || !str_starts_with($resolved, $storagePrefix)) {
                continue;
            }

            if (!@unlink($resolved)) {
                throw new RuntimeException('Could not remove staged game file: ' . $resolved);
            }
            $removed++;

            $note = $resolved . '.txt';
            if (is_file($note) && !@unlink($note)) {
                throw new RuntimeException('Could not remove staged game-file note: ' . $note);
            }
        }

        return $removed;
    }

    private function storageRoot(): string
    {
        $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            throw new RuntimeException('Catalog storage folder is unavailable.');
        }
        return $storageRoot;
    }

    /** @return array{path:string,is_dir:bool} */
    private function storageEntry(SplFileInfo $item): array
    {
        return [
            'path' => $item->getPathname(),
            'is_dir' => $item->isDir() && !$item->isLink(),
        ];
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    private function removeStorageTree(
        string $targetPath,
        string $storageRoot,
        ?callable $progress = null
    ): int {
        CatalogGameLifecycleProgress::emit(
            $progress,
            'storage_scan',
            0,
            1,
            2,
            'Inspecting managed game storage…'
        );
        if (!file_exists($targetPath)) {
            CatalogGameLifecycleProgress::emit(
                $progress,
                'storage_delete',
                0,
                0,
                82,
                'No managed game storage folder exists.'
            );
            return 0;
        }

        $storageRoot = rtrim(realpath($storageRoot) ?: $storageRoot, DIRECTORY_SEPARATOR);
        $rootPrefix = $storageRoot . DIRECTORY_SEPARATOR;
        $resolved = realpath($targetPath);
        if ($resolved === false
            || !str_starts_with($resolved, $rootPrefix)
            || $resolved === $storageRoot) {
            throw new RuntimeException('Refusing to reset storage outside the catalog storage folder.');
        }

        if (is_file($resolved) || is_link($resolved)) {
            CatalogGameLifecycleProgress::emit(
                $progress,
                'storage_delete',
                0,
                1,
                12,
                'Deleting the managed game storage item…'
            );
            if (!@unlink($resolved)) {
                throw new RuntimeException('Could not remove stored file: ' . $resolved);
            }
            CatalogGameLifecycleProgress::emit(
                $progress,
                'storage_delete',
                1,
                1,
                82,
                'Managed game storage deleted.'
            );
            return 1;
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $entries[] = $this->storageEntry($item);
            $count = count($entries);
            if (($count % 250) === 0) {
                CatalogGameLifecycleProgress::emit(
                    $progress,
                    'storage_scan',
                    $count,
                    $count + 1,
                    4,
                    'Counting managed storage entries… ' . $count . ' found'
                );
            }
        }

        $total = max(1, count($entries) + 1);
        $removedFiles = 0;
        foreach ($entries as $index => $entry) {
            $path = $entry['path'];
            if ($entry['is_dir']) {
                if (!@rmdir($path)) {
                    throw new RuntimeException('Could not remove storage folder: ' . $path);
                }
            } else {
                if (!@unlink($path)) {
                    throw new RuntimeException('Could not remove stored file: ' . $path);
                }
                $removedFiles++;
            }

            $done = $index + 1;
            $percent = 5 + (int)floor(($done / $total) * 75);
            CatalogGameLifecycleProgress::emit(
                $progress,
                'storage_delete',
                $done,
                $total,
                $percent,
                'Deleting managed storage entry ' . $done . '/' . $total . ': ' . basename($path)
            );
        }

        if (!@rmdir($resolved)) {
            throw new RuntimeException('Could not remove game storage folder: ' . $resolved);
        }
        CatalogGameLifecycleProgress::emit(
            $progress,
            'storage_delete',
            $total,
            $total,
            82,
            'Managed game storage deleted.'
        );
        return $removedFiles;
    }

    private function slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'game';
    }
}
