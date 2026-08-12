<?php
/**
 * Durable PAK extraction/index workspace owned by one parent import job.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CatalogPakImportWorkspace
{
    private string $directory;
    private string $filesDirectory;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, int $parentJobId)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for PAK import workspace.');
        }
        if ($parentJobId < 1) {
            throw new \InvalidArgumentException('A positive parent job id is required for PAK import workspace.');
        }
        $this->directory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR
            . 'pak-import' . DIRECTORY_SEPARATOR . 'job-' . $parentJobId;
        $this->filesDirectory = $this->directory . DIRECTORY_SEPARATOR . 'files';
    }

    public function available(): bool
    {
        return is_file($this->statePath()) && is_dir($this->filesDirectory);
    }

    /**
     * @param array<string,mixed> $state
     * @param list<array<string,mixed>> $extractedFiles
     */
    public function publish(string $temporaryDirectory, array $state, array $extractedFiles): void
    {
        if ($this->available()) {
            return;
        }
        $temporaryReal = realpath($temporaryDirectory);
        if ($temporaryReal === false || !is_dir($temporaryReal)) {
            throw new \RuntimeException('Extracted PAK temporary directory is unavailable.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create durable PAK import workspace.');
        }
        if (file_exists($this->filesDirectory)) {
            $this->deleteTree($this->filesDirectory);
        }

        if (!@rename($temporaryReal, $this->filesDirectory)) {
            $this->copyTree($temporaryReal, $this->filesDirectory);
            $this->deleteTree($temporaryReal);
        }

        $files = [];
        $temporaryPrefix = rtrim(str_replace('\\', '/', $temporaryReal), '/') . '/';
        foreach ($extractedFiles as $file) {
            if (!is_array($file)) {
                continue;
            }
            $display = $this->normalize((string)($file['relative'] ?? ''));
            $oldPath = str_replace('\\', '/', (string)($file['path'] ?? ''));
            if ($display === '' || !str_starts_with(strtolower($oldPath), strtolower($temporaryPrefix))) {
                continue;
            }
            $workspaceRelative = $this->normalize(substr($oldPath, strlen($temporaryPrefix)));
            if ($workspaceRelative === '') {
                continue;
            }
            $files[strtolower($display)] = $workspaceRelative;
        }

        $state['extracted_files'] = $files;
        $state['published_at'] = gmdate('c');
        $this->writeState($state);
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $raw = @file_get_contents($this->statePath());
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    public function entry(int $entryIndex): array
    {
        $state = $this->state();
        $index = is_array($state['index'] ?? null) ? $state['index'] : [];
        $entries = is_array($index['entries'] ?? null) ? array_values($index['entries']) : [];
        if ($entryIndex < 0 || !isset($entries[$entryIndex]) || !is_array($entries[$entryIndex])) {
            throw new \RuntimeException('PAK import workspace entry is unavailable: ' . $entryIndex);
        }
        return $entries[$entryIndex];
    }

    public function extractedPath(string $displayPath): ?string
    {
        $state = $this->state();
        $files = is_array($state['extracted_files'] ?? null) ? $state['extracted_files'] : [];
        $key = strtolower($this->normalize($displayPath));
        $relative = $key !== '' ? $this->normalize((string)($files[$key] ?? '')) : '';
        if ($relative === '') {
            return null;
        }
        $root = realpath($this->filesDirectory);
        $candidate = realpath($this->filesDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($root === false || $candidate === false || !is_file($candidate) || !is_readable($candidate) || is_link($candidate)) {
            return null;
        }
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $candidate), $prefix)) {
            return null;
        }
        return $candidate;
    }

    public function workingCopy(string $sourcePath, int $entryIndex): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Durable extracted PAK entry is unavailable.');
        }
        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'bin';
        $workDir = $this->directory . DIRECTORY_SEPARATOR . 'work';
        if (!is_dir($workDir) && !@mkdir($workDir, 0750, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Could not create PAK entry working directory.');
        }
        foreach (glob($workDir . DIRECTORY_SEPARATOR . 'entry-' . $entryIndex . '-*') ?: [] as $old) {
            if (is_string($old) && is_file($old)) {
                @unlink($old);
            }
        }
        $path = $workDir . DIRECTORY_SEPARATOR . 'entry-' . $entryIndex . '-'
            . bin2hex(random_bytes(6)) . '.' . $extension;
        if (@link($sourcePath, $path)) {
            return $path;
        }
        if (!@copy($sourcePath, $path)) {
            throw new \RuntimeException('Could not create disposable PAK entry working copy.');
        }
        $sourceSize = filesize($sourcePath);
        $copySize = filesize($path);
        if ($sourceSize === false || $copySize === false || (int)$sourceSize !== (int)$copySize) {
            @unlink($path);
            throw new \RuntimeException('Disposable PAK entry working copy is incomplete.');
        }
        return $path;
    }

    public function clear(): void
    {
        $this->deleteTree($this->directory);
        @rmdir(dirname($this->directory));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not publish durable PAK import workspace metadata.');
        }
        @chmod($path, 0640);
    }

    private function statePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'state.json';
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace('\\', '/', $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($destination) && !@mkdir($destination, 0750, true) && !is_dir($destination)) {
            throw new \RuntimeException('Could not create durable PAK extraction directory.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $sourcePrefix = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($sourcePrefix));
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($entry->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0750, true) && !is_dir($target)) {
                    throw new \RuntimeException('Could not create PAK extraction subdirectory.');
                }
                continue;
            }
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Could not create PAK extraction target directory.');
            }
            if (!@copy($entry->getPathname(), $target)) {
                throw new \RuntimeException('Could not persist extracted PAK entry: ' . $relative);
            }
            @chmod($target, 0640);
        }
    }

    private function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
