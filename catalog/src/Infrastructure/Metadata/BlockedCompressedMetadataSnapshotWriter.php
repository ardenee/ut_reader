<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

/** Publishes a complete format-2 snapshot and its MySQL projections atomically. */
final class BlockedCompressedMetadataSnapshotWriter
{
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
        $built = BlockedCompressedMetadataContainer::build($snapshot);
        $bytes = (string)$built['bytes'];
        $path = BlockedCompressedMetadataContainer::path($this->storageRoot, $gameId, $fileId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create compact metadata directory: ' . $directory);
        }

        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        $backupPath = $path . '.bak.' . bin2hex(random_bytes(8));
        $written = file_put_contents($temporaryPath, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not completely write compact metadata temporary file.');
        }
        $temporaryBytes = file_get_contents($temporaryPath);
        if (!is_string($temporaryBytes)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not read back compact metadata temporary file.');
        }
        BlockedCompressedMetadataContainer::verifyBytes($temporaryBytes, $fileId);

        $hadExistingFile = is_file($path);
        $published = false;
        $backedUp = false;
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
            (new CompactSearchProjectionWriter($this->db))->write($snapshot, $sqlBatches);

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
            @unlink($temporaryPath);
            throw $error;
        }

        if ($backedUp && is_file($backupPath)) {
            @unlink($backupPath);
        }

        $verified = (new BlockedCompressedMetadataReader($this->db, $this->storageRoot))->verify($fileId);
        return array_merge($verified, [
            'sql_batches' => $sqlBatches,
            'container_rewritten' => true,
            'dependency_count' => count((array)($snapshot['dependencies'] ?? [])),
        ]);
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
