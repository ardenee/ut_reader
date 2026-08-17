<?php
/**
 * Persists a neutral Upload Bucket PAK parent and the extracted package rows it owns.
 * The parent is a container identity, not an Unreal package-table identity.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataStore;

final class CatalogBucketPakContainerStore
{
    private readonly CatalogUnverifiedPackageRuntime $runtime;
    private readonly CatalogUnverifiedMetadataStore $metadata;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->runtime = new CatalogUnverifiedPackageRuntime($db, $config);
        $this->metadata = new CatalogUnverifiedMetadataStore($db);
    }

    public function ensureSchema(): void
    {
        $this->runtime->ensureSchema();
        $statement = $this->db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_unverified_pak_members"'
        );
        if ((int)($statement ? $statement->fetchColumn() : 0) !== 1) {
            throw new \RuntimeException(
                'Upload Bucket PAK schema is not migrated. Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }
    }

    /**
     * @return array{status:string,file_id:int,queue_name:string,path:string,original_name:string,size:int,message:string}
     */
    public function publishParent(
        string $sourcePath,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        string $md5,
        string $sha1,
        string $extractLog,
        int $entryCount
    ): array {
        $this->ensureSchema();
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Prepared PAK source is unavailable.');
        }
        if ($uploadedBy < 1) {
            throw new \RuntimeException('Administrator identity is missing from the Upload Bucket PAK job.');
        }
        $size = (int)(filesize($sourcePath) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('Prepared PAK source is empty.');
        }
        $md5 = strtolower(trim($md5));
        $sha1 = strtolower(trim($sha1));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Prepared PAK MD5 or SHA-1 is missing.');
        }

        $inspection = (new CatalogUploadDuplicateDetector($this->db, $this->config))->inspect($size, $md5, $sha1);
        $duplicate = is_array($inspection['duplicate'] ?? null) ? $inspection['duplicate'] : null;
        if ($duplicate !== null) {
            return [
                'status' => 'duplicate',
                'file_id' => (int)$duplicate['file_id'],
                'queue_name' => '',
                'path' => (string)$duplicate['physical_path'],
                'original_name' => trim((string)($duplicate['original_name'] ?? '')) ?: $originalName,
                'size' => $size,
                'message' => 'Identical PAK bytes already exist as file #' . (int)$duplicate['file_id'] . '.',
            ];
        }

        $copy = $this->storageCopy($sourcePath, $originalName);
        $stored = null;
        try {
            $stored = (new CatalogUploadBucketStorage($this->runtime))->store($copy, $originalName, $reason);
            $copy = '';
            $queueName = (string)$stored['queue_name'];
            $storedPath = (string)$stored['path'];
            $cleanName = (string)$stored['original_name'];
            $packageName = trim((string)pathinfo($cleanName, PATHINFO_FILENAME));
            if ($packageName === '') {
                $packageName = 'PAK';
            }
            $sourceRelativePath = $this->runtime->normalizeSourceRelativePath($sourceRelativePath);
            $queueKey = $this->runtime->queueKey(0, $queueName);
            $relativePath = $this->runtime->storageRelative($storedPath);
            $notes = 'Valid Unreal PAK container. ' . trim($extractLog)
                . ' Bucket extraction discovered ' . max(0, $entryCount) . ' physical entr'
                . (max(0, $entryCount) === 1 ? 'y.' : 'ies.');
            if ($reason !== '') {
                $notes .= "\nQueue reason: " . $reason;
            }

            $this->db->beginTransaction();
            try {
                $statement = $this->db->prepare(
                    'INSERT INTO ue_files('
                    . 'game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
                    . 'detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,'
                    . 'compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,'
                    . 'is_compressed,compression_flags,package_version,licensee_version,name_count,import_count,'
                    . 'export_count,scan_status,scan_notes,uploaded_by,unverified_queue_key,'
                    . 'unverified_queue_game_id,unverified_queue_name,unverified_reason'
                    . ') VALUES(NULL,?,?,?,?,?,"pak","PAK",NULL,NULL,"high","unverified",NULL,?,?,?,?,NULL,0,0,0,0,0,0,0,"unverified",?,?,?,?,?,?)'
                );
                $statement->execute([
                    $packageName,
                    $cleanName,
                    $sourceRelativePath !== '' ? $sourceRelativePath : null,
                    $queueName,
                    $relativePath,
                    $notes,
                    (int)$stored['size'],
                    $md5,
                    $sha1,
                    $notes,
                    $uploadedBy,
                    $queueKey,
                    0,
                    $queueName,
                    $reason,
                ]);
                $fileId = (int)$this->db->lastInsertId();
                $this->metadata->write([
                    'file_id' => $fileId,
                    'package_name' => $packageName,
                    'names' => [],
                    'imports' => [],
                    'exports' => [],
                ]);
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }

            return [
                'status' => 'indexed',
                'file_id' => $fileId,
                'queue_name' => $queueName,
                'path' => $storedPath,
                'original_name' => $cleanName,
                'size' => (int)$stored['size'],
                'message' => 'Retained PAK container and queued extracted package inspection.',
            ];
        } catch (Throwable $error) {
            if (is_array($stored)) {
                @unlink((string)$stored['path'] . '.txt');
                @unlink((string)$stored['path']);
            }
            throw $error;
        } finally {
            if ($copy !== '' && is_file($copy)) {
                @unlink($copy);
            }
        }
    }

    public function ensureMember(
        int $parentFileId,
        int $entryIndex,
        string $entryPath,
        string $entryName,
        string $extension,
        string $status = 'pending',
        string $message = ''
    ): int {
        $this->ensureSchema();
        if ($parentFileId < 1 || $entryIndex < 0) {
            throw new \InvalidArgumentException('PAK member identity is invalid.');
        }
        $statement = $this->db->prepare(
            'INSERT INTO ue_unverified_pak_members('
            . 'parent_file_id,entry_index,entry_path,entry_name,extension,status,message) '
            . 'VALUES(?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE entry_path=VALUES(entry_path),entry_name=VALUES(entry_name),'
            . 'extension=VALUES(extension),status=IF(status IN ("indexed","duplicate"),status,VALUES(status)),'
            . 'message=IF(status IN ("indexed","duplicate"),message,VALUES(message)),id=LAST_INSERT_ID(id)'
        );
        $statement->execute([
            $parentFileId,
            $entryIndex,
            $entryPath,
            $entryName,
            strtolower($extension),
            $status,
            $message !== '' ? $this->short($message) : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function completeMember(
        int $memberId,
        string $status,
        ?int $childFileId,
        bool $ownsChild,
        string $message
    ): void {
        $this->ensureSchema();
        $statement = $this->db->prepare(
            'UPDATE ue_unverified_pak_members SET status=?,child_file_id=?,owns_child_file=GREATEST(owns_child_file,?),message=? WHERE id=?'
        );
        $statement->execute([
            $status,
            $childFileId !== null && $childFileId > 0 ? $childFileId : null,
            $ownsChild ? 1 : 0,
            $this->short($message),
            $memberId,
        ]);
    }

    /** @return array{total:int,indexed:int,duplicate:int,skipped:int,rejected:int,pending:int} */
    public function summary(int $parentFileId): array
    {
        $this->ensureSchema();
        $summary = ['total' => 0, 'indexed' => 0, 'duplicate' => 0, 'skipped' => 0, 'rejected' => 0, 'pending' => 0];
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_unverified_pak_members WHERE parent_file_id=? GROUP BY status'
        );
        $statement->execute([$parentFileId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = strtolower((string)$row['status']);
            $count = (int)$row['c'];
            $summary['total'] += $count;
            if (array_key_exists($status, $summary)) {
                $summary[$status] += $count;
            }
        }
        return $summary;
    }

    public function finishParent(int $parentFileId): array
    {
        $summary = $this->summary($parentFileId);
        $message = 'PAK contents: ' . $summary['indexed'] . ' indexed, '
            . $summary['duplicate'] . ' duplicate, ' . $summary['skipped'] . ' skipped, '
            . $summary['rejected'] . ' rejected.';
        $this->db->prepare(
            'UPDATE ue_files SET scan_notes=CONCAT(COALESCE(scan_notes,""),IF(COALESCE(scan_notes,"")="","","\n"),?) WHERE id=? AND scan_status="unverified"'
        )->execute([$message, $parentFileId]);
        return $summary;
    }

    private function storageCopy(string $sourcePath, string $name): string
    {
        $root = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($root === '') {
            throw new \RuntimeException('Catalog storage path is unavailable for PAK publication.');
        }
        $directory = $root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'bucket-pak-publish';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create PAK publication workspace.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '.pak';
        if (!@copy($sourcePath, $path)) {
            throw new \RuntimeException('Could not create retained PAK publication copy.');
        }
        $sourceSize = filesize($sourcePath);
        $copySize = filesize($path);
        if ($sourceSize === false || $copySize === false || (int)$sourceSize !== (int)$copySize) {
            @unlink($path);
            throw new \RuntimeException('Retained PAK publication copy is incomplete.');
        }
        return $path;
    }

    private function short(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        return function_exists('mb_substr') ? mb_substr($message, 0, 1000, 'UTF-8') : substr($message, 0, 1000);
    }
}
