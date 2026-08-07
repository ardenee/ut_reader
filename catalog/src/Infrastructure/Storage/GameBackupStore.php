<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `GameBackupStore` for game backup store.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

final class GameBackupStore
{
    private string $root;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $configured = trim((string)($config['game_backups']['path'] ?? ''));
        if ($configured === '') {
            $storage = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
            if ($storage === '') {
                throw new \InvalidArgumentException('A catalog storage path is required for game backups.');
            }
            $configured = $storage . DIRECTORY_SEPARATOR . 'game-backups';
        }
        $this->root = rtrim($configured, DIRECTORY_SEPARATOR);
        $this->ensureDirectory($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function backupPath(string $backupKey): string
    {
        $backupKey = $this->validateKey($backupKey);
        return $this->root . DIRECTORY_SEPARATOR . $backupKey;
    }

    public function filesPath(string $backupKey): string
    {
        return $this->backupPath($backupKey) . DIRECTORY_SEPARATOR . 'files';
    }

    /** @return array{path:string,files_path:string} */
    public function create(string $backupKey): array
    {
        $path = $this->backupPath($backupKey);
        if (file_exists($path)) {
            throw new \RuntimeException('A backup with this name already exists: ' . $backupKey);
        }
        $this->ensureDirectory($path);
        $filesPath = $path . DIRECTORY_SEPARATOR . 'files';
        $this->ensureDirectory($filesPath);
        $this->writeState($backupKey, [
            'backup_key' => $backupKey,
            'status' => 'building',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'files_done' => 0,
            'files_total' => 0,
            'bytes_done' => 0,
            'bytes_total' => 0,
        ]);
        return ['path' => $path, 'files_path' => $filesPath];
    }

    /** @param array<string,mixed> $state */
    public function writeState(string $backupKey, array $state): void
    {
        $state['backup_key'] = $this->validateKey($backupKey);
        $state['updated_at'] = gmdate('c');
        $this->writeJson($this->backupPath($backupKey) . DIRECTORY_SEPARATOR . 'state.json', $state);
    }

    /** @param array<string,mixed> $manifest */
    public function publishManifest(string $backupKey, array $manifest): void
    {
        $manifest['backup_key'] = $this->validateKey($backupKey);
        $manifest['status'] = 'complete';
        $this->writeJson($this->backupPath($backupKey) . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
        $this->writeState($backupKey, [
            'backup_key' => $backupKey,
            'status' => 'complete',
            'created_at' => (string)($manifest['created_at'] ?? ''),
            'completed_at' => (string)($manifest['completed_at'] ?? gmdate('c')),
            'game_id' => (int)($manifest['source_game']['id'] ?? 0),
            'game_name' => (string)($manifest['source_game']['name'] ?? ''),
            'game_slug' => (string)($manifest['source_game']['slug'] ?? ''),
            'files_done' => (int)($manifest['summary']['entries'] ?? 0),
            'files_total' => (int)($manifest['summary']['entries'] ?? 0),
            'physical_files' => (int)($manifest['summary']['physical_files'] ?? 0),
            'bytes_done' => (int)($manifest['summary']['bytes'] ?? 0),
            'bytes_total' => (int)($manifest['summary']['bytes'] ?? 0),
            'conflicts' => (int)($manifest['summary']['conflicts'] ?? 0),
            'renamed_variations' => (int)($manifest['summary']['renamed_variations'] ?? 0),
            'paths_from_primary' => (int)($manifest['summary']['paths_from_primary'] ?? 0),
            'paths_from_locations' => (int)($manifest['summary']['paths_from_locations'] ?? 0),
            'paths_unsorted' => (int)($manifest['summary']['paths_unsorted'] ?? 0),
        ]);
    }

    /** @return array<string,mixed> */
    public function readManifest(string $backupKey): array
    {
        return $this->readJson($this->backupPath($backupKey) . DIRECTORY_SEPARATOR . 'manifest.json');
    }

    /** @return array<string,mixed> */
    public function readState(string $backupKey): array
    {
        return $this->readJson($this->backupPath($backupKey) . DIRECTORY_SEPARATOR . 'state.json');
    }

    /** @return list<array<string,mixed>> */
    public function listBackups(): array
    {
        $entries = @scandir($this->root);
        if (!is_array($entries)) {
            return [];
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || preg_match('/^[A-Za-z0-9._-]+$/', $entry) !== 1) {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $state = $this->readJson($path . DIRECTORY_SEPARATOR . 'state.json');
            $manifest = $this->readJson($path . DIRECTORY_SEPARATOR . 'manifest.json');
            $sourceGame = is_array($manifest['source_game'] ?? null) ? $manifest['source_game'] : [];
            $summary = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];
            $result[] = [
                'backup_key' => $entry,
                'path' => $path,
                'status' => (string)($manifest['status'] ?? $state['status'] ?? 'incomplete'),
                'created_at' => (string)($manifest['created_at'] ?? $state['created_at'] ?? ''),
                'completed_at' => (string)($manifest['completed_at'] ?? $state['completed_at'] ?? ''),
                'game_id' => (int)($sourceGame['id'] ?? $state['game_id'] ?? 0),
                'game_name' => (string)($sourceGame['name'] ?? $state['game_name'] ?? ''),
                'game_slug' => (string)($sourceGame['slug'] ?? $state['game_slug'] ?? ''),
                'engine_key' => (string)($sourceGame['engine_key'] ?? ''),
                'profile_name' => (string)($sourceGame['profile_name'] ?? ''),
                'entries' => (int)($summary['entries'] ?? $state['files_done'] ?? 0),
                'physical_files' => (int)($summary['physical_files'] ?? $state['physical_files'] ?? 0),
                'bytes' => (int)($summary['bytes'] ?? $state['bytes_done'] ?? 0),
                'conflicts' => (int)($summary['conflicts'] ?? $state['conflicts'] ?? 0),
                'renamed_variations' => (int)($summary['renamed_variations'] ?? $state['renamed_variations'] ?? 0),
                'paths_from_primary' => (int)($summary['paths_from_primary'] ?? $state['paths_from_primary'] ?? 0),
                'paths_from_locations' => (int)($summary['paths_from_locations'] ?? $state['paths_from_locations'] ?? 0),
                'paths_unsorted' => (int)($summary['paths_unsorted'] ?? $state['paths_unsorted'] ?? 0),
                'complete' => is_file($path . DIRECTORY_SEPARATOR . 'manifest.json') && (string)($manifest['status'] ?? '') === 'complete',
                'state' => $state,
            ];
        }
        usort($result, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return $result;
    }

    public function resolveBackupFile(string $backupKey, string $relativePath): string
    {
        $relativePath = self::safeRelativePath($relativePath);
        if ($relativePath === '') {
            throw new \RuntimeException('Backup file path is empty.');
        }
        $filesRoot = realpath($this->filesPath($backupKey));
        if ($filesRoot === false || !is_dir($filesRoot)) {
            throw new \RuntimeException('Backup files directory is unavailable.');
        }
        $candidate = realpath($filesRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($candidate === false || !is_file($candidate)) {
            throw new \RuntimeException('Backup file is missing: ' . $relativePath);
        }
        $prefix = rtrim($filesRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $prefix)) {
            throw new \RuntimeException('Backup file path escapes the backup directory.');
        }
        return $candidate;
    }

    public function delete(string $backupKey): void
    {
        $path = $this->backupPath($backupKey);
        if (!is_dir($path)) {
            throw new \RuntimeException('Backup directory was not found.');
        }
        $this->deleteTree($path);
    }

    public static function safeRelativePath(string $path): string
    {
        $path = trim(str_replace(["\0", '\\'], ['', '/'], $path), '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $part = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '_', $part) ?? '_';
            $part = rtrim(trim($part), ' .');
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    private function validateKey(string $backupKey): string
    {
        $backupKey = trim($backupKey);
        if ($backupKey === '' || strlen($backupKey) > 180 || preg_match('/^[A-Za-z0-9._-]+$/', $backupKey) !== 1) {
            throw new \InvalidArgumentException('Invalid game-backup identifier.');
        }
        return $backupKey;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new \RuntimeException('Could not create game-backup directory: ' . $path);
        }
    }

    /** @param array<string,mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not write game-backup metadata: ' . basename($path));
        }
        @chmod($path, 0640);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException('Could not delete backup file: ' . $path);
            }
            return;
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            throw new \RuntimeException('Could not read backup directory for deletion.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException('Could not delete backup directory: ' . $path);
        }
    }
}
