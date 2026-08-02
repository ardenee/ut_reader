<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

/** Converts legacy SQL metadata into the version-2 random-access container. */
final class BlockedCompressedFileMetadataConverter
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for blocked metadata conversion.');
        }
    }

    /** @return array<string,mixed> */
    public function convert(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        $this->assertSchema();
        $existing = $this->metadataRow($fileId);
        if (is_array($existing) && (int)$existing['format_version'] >= BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            return array_merge($this->verify($fileId), [
                'already_converted' => true,
                'upgraded_from_version' => null,
                'legacy_rows_deleted' => false,
            ]);
        }

        $snapshot = (new CompressedMetadataLegacySnapshot($this->db))->capture($fileId);
        $built = BlockedCompressedMetadataContainer::build($snapshot);
        $bytes = (string)$built['bytes'];
        $file = (array)$snapshot['file'];
        $path = BlockedCompressedMetadataContainer::path(
            $this->storageRoot,
            (int)$file['game_id'],
            $fileId
        );
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create blocked metadata directory: ' . $directory);
        }
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not replace orphan blocked metadata file: ' . $path);
        }

        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
        $written = file_put_contents($temporaryPath, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not completely write blocked metadata file: ' . $temporaryPath);
        }
        try {
            $temporaryBytes = file_get_contents($temporaryPath);
            if (!is_string($temporaryBytes)) {
                throw new RuntimeException('Could not read back the blocked metadata temporary file.');
            }
            BlockedCompressedMetadataContainer::verifyBytes($temporaryBytes, $fileId);
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not publish blocked metadata file: ' . $path);
            }
        } catch (Throwable $error) {
            @unlink($temporaryPath);
            throw $error;
        }

        $sqlBatches = 0;
        $this->db->beginTransaction();
        try {
            (new CompressedMetadataLookupWriter($this->db))->writeVersioned(
                $snapshot,
                $bytes,
                (int)$built['uncompressed_size'],
                BlockedCompressedMetadataContainer::FORMAT_VERSION,
                BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP,
                $sqlBatches
            );
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($path);
            throw $error;
        }

        $upgradedFrom = is_array($existing) ? (int)$existing['format_version'] : null;
        if ($upgradedFrom === 1) {
            $legacyPath = BatchedCompressedFileMetadataConverter::metadataPath(
                $this->storageRoot,
                (int)$file['game_id'],
                $fileId
            );
            if (is_file($legacyPath)) {
                @unlink($legacyPath);
            }
        }

        return array_merge($this->verify($fileId), [
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'dependency_count' => count((array)$snapshot['dependencies']),
            'block_size' => BlockedCompressedMetadataContainer::DEFAULT_BLOCK_SIZE,
            'sql_batches' => $sqlBatches,
            'already_converted' => false,
            'upgraded_from_version' => $upgradedFrom,
            'legacy_rows_deleted' => false,
        ]);
    }

    /** @return array<string,mixed> */
    public function verify(int $fileId): array
    {
        $row = $this->metadataRow($fileId);
        if (!is_array($row)) {
            throw new RuntimeException('File #' . $fileId . ' has no compressed metadata row.');
        }
        if ((int)$row['format_version'] === 1) {
            return (new BatchedCompressedFileMetadataConverter($this->db, $this->storageRoot))->verify($fileId);
        }
        return (new BlockedCompressedMetadataReader($this->db, $this->storageRoot))->verify($fileId);
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
