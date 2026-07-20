<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogIncomingFileStore
{
    private string $storageRoot;
    private string $directory;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required.');
        }
        $this->storageRoot = $storageRoot;
        $this->directory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'incoming';
    }

    /** @return array{relative_path:string,original_name:string,size:int,sha256:string} */
    public function stageUploadedFile(string $temporaryPath, string &$originalName): array
    {
        // Return the logical name through the existing caller variable so job
        // payloads cannot accidentally retain an unnormalised duplicate suffix.
        $originalName = $this->logicalName($originalName);
        return $this->stage($temporaryPath, $originalName, true);
    }

    /** @return array{relative_path:string,original_name:string,size:int,sha256:string} */
    public function stageLocalFile(string $sourcePath, string $originalName = ''): array
    {
        return $this->stage($sourcePath, $originalName !== '' ? $originalName : basename($sourcePath), false);
    }

    public function resolve(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:\//', $relativePath) === 1 || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1) {
            throw new \RuntimeException('Unsafe staged import path.');
        }
        $candidate = $this->storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($candidate);
        $root = realpath($this->directory);
        if ($real === false || $root === false || !is_file($real) || is_link($real)) {
            throw new \RuntimeException('Staged import file is unavailable.');
        }
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedReal = str_replace('\\', '/', $real);
        if (!str_starts_with($normalizedReal, $normalizedRoot)) {
            throw new \RuntimeException('Staged import path escapes controlled storage.');
        }
        return $real;
    }

    /**
     * Retain the staged source after a handler finishes.
     *
     * Job completion is persisted after the handler returns. Deleting here made
     * a successful import non-retryable when queue finalisation failed. Incoming
     * files are now durable placeholders and are removed only by explicit
     * deletion or age-based pruning.
     */
    public function remove(string $relativePath): void
    {
        // Intentionally retained for retry/recovery.
    }

    /** Delete a staged file when no job was created or an operator cleans it up. */
    public function delete(string $relativePath): void
    {
        try {
            $path = $this->resolve($relativePath);
        } catch (\Throwable) {
            return;
        }
        if (!@unlink($path)) {
            error_log('[UnrealDB import] Could not remove staged file: ' . basename($path));
        }
        $this->removeEmptyParents(dirname($path));
    }

    /** @return array{files:int,bytes:int} */
    public function prune(int $maxAgeSeconds = 2592000): array
    {
        $this->ensureDirectory();
        $maxAgeSeconds = max(86400, min($maxAgeSeconds, 90 * 86400));
        $threshold = time() - $maxAgeSeconds;
        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }
            $modified = (int)$entry->getMTime();
            if ($modified <= 0 || $modified >= $threshold) {
                continue;
            }
            $size = (int)$entry->getSize();
            if (@unlink($entry->getPathname())) {
                $files++;
                $bytes += $size;
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    /** @return array{relative_path:string,original_name:string,size:int,sha256:string} */
    private function stage(string $sourcePath, string $originalName, bool $move): array
    {
        $this->ensureDirectory();
        if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Import source file is unavailable.');
        }
        $size = filesize($sourcePath);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('Import source file is empty.');
        }
        $logicalName = $this->logicalName($originalName);
        $safeName = $this->safeName($logicalName);
        $dateDirectory = $this->directory . DIRECTORY_SEPARATOR . gmdate('Ymd');
        if (!is_dir($dateDirectory) && !mkdir($dateDirectory, 0750, true) && !is_dir($dateDirectory)) {
            throw new \RuntimeException('Could not create staged import directory.');
        }
        $token = gmdate('His') . '-' . bin2hex(random_bytes(12));
        $destination = $dateDirectory . DIRECTORY_SEPARATOR . $token . '-' . $safeName;
        $part = $destination . '.part';
        $stored = false;
        try {
            if ($move && is_uploaded_file($sourcePath)) {
                $stored = move_uploaded_file($sourcePath, $part);
            } elseif ($move) {
                $stored = @rename($sourcePath, $part);
                if (!$stored) {
                    $stored = @copy($sourcePath, $part);
                    if ($stored) {
                        @unlink($sourcePath);
                    }
                }
            } else {
                $stored = @copy($sourcePath, $part);
            }
            if (!$stored || !is_file($part)) {
                throw new \RuntimeException('Could not stage import source file.');
            }
            @chmod($part, 0640);
            if (!@rename($part, $destination)) {
                throw new \RuntimeException('Could not publish staged import source file.');
            }
            $sha256 = hash_file('sha256', $destination);
            if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new \RuntimeException('Could not hash staged import source file.');
            }
            $relative = ltrim(str_replace('\\', '/', substr($destination, strlen($this->storageRoot))), '/');
            return [
                'relative_path' => $relative,
                'original_name' => $logicalName,
                'size' => (int)$size,
                'sha256' => $sha256,
            ];
        } catch (\Throwable $error) {
            @unlink($part);
            @unlink($destination);
            throw $error;
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create staged import storage.');
        }
    }

    private function logicalName(string $name): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        if ($name === '' || $name === '.' || $name === '..') {
            return 'package.bin';
        }

        do {
            $previous = $name;

            // Some download tools append the duplicate marker after the whole
            // filename, for example Name.ut2 (2).
            $name = preg_replace('/\s+\([0-9]+\)$/u', '', $name) ?? $name;

            // Normal copies place the marker at the end of the stem. Handling
            // the stem also fixes Name.uax (2).uz2 before redirect decoding.
            $extension = (string)pathinfo($name, PATHINFO_EXTENSION);
            if ($extension !== '') {
                $stem = (string)pathinfo($name, PATHINFO_FILENAME);
                $stem = preg_replace('/\s+\([0-9]+\)$/u', '', $stem) ?? $stem;
                $stem = preg_replace('/\s+-\s+copy(?:\s*\([0-9]+\))?$/iu', '', $stem) ?? $stem;
                $stem = preg_replace('/\s+copy(?:\s*\([0-9]+\))?$/iu', '', $stem) ?? $stem;
                $name = rtrim($stem, ' .') . '.' . $extension;
            }

            $name = rtrim(trim($name), ' .');
        } while ($name !== $previous);

        return $name !== '' ? $name : 'package.bin';
    }

    private function safeName(string $name): string
    {
        // This is only the controlled staging filename. It must never replace
        // the logical original_name stored in the database.
        $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 180, 'UTF-8');
        } else {
            $name = substr($name, 0, 180);
        }
        return $name !== '' ? $name : 'package.bin';
    }

    private function removeEmptyParents(string $directory): void
    {
        $root = realpath($this->directory);
        while ($root !== false) {
            $real = realpath($directory);
            if ($real === false || $real === $root || !str_starts_with(str_replace('\\', '/', $real) . '/', rtrim(str_replace('\\', '/', $root), '/') . '/')) {
                return;
            }
            if (!@rmdir($real)) {
                return;
            }
            $directory = dirname($real);
        }
    }
}
