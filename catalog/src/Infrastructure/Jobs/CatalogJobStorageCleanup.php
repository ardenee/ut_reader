<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogJobStorageCleanup` for catalog job storage cleanup.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Removes disposable and orphaned job-storage files without deleting sources
 * still referenced by any surviving background-job row.
 */
final class CatalogJobStorageCleanup
{
    private string $storageRoot;
    private string $incomingDirectory;
    private string $backupImportDirectory;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        array $config
    ) {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for job-storage cleanup.');
        }
        $this->storageRoot = $storageRoot;
        $this->incomingDirectory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'incoming';
        $this->backupImportDirectory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'game-backup-import';
    }

    /**
     * @return array{
     *   incoming:array{scanned:int,referenced:int,recent:int,deleted:int,bytes:int,failed:int},
     *   backup_import:array{scanned:int,active:int,recent:int,deleted:int,bytes:int,failed:int}
     * }
     */
    public function prune(int $minimumAgeSeconds = 300): array
    {
        $minimumAgeSeconds = max(60, min($minimumAgeSeconds, 30 * 86400));
        return [
            'incoming' => $this->pruneIncoming($minimumAgeSeconds),
            'backup_import' => $this->pruneBackupImport($minimumAgeSeconds),
        ];
    }

    /** @return array{scanned:int,referenced:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneIncoming(int $minimumAgeSeconds): array
    {
        $result = ['scanned' => 0, 'referenced' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->incomingDirectory)) {
            return $result;
        }

        $references = $this->incomingReferences();
        $threshold = time() - $minimumAgeSeconds;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->incomingDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }

            $result['scanned']++;
            $path = $entry->getPathname();
            $relative = $this->storageRelativePath($path);
            if ($relative !== '' && isset($references[strtolower($relative)])) {
                $result['referenced']++;
                continue;
            }
            if ((int)$entry->getMTime() > $threshold) {
                $result['recent']++;
                continue;
            }

            $size = max(0, (int)$entry->getSize());
            if (@unlink($path)) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
        }
        @rmdir($this->incomingDirectory);
        return $result;
    }

    /** @return array{scanned:int,active:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneBackupImport(int $minimumAgeSeconds): array
    {
        $result = ['scanned' => 0, 'active' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->backupImportDirectory)) {
            return $result;
        }

        $activeJobs = $this->activeBackupImportJobs();
        $threshold = time() - $minimumAgeSeconds;
        foreach (new FilesystemIterator($this->backupImportDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $result['scanned']++;
            $name = $entry->getFilename();
            $jobId = preg_match('/^restore-([0-9]+)-/i', $name, $match) === 1 ? (int)$match[1] : 0;
            if (($jobId > 0 && isset($activeJobs[$jobId])) || ($jobId === 0 && $activeJobs !== [])) {
                $result['active']++;
                continue;
            }
            if ((int)$entry->getMTime() > $threshold) {
                $result['recent']++;
                continue;
            }

            $size = max(0, (int)$entry->getSize());
            if (@unlink($entry->getPathname())) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
        }
        @rmdir($this->backupImportDirectory);
        return $result;
    }

    /** @return array<string,true> */
    private function incomingReferences(): array
    {
        $references = [];
        $statement = $this->db->query(
            'SELECT payload_json FROM ue_background_jobs WHERE payload_json LIKE "%jobs/incoming/%"'
        );
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            try {
                $payload = json_decode((string)$json, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $this->collectIncomingReferences($payload, $references);
        }
        return $references;
    }

    /** @param array<string,true> $references */
    private function collectIncomingReferences(mixed $value, array &$references): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->collectIncomingReferences($child, $references);
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }
        $path = ltrim(str_replace('\\', '/', trim($value)), '/');
        if (str_starts_with(strtolower($path), 'jobs/incoming/')) {
            $references[strtolower($path)] = true;
        }
    }

    /** @return array<int,true> */
    private function activeBackupImportJobs(): array
    {
        $statement = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE job_type=? AND status IN ("queued","running")'
        );
        $statement->execute([\UnrealDb\Catalog\Domain\Jobs\JobType::IMPORT_GAME_BACKUP]);
        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $jobId = (int)$id;
            if ($jobId > 0) {
                $ids[$jobId] = true;
            }
        }
        return $ids;
    }

    private function storageRelativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->storageRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with(strtolower($normalized), strtolower($root))) {
            return '';
        }
        return ltrim(substr($normalized, strlen($root)), '/');
    }
}
