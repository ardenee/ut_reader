<?php
/**
 * Purpose: Verifies format-2 metadata containers and rebuilds their current MySQL projections.
 * Why: Historical SQL-to-compact conversion is complete; maintenance must now operate only from authoritative format-2 containers.
 * Role: Current compact metadata verification/projection-maintenance infrastructure. The historical class name is retained for caller compatibility.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class BlockedCompressedFileMetadataConverter
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compact metadata maintenance.');
        }
    }

    /**
     * Compatibility method for callers that previously requested conversion.
     * Historical conversion is intentionally retired; current files are verified only.
     *
     * @return array<string,mixed>
     */
    public function convert(int $fileId): array
    {
        $row = $this->requireCurrentMetadata($fileId);
        return array_merge($this->verify($fileId), [
            'already_converted' => true,
            'upgraded_from_version' => null,
            'legacy_rows_deleted' => false,
            'format_version' => (int)$row['format_version'],
        ]);
    }

    /**
     * Rebuild only compact MySQL projections for an existing version-2 container.
     * The source snapshot is loaded from the current container.
     *
     * @return array<string,mixed>
     */
    public function rebuildProjections(int $fileId): array
    {
        $existing = $this->requireCurrentMetadata($fileId);
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($this->db, $this->storageRoot))->load($fileId);
        $file = (array)$snapshot['file'];
        $path = BlockedCompressedMetadataContainer::path(
            $this->storageRoot,
            (int)$file['game_id'],
            $fileId
        );
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('Could not read blocked metadata container: ' . $path);
        }
        if (strlen($bytes) !== (int)$existing['compressed_size']) {
            throw new RuntimeException('Blocked metadata container size does not match ue_file_metadata.');
        }
        if (!hash_equals((string)$existing['payload_sha256'], hash('sha256', $bytes, true))) {
            throw new RuntimeException('Blocked metadata container SHA-256 does not match ue_file_metadata.');
        }
        BlockedCompressedMetadataContainer::verifyBytes($bytes, $fileId);

        // Shared dictionary rows are append-mostly global state. Resolve once
        // before the file-owned projection transaction and reuse the stable IDs
        // instead of rebuilding the unique term map inside the transaction.
        $sqlBatches = 0;
        $lookupWriter = new CompressedMetadataLookupWriter($this->db);
        $resolvedTermIds = $lookupWriter->primeSnapshotTerms($snapshot, $sqlBatches);
        (new CompactTermOverflowWriter($this->db))->write($snapshot, $sqlBatches);

        $this->db->beginTransaction();
        try {
            $lookupWriter->writeVersioned(
                $snapshot,
                $bytes,
                (int)$existing['uncompressed_size'],
                BlockedCompressedMetadataContainer::FORMAT_VERSION,
                BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP,
                $sqlBatches,
                $resolvedTermIds
            );
            (new CompactSearchProjectionWriter($this->db))->write($snapshot, $sqlBatches);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return array_merge($this->verify($fileId), [
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'dependency_count' => count((array)$snapshot['dependencies']),
            'sql_batches' => $sqlBatches,
            'projection_rebuilt' => true,
            'container_rewritten' => false,
            'legacy_rows_deleted' => false,
        ]);
    }

    /** @return array<string,mixed> */
    public function verify(int $fileId): array
    {
        $this->requireCurrentMetadata($fileId);
        return (new BlockedCompressedMetadataReader($this->db, $this->storageRoot))->verify($fileId);
    }

    /** @return array<string,mixed> */
    private function requireCurrentMetadata(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        $this->assertSchema();
        $row = $this->metadataRow($fileId);
        if (!is_array($row)) {
            throw new RuntimeException(
                'File #' . $fileId . ' has no current metadata registration. Historical SQL metadata conversion has been retired.'
            );
        }
        if ((int)$row['format_version'] !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException(
                'File #' . $fileId . ' uses metadata format version ' . (int)$row['format_version']
                . '; only authoritative format-' . BlockedCompressedMetadataContainer::FORMAT_VERSION
                . ' metadata is supported after legacy table retirement.'
            );
        }
        if ((int)$row['codec'] !== BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP) {
            throw new RuntimeException('File #' . $fileId . ' uses an unsupported metadata codec.');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function metadataRow(int $fileId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM ue_file_metadata WHERE file_id=?');
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertSchema(): void
    {
        $tables = ['ue_file_metadata', 'ue_terms', 'ue_export_lookup', 'ue_dependency_links'];
        $statement = $this->db->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($tables), '?')) . ')'
        );
        $statement->execute($tables);
        $actual = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        $missing = array_values(array_filter($tables, static fn(string $table): bool => empty($actual[$table])));
        if ($missing !== []) {
            throw new RuntimeException('Compact metadata schema is incomplete: ' . implode(', ', $missing) . '.');
        }
    }
}
