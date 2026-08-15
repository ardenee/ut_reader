<?php
/**
 * Read-only operational storage metrics for bounded known directories.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CatalogOperationalStorageMetrics
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    /** @return array<string,array{files:int,bytes:int}> */
    public function controlledDirectories(): array
    {
        $root = rtrim($this->storageRoot, DIRECTORY_SEPARATOR);
        return [
            'generated_packages' => $this->directory($root . DIRECTORY_SEPARATOR . 'generated-packages'),
            'staged_imports' => $this->directory($root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'incoming'),
            'incoming_federation' => $this->directory($root . DIRECTORY_SEPARATOR . 'federation' . DIRECTORY_SEPARATOR . 'incoming'),
        ];
    }

    /** @return array{total_bytes:int|null,free_bytes:int|null} */
    public function capacity(): array
    {
        $root = rtrim($this->storageRoot, DIRECTORY_SEPARATOR);
        $total = is_dir($root) ? @disk_total_space($root) : false;
        $free = is_dir($root) ? @disk_free_space($root) : false;
        return [
            'total_bytes' => is_int($total) || is_float($total) ? max(0, (int)$total) : null,
            'free_bytes' => is_int($free) || is_float($free) ? max(0, (int)$free) : null,
        ];
    }

    /** @return array{files:int,bytes:int} */
    private function directory(string $directory): array
    {
        if (!is_dir($directory)) {
            return ['files' => 0, 'bytes' => 0];
        }
        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $files++;
            $bytes += max(0, (int)$entry->getSize());
        }
        return ['files' => $files, 'bytes' => $bytes];
    }
}
