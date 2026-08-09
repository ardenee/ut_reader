<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedFileMetadataConverter;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

final class CompactMetadataCutoverService
{
    public const LOCK_NAME = 'unrealdb_compact_metadata_cutover_v1';
    private const LEGACY_TABLES = [
        'ue_dependencies',
        'ue_imports',
        'ue_exports',
        'ue_names',
    ];

    private readonly BlockedCompressedFileMetadataConverter $converter;

    public function __construct(
        private readonly PDO $db,
        string $storageRoot
    ) {
        $storageRoot = trim($storageRoot);
        if ($storageRoot === '') {
            throw new RuntimeException('A catalog storage path is required for compact metadata cutover.');
        }
        $this->converter = new BlockedCompressedFileMetadataConverter($db, $storageRoot);
    }

    /** @return array<string,mixed> */
    public function status(bool $includeLegacyCounts = true): array
    {
        $missing = $this->scalar(
            'SELECT COUNT(*) FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" '
            . 'AND (m.file_id IS NULL OR m.format_version<>?)',
            [BlockedCompressedMetadataContainer::FORMAT_VERSION]
        );
        $mismatched = $this->scalar(
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
            . 'WHERE f.scan_status="verified" AND ('
            . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)',
            [BlockedCompressedMetadataContainer::FORMAT_VERSION]
        );
        $nonterminalJobs = $this->nonterminalJobs();
        $legacyRows = [];
        if ($includeLegacyCounts) {
            foreach (self::LEGACY_TABLES as $table) {
                $legacyRows[$table] = $this->scalar(
                    'SELECT COUNT(*) FROM ' . $table . ' l '
                    . 'JOIN ue_files f ON f.id=l.file_id WHERE f.scan_status="verified"'
                );
            }
        }

