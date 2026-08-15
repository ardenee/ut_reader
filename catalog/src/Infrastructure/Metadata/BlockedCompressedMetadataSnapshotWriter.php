<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `BlockedCompressedMetadataSnapshotWriter` for blocked compressed metadata
 *          snapshot writer.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

/** Publishes a complete format-2 snapshot and its MySQL projections atomically. */
final class BlockedCompressedMetadataSnapshotWriter
{
    private const CONTENTION_ATTEMPTS = 5;

    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compact metadata writing.');
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function write(array $snapshot): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact metadata snapshot writing requires ownership of the database transaction.');
        }

        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Compact metadata snapshot has no valid file or game identity.');
        }

        $this->assertSnapshotCounts($snapshot);
        $path = BlockedCompressedMetadataContainer::path($this->storageRoot, $gameId, $fileId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create compact metadata directory: ' . $directory);
        }
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        // ue_terms is shared by every file. Resolve it once before the file-owned
        // transaction, then reuse those stable IDs for every contention retry and
        // for both lookup/search projections.
        $dictionarySqlBatches = 0;
        $lookupWriter = new CompressedMetadataLookupWriter($this->db);
        $resolvedTermIds = $lookupWriter->primeSnapshotTerms($snapshot, $dictionarySqlBatches);
        (new CompactTermOverflowWriter($this->db))->write($snapshot, $dictionarySqlBatches);

        $built = null;
        try {
            for ($attempt = 1; ; $attempt++) {
                clearstatcache(true, $temporaryPath);
                if (!is_array($built) || !is_file($temporaryPath)) {
                    // If a prior attempt failed before rename, the verified file
                    // and its build metadata are reused. If it reached rename and
                    // then rolled back its DB transaction, the temp path no longer
                    // exists and is rebuilt here.
                    $built = BlockedCompressedMetadataContainer::buildToFile(
                        $snapshot,
                        $temporaryPath
                    );
                }

                try {
                    return $this->publishAttempt(
                        $snapshot,
                        $built,
                        $temporaryPath,
                        $path,
                        $fileId,
                        $dictionarySqlBatches,
                        $lookupWriter,
                        $resolvedTermIds
                    );
                } catch (Throwable $error) {
                    if (!PdoContention::retryable($error) || $attempt >= self::CONTENTION_ATTEMPTS) {
                        throw $error;
                    }
                    usleep(PdoContention::backoffMicros($attempt, 25000));
                }
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $built
     * @param array<string,int> $resolvedTermIds
     * @return array<string,mixed>
     */
    private function publishAttempt(
        array $snapshot,
        array $built,
        string $temporaryPath,
        string $path,
        int $fileId,
        int $dictionarySqlBatches,
        CompressedMetadataLookupWriter $lookupWriter,
        array $resolvedTermIds
    ): array {
        $backupPath = $path . '.bak.' . bin2hex(random_bytes(8));
        $compressedSize = (int)($built['compressed_size'] ?? 0);
        $payloadSha256 = (string)($built['payload_sha256'] ?? '');
        $uncompressedSize = (int)($built['uncompressed_size'] ?? 0);
        $blockCount = (int)($built['block_count'] ?? 0);
        if ($compressedSize < 1 || strlen($payloadSha256) !== 32 || $uncompressedSize < 1) {
            throw new RuntimeException('Streamed compact metadata build returned incomplete publication metadata.');
        }

        clearstatcache(true, $path);
        $hadExistingFile = is_file($path);
        $published = false;
        $backedUp = false;
        $sqlBatches = $dictionarySqlBatches;

        $this->db->beginTransaction();
        try {
            $lookupWriter->writeVersionedMetadata(
                $snapshot,
                $compressedSize,
                $payloadSha256,
                $uncompressedSize,
                BlockedCompressedMetadataContainer::FORMAT_VERSION,
                BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP,
                $sqlBatches,
                $resolvedTermIds
            );
            (new CompactSearchProjectionWriter($this->db))->write(
                $snapshot,
                $sqlBatches,
                $resolvedTermIds
            );

            if ($hadExistingFile) {
                if (!rename($path, $backupPath)) {
                    throw new RuntimeException('Could not stage the existing compact metadata file for replacement.');
                }
                $backedUp = true;
            }
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not publish replacement compact metadata file.');
            }
            $published = true;
            clearstatcache(true, $path);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($published && is_file($path)) {
                @unlink($path);
            }
            if ($backedUp && is_file($backupPath)) {
                @rename($backupPath, $path);
            }
            clearstatcache(true, $path);
            throw $error;
        }

        if ($backedUp && is_file($backupPath)) {
            @unlink($backupPath);
        }

        // buildToFile() verified the exact temp file before rename. The atomic
        // rename cannot alter bytes, and the committed DB row contains the same
        // size/SHA, so a second full read/decompress pass adds I/O but no stronger
        // publication guarantee.
        return [
            'verified' => true,
            'file_id' => $fileId,
            'metadata_path' => $path,
            'compressed_size' => $compressedSize,
            'uncompressed_size' => $uncompressedSize,
            'name_count' => count((array)($snapshot['names'] ?? [])),
            'import_count' => count((array)($snapshot['imports'] ?? [])),
            'export_count' => count((array)($snapshot['exports'] ?? [])),
            'block_count' => $blockCount,
            'format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
            'sql_batches' => $sqlBatches,
            'container_rewritten' => true,
            'dependency_count' => count((array)($snapshot['dependencies'] ?? [])),
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshotCounts(array $snapshot): void
    {
        $file = (array)($snapshot['file'] ?? []);
        $expected = [
            'names' => (int)($file['name_count'] ?? -1),
            'imports' => (int)($file['import_count'] ?? -1),
            'exports' => (int)($file['export_count'] ?? -1),
            'dependencies' => (int)($file['import_count'] ?? -1),
        ];
        foreach ($expected as $section => $count) {
            $actual = count((array)($snapshot[$section] ?? []));
            if ($count < 0 || $actual !== $count) {
                throw new RuntimeException(
                    'Compact snapshot ' . $section . ' count mismatch: expected '
                    . $count . ', found ' . $actual . '.'
                );
            }
        }

        $paths = (array)($snapshot['paths'] ?? []);
        if (count((array)($paths['imports'] ?? [])) !== $expected['imports']) {
            throw new RuntimeException('Compact snapshot Import path count mismatch.');
        }
        if (count((array)($paths['exports'] ?? [])) !== $expected['exports']) {
            throw new RuntimeException('Compact snapshot Export path count mismatch.');
        }
    }
}
