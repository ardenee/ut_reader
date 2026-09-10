<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Creates lightweight recovery manifests that map hash-named catalog files back to their original names.
 * Why: If the catalog database is lost while package storage survives, operators need enough information to identify
 *      each physical file and feed it back through the normal importer without maintaining a full database dump.
 * Role: Infrastructure storage for file identity recovery manifests only; it never copies package data or package metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use RuntimeException;

final class FileIdentityBackupStore
{
    private const FORMAT = 'unrealdb-file-identity-v1';
    private const PAGE_SIZE = 5000;

    private string $root;
    private string $storageRoot;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($this->storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for file identity backups.');
        }

        $configured = trim((string)($config['file_identity_backups']['path'] ?? ''));
        if ($configured === '') {
            $configured = $this->storageRoot . DIRECTORY_SEPARATOR . 'file-identity-backups';
        }
        $this->root = rtrim($configured, DIRECTORY_SEPARATOR);
        $this->ensureDirectory($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Write a compact CSV containing only the fields needed to identify stored package files after database loss.
     *
     * @return array{filename:string,path:string,created_at:string,entries:int,bytes:int,sha256:string,storage_root:string}
     */
    public function create(PDO $db): array
    {
        $createdAt = gmdate('c');
        $base = 'file-identity-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $filename = $base . '.csv';
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));

        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create file identity backup.');
        }

        $entries = 0;
        $lastId = 0;
        try {
            // UTF-8 BOM keeps non-ASCII Unreal filenames intact in Windows tools such as Excel and PowerShell 5.1.
            if (fwrite($handle, "\xEF\xBB\xBF") !== 3) {
                throw new RuntimeException('Could not write file identity backup header.');
            }

            $columns = [
                'file_id',
                'game_id',
                'game_slug',
                'game_name',
                'original_name',
                'stored_name',
                'relative_path',
                'storage_relative_path',
                'physical_path',
                'file_size',
                'md5',
                'sha1',
                'scan_status',
            ];
            $this->writeCsvRow($handle, $columns);

            do {
                $statement = $db->prepare(
                    'SELECT f.id,f.game_id,g.slug AS game_slug,g.name AS game_name,'
                    . 'f.original_name,f.stored_name,f.relative_path,f.file_size,f.md5,f.sha1,f.scan_status '
                    . 'FROM ue_files f INNER JOIN ue_games g ON g.id=f.game_id '
                    . 'WHERE f.id>? ORDER BY f.id ASC LIMIT ' . self::PAGE_SIZE
                );
                $statement->execute([$lastId]);
                $rowsThisPage = 0;

                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    $relativePath = (string)($row['relative_path'] ?? '');
                    $storageRelativePath = $this->storageRelativePath($relativePath);
                    $physicalPath = $this->physicalPath($relativePath, $storageRelativePath);

                    $this->writeCsvRow($handle, [
                        (string)(int)$row['id'],
                        (string)(int)$row['game_id'],
                        (string)$row['game_slug'],
                        (string)$row['game_name'],
                        (string)$row['original_name'],
                        (string)$row['stored_name'],
                        $relativePath,
                        $storageRelativePath,
                        $physicalPath,
                        (string)(int)$row['file_size'],
                        strtolower((string)$row['md5']),
                        strtolower((string)$row['sha1']),
                        (string)$row['scan_status'],
                    ]);

                    $lastId = (int)$row['id'];
                    $rowsThisPage++;
                    $entries++;
                }
                $statement->closeCursor();
            } while ($rowsThisPage === self::PAGE_SIZE);

            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush file identity backup.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish file identity backup.');
        }
        @chmod($path, 0640);

        $bytes = (int)(@filesize($path) ?: 0);
        $sha256 = (string)(@hash_file('sha256', $path) ?: '');
        $metadata = [
            'format' => self::FORMAT,
            'created_at' => $createdAt,
            'entries' => $entries,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'storage_root' => $this->storageRoot,
            'csv' => $filename,
            'columns' => $columns,
            'purpose' => 'Maps catalog storage files back to original filenames for disaster recovery and re-import.',
        ];
        $this->writeMetadata($base . '.json', $metadata);

        return [
            'filename' => $filename,
            'path' => $path,
            'created_at' => $createdAt,
            'entries' => $entries,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'storage_root' => $this->storageRoot,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listBackups(): array
    {
        $items = @scandir($this->root);
        if (!is_array($items)) {
            return [];
        }

        $backups = [];
        foreach ($items as $item) {
            if (preg_match('/^file-identity-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.csv$/', $item) !== 1) {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $item;
            if (!is_file($path)) {
                continue;
            }

            $metadataPath = substr($path, 0, -4) . '.json';
            $metadata = [];
            if (is_file($metadataPath)) {
                $raw = @file_get_contents($metadataPath);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $metadata = is_array($decoded) ? $decoded : [];
            }

            $backups[] = [
                'filename' => $item,
                'path' => $path,
                'created_at' => (string)($metadata['created_at'] ?? gmdate('c', (int)(@filemtime($path) ?: time()))),
                'entries' => (int)($metadata['entries'] ?? 0),
                'bytes' => (int)($metadata['bytes'] ?? (@filesize($path) ?: 0)),
                'sha256' => (string)($metadata['sha256'] ?? ''),
                'storage_root' => (string)($metadata['storage_root'] ?? ''),
            ];
        }

        usort($backups, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return $backups;
    }

    public function resolve(string $filename): string
    {
        $filename = $this->validateFilename($filename);
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('File identity backup was not found.');
        }
        return $path;
    }

    public function delete(string $filename): void
    {
        $filename = $this->validateFilename($filename);
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('File identity backup was not found.');
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not delete file identity backup.');
        }
        $metadataPath = substr($path, 0, -4) . '.json';
        if (is_file($metadataPath)) {
            @unlink($metadataPath);
        }
    }

    /** @param resource $handle @param list<string> $values */
    private function writeCsvRow($handle, array $values): void
    {
        if (fputcsv($handle, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Could not write file identity backup row.');
        }
    }

    private function storageRelativePath(string $relativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if (str_starts_with(strtolower($normalized), 'storage/')) {
            return substr($normalized, strlen('storage/'));
        }
        return $normalized;
    }

    private function physicalPath(string $relativePath, string $storageRelativePath): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if (str_starts_with(strtolower($normalized), 'storage/')) {
            return $this->storageRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $storageRelativePath);
        }
        return $relativePath;
    }

    /** @param array<string,mixed> $metadata */
    private function writeMetadata(string $filename, array $metadata): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not write file identity backup metadata.');
        }
        @chmod($path, 0640);
    }

    private function validateFilename(string $filename): string
    {
        $filename = trim($filename);
        if (preg_match('/^file-identity-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.csv$/', $filename) !== 1) {
            throw new \InvalidArgumentException('Invalid file identity backup filename.');
        }
        return $filename;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create file identity backup directory: ' . $path);
        }
    }
}