        return [
            'verified_without_format2' => $missing,
            'verified_count_mismatches' => $mismatched,
            'verified_legacy_rows' => $legacyRows,
            'verified_legacy_rows_total' => array_sum($legacyRows),
            'nonterminal_background_jobs' => $nonterminalJobs,
            'conversion_complete' => $missing === 0 && $mismatched === 0,
            'cleanup_ready' => $missing === 0 && $mismatched === 0 && $nonterminalJobs === 0,
        ];
    }

    /** @return array<string,mixed> */
    public function convert(
        int $batchSize = 25,
        int $maxFiles = 0,
        bool $continueOnError = false,
        ?callable $progress = null
    ): array {
        $batchSize = max(1, min(500, $batchSize));
        $maxFiles = max(0, $maxFiles);
        $this->assertIdle();
        $this->acquireLock();

        $attempted = 0;
        $converted = 0;
        $alreadyCurrent = 0;
        $failures = [];
        $cursor = 0;
        $startedAt = microtime(true);

        try {
            while ($maxFiles === 0 || $attempted < $maxFiles) {
                $remainingCapacity = $maxFiles === 0 ? $batchSize : min($batchSize, $maxFiles - $attempted);
                if ($remainingCapacity < 1) {
                    break;
                }
                $files = $this->missingFormat2Files($cursor, $remainingCapacity);
                if ($files === []) {
                    break;
                }

                foreach ($files as $file) {
                    $fileId = (int)$file['id'];
                    $cursor = max($cursor, $fileId);
                    $attempted++;
                    $this->emit($progress, [
                        'phase' => 'convert',
                        'file_id' => $fileId,
                        'attempted' => $attempted,
                        'message' => 'Converting verified file #' . $fileId . ' to format-2 metadata',
                    ]);

                    try {
                        $result = $this->converter->convert($fileId);
                        $this->assertCurrentMetadata($fileId);
                        if (!empty($result['already_converted'])) {
                            $alreadyCurrent++;
                        } else {
                            $converted++;
                        }
                    } catch (Throwable $error) {
                        $failure = [
                            'file_id' => $fileId,
                            'original_name' => (string)($file['original_name'] ?? ''),
                            'error' => $error->getMessage(),
                        ];
                        $failures[] = $failure;
                        $this->emit($progress, [
                            'phase' => 'convert',
                            'file_id' => $fileId,
                            'failed' => true,
                            'message' => 'Conversion failed for file #' . $fileId . ': ' . $error->getMessage(),
                        ]);
                        if (!$continueOnError) {
                            throw new RuntimeException(
                                'Conversion failed for verified file #' . $fileId . ': ' . $error->getMessage(),
                                0,
                                $error
                            );
                        }
                    }
                }
            }
        } finally {
            $this->releaseLock();
        }

        $status = $this->status(false);
        return [
            'phase' => 'convert',
            'attempted' => $attempted,
            'converted' => $converted,
            'already_current' => $alreadyCurrent,
            'failed' => count($failures),
            'failures' => $failures,
            'last_file_id' => $cursor,
            'remaining_verified_without_format2' => (int)$status['verified_without_format2'],
            'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
            'complete' => (int)$status['verified_without_format2'] === 0 && $failures === [],
        ];
    }

    /**
     * Delete legacy rows only for verified files whose current format-2 container
     * and registration have been re-verified immediately before deletion.
     *
     * @return array<string,mixed>
     */
    public function cleanup(
        int $batchSize = 10,
        int $maxFiles = 0,
        bool $continueOnError = false,
        ?callable $progress = null
    ): array {
        $batchSize = max(1, min(100, $batchSize));
        $maxFiles = max(0, $maxFiles);
        $this->assertIdle();

        $preflight = $this->status(false);
        if ((int)$preflight['verified_without_format2'] !== 0) {
            throw new RuntimeException(
                'Cleanup is blocked: ' . (int)$preflight['verified_without_format2']
                . ' verified file(s) do not yet have format-2 metadata.'
            );
        }
        if ((int)$preflight['verified_count_mismatches'] !== 0) {
            throw new RuntimeException(
                'Cleanup is blocked: ' . (int)$preflight['verified_count_mismatches']
                . ' verified format-2 registration count mismatch(es) remain.'
            );
        }

        $this->acquireLock();
        $attempted = 0;
        $cleaned = 0;
        $removed = array_fill_keys(self::LEGACY_TABLES, 0);
        $failures = [];
        $cursor = 0;
        $startedAt = microtime(true);

        try {
            while ($maxFiles === 0 || $attempted < $maxFiles) {
                $remainingCapacity = $maxFiles === 0 ? $batchSize : min($batchSize, $maxFiles - $attempted);
                if ($remainingCapacity < 1) {
                    break;
                }
                $fileIds = $this->cleanupCandidates($cursor, $remainingCapacity);
                if ($fileIds === []) {
                    break;
                }

                foreach ($fileIds as $fileId) {
                    $cursor = max($cursor, $fileId);
                    $attempted++;
                    $this->emit($progress, [
                        'phase' => 'cleanup',
                        'file_id' => $fileId,
                        'attempted' => $attempted,
                        'message' => 'Re-verifying compact metadata before legacy cleanup for file #' . $fileId,
                    ]);
                    try {
                        $this->assertCurrentMetadata($fileId);
                        $this->converter->verify($fileId);
                        $fileRemoved = $this->cleanupFile($fileId);
                        foreach ($fileRemoved as $table => $count) {
                            $removed[$table] += $count;
                        }
                        $cleaned++;
                    } catch (Throwable $error) {
                        $failures[] = ['file_id' => $fileId, 'error' => $error->getMessage()];
                        $this->emit($progress, [
                            'phase' => 'cleanup',
                            'file_id' => $fileId,
                            'failed' => true,
                            'message' => 'Cleanup failed for file #' . $fileId . ': ' . $error->getMessage(),
                        ]);
                        if (!$continueOnError) {
                            throw new RuntimeException(
                                'Legacy cleanup failed for verified file #' . $fileId . ': ' . $error->getMessage(),
                                0,
                                $error
                            );
                        }
                    }
                }
            }
        } finally {
            $this->releaseLock();
        }

        $remainingCandidates = $this->cleanupCandidates(0, 1) !== [];
        return [
            'phase' => 'cleanup',
            'attempted' => $attempted,
            'cleaned' => $cleaned,
            'failed' => count($failures),
            'failures' => $failures,
            'removed_rows' => $removed,
            'removed_rows_total' => array_sum($removed),
            'last_file_id' => $cursor,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
            'complete' => !$remainingCandidates && $failures === [],
        ];
    }

    /** @return list<array{id:int,original_name:string}> */
    private function missingFormat2Files(int $afterId, int $limit): array
    {
        $statement = $this->db->prepare(
            'SELECT f.id,f.original_name FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" AND f.id>? '
            . 'AND (m.file_id IS NULL OR m.format_version<>?) '
            . 'ORDER BY f.id LIMIT ' . max(1, $limit)
        );
        $statement->execute([$afterId, BlockedCompressedMetadataContainer::FORMAT_VERSION]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<int> */
    private function cleanupCandidates(int $afterId, int $limit): array
    {
        $statement = $this->db->prepare(
            'SELECT f.id FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
            . 'WHERE f.scan_status="verified" AND f.id>? AND ('
            . 'EXISTS(SELECT 1 FROM ue_dependencies d WHERE d.file_id=f.id) OR '
            . 'EXISTS(SELECT 1 FROM ue_imports i WHERE i.file_id=f.id) OR '
            . 'EXISTS(SELECT 1 FROM ue_exports e WHERE e.file_id=f.id) OR '
            . 'EXISTS(SELECT 1 FROM ue_names n WHERE n.file_id=f.id)) '
            . 'ORDER BY f.id LIMIT ' . max(1, $limit)
        );
        $statement->execute([BlockedCompressedMetadataContainer::FORMAT_VERSION, $afterId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<string,int> */
    private function cleanupFile(int $fileId): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact metadata cutover cleanup requires ownership of the transaction.');
        }

        $removed = array_fill_keys(self::LEGACY_TABLES, 0);
        $this->db->beginTransaction();
        try {
            $guard = $this->db->prepare(
                'SELECT f.id FROM ue_files f '
                . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
                . 'WHERE f.id=? AND f.scan_status="verified" FOR UPDATE'
            );
            $guard->execute([BlockedCompressedMetadataContainer::FORMAT_VERSION, $fileId]);
            if ((int)($guard->fetchColumn() ?: 0) !== $fileId) {
                throw new RuntimeException(
                    'Verified format-2 cleanup guard changed before deletion for file #' . $fileId . '.'
                );
            }

            foreach (self::LEGACY_TABLES as $table) {
                $statement = $this->db->prepare('DELETE FROM ' . $table . ' WHERE file_id=?');
                $statement->execute([$fileId]);
                $removed[$table] = $statement->rowCount();
            }

            foreach (self::LEGACY_TABLES as $table) {
                if ($this->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE file_id=?', [$fileId]) !== 0) {
                    throw new RuntimeException('Legacy rows remain in ' . $table . ' for file #' . $fileId . '.');
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $removed;
    }

    private function assertCurrentMetadata(int $fileId): void
    {
        $statement = $this->db->prepare(
            'SELECT f.name_count,f.import_count,f.export_count,'
            . 'm.format_version,m.name_count metadata_name_count,'
            . 'm.import_count metadata_import_count,m.export_count metadata_export_count '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Verified file #' . $fileId . ' has no compact metadata registration.');
        }
        if ((int)$row['format_version'] !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('Verified file #' . $fileId . ' is not format-2 metadata.');
        }
        foreach (['name', 'import', 'export'] as $type) {
            if ((int)$row[$type . '_count'] !== (int)$row['metadata_' . $type . '_count']) {
                throw new RuntimeException(
                    'Verified file #' . $fileId . ' has a ' . $type . ' count mismatch between ue_files and ue_file_metadata.'
                );
            }
        }
    }

    private function assertIdle(): void
    {
        $jobs = $this->nonterminalJobs();
        if ($jobs !== 0) {
            throw new RuntimeException(
                'Compact metadata cutover requires an idle durable-job queue; ' . $jobs . ' queued/running job(s) remain.'
            );
        }
    }

    private function nonterminalJobs(): int
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status IN ("queued","running")'
        );
    }

    private function acquireLock(): void
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $statement->execute([self::LOCK_NAME]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another compact metadata cutover operation already holds the maintenance lock.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([self::LOCK_NAME]);
        } catch (Throwable) {
            // The database connection closing also releases the advisory lock.
        }
    }

    /** @param list<mixed> $args */
    private function scalar(string $sql, array $args = []): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return (int)($statement->fetchColumn() ?: 0);
    }

    /** @param array<string,mixed> $event */
    private function emit(?callable $progress, array $event): void
    {
        if ($progress !== null) {
            $progress($event);
        }
    }
}
