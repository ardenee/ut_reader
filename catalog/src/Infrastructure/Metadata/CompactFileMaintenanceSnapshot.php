<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

/** Captures and restores one verified file using authoritative UEDB5 metadata. */
final class CompactFileMaintenanceSnapshot
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for UEDB5 maintenance snapshots.');
        }
    }

    /** @return array<string,mixed> */
    public function capture(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        $file = $this->one('SELECT * FROM ue_files WHERE id=?', [$fileId]);
        if ($file === null || (string)($file['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('File #' . $fileId . ' is not an active verified file.');
        }
        $gameId = (int)($file['game_id'] ?? 0);
        if ($gameId < 1) {
            throw new RuntimeException('File #' . $fileId . ' has no game identity.');
        }

        $metadata = (new Uedb5MetadataReader($this->storageRoot))->snapshot($gameId, $fileId);
        $registration = $this->one(
            'SELECT file_id,game_id,format_version,codec,compressed_size,uncompressed_size,'
            . 'payload_sha256,block_count,package_family,source_policy,package_key_kind,package_key,'
            . 'package_name,section_counts_json FROM ue_uedb5_files WHERE file_id=? AND game_id=?',
            [$fileId, $gameId]
        );
        if ($registration === null
            || (int)$registration['format_version'] !== Uedb5MetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('File #' . $fileId . ' has no valid UEDB5 registration.');
        }

        return [
            'format' => 'unrealdb.uedb5-maintenance-snapshot',
            'format_version' => 2,
            'file' => $file,
            'metadata' => $metadata,
            'registration' => $registration,
            'locations' => $this->rows('SELECT * FROM ue_file_locations WHERE file_id=? ORDER BY id', [$fileId]),
            'aliases' => $this->tableExists('ue_file_package_aliases')
                ? $this->rows('SELECT * FROM ue_file_package_aliases WHERE file_id=? ORDER BY id', [$fileId])
                : [],
            'captured_at' => gmdate('c'),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function restore(array $snapshot): array
    {
        $this->assertSnapshot($snapshot);
        $file = (array)$snapshot['file'];
        $metadata = (array)$snapshot['metadata'];
        $fileId = (int)$file['id'];
        $gameId = (int)$file['game_id'];

        if ($this->one('SELECT id FROM ue_files WHERE id=?', [$fileId]) !== null) {
            throw new RuntimeException('Refusing to restore file #' . $fileId . ' because it already exists.');
        }

        $this->db->beginTransaction();
        try {
            $this->insertExact('ue_files', $file);
            foreach ((array)$snapshot['locations'] as $row) {
                if (is_array($row)) {
                    $this->insertExact('ue_file_locations', $row);
                }
            }
            if ($this->tableExists('ue_file_package_aliases')) {
                foreach ((array)$snapshot['aliases'] as $row) {
                    if (is_array($row)) {
                        $this->insertExact('ue_file_package_aliases', $row);
                    }
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $path = Uedb5MetadataContainer::path($this->storageRoot, $gameId, $fileId);
        try {
            $written = (new Uedb5MetadataSnapshotWriter($this->storageRoot))->write($metadata);
            $registration = (new PdoUedb5StagingRegistrationRepository(
                $this->db,
                $this->storageRoot
            ))->register($gameId, $fileId);
            $projection = (new PdoUedb5BaseProjectionPublisher($this->db))
                ->publish($metadata, $registration);
            (new PdoUedb5MigrationStatusRepository($this->db))->markStageSucceeded($fileId, $gameId);
            $dependency = (new Uedb5GameDependencyPassService(
                $this->db,
                ['storage_path' => $this->storageRoot]
            ))->runFile($gameId, $fileId, true, true);
        } catch (Throwable $error) {
            try {
                $this->db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
            } catch (Throwable $cleanupError) {
                error_log('[UnrealDB UEDB5 maintenance restore] file_id=' . $fileId
                    . ' cleanup failed: ' . $cleanupError->getMessage());
            }
            if (is_file($path)) {
                @unlink($path);
            }
            throw new RuntimeException(
                'Could not restore UEDB5 metadata for file #' . $fileId . ': ' . $error->getMessage(),
                0,
                $error
            );
        }

        return [
            'verified' => true,
            'format_version' => Uedb5MetadataContainer::FORMAT_VERSION,
            'metadata_path' => (string)($written['path'] ?? $path),
            'compressed_size' => (int)($written['compressed_size'] ?? 0),
            'uncompressed_size' => (int)($written['uncompressed_size'] ?? 0),
            'block_count' => (int)($written['block_count'] ?? 0),
            'restored' => true,
            'file_id' => $fileId,
            'locations_restored' => count((array)$snapshot['locations']),
            'aliases_restored' => count((array)$snapshot['aliases']),
            'base_projection' => $projection,
            'dependency_result' => (array)($dependency['result'] ?? []),
            'legacy_metadata_rows_restored' => 0,
            'compact_native' => true,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(array $snapshot): void
    {
        if ((string)($snapshot['format'] ?? '') !== 'unrealdb.uedb5-maintenance-snapshot'
            || (int)($snapshot['format_version'] ?? 0) !== 2) {
            throw new RuntimeException('Unsupported UEDB5 maintenance snapshot format.');
        }
        $file = (array)($snapshot['file'] ?? []);
        $metadata = (array)($snapshot['metadata'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1 || (int)($metadata['file']['id'] ?? 0) !== $fileId) {
            throw new RuntimeException('UEDB5 maintenance snapshot identity mismatch.');
        }
        if ((int)($file['game_id'] ?? 0) < 1
            || (int)($metadata['file']['game_id'] ?? 0) !== (int)$file['game_id']) {
            throw new RuntimeException('UEDB5 maintenance snapshot game identity mismatch.');
        }
        if (!is_array($metadata['sections'] ?? null) || !is_array($metadata['section_schemas'] ?? null)) {
            throw new RuntimeException('UEDB5 maintenance snapshot is missing source-shaped sections.');
        }
    }

    /** @param list<mixed> $arguments @return array<string,mixed>|null */
    private function one(string $sql, array $arguments = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<mixed> $arguments @return list<array<string,mixed>> */
    private function rows(string $sql, array $arguments = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $row */
    private function insertExact(string $table, array $row): void
    {
        if ($row === [] || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('Invalid UEDB5 maintenance restore row.');
        }
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string)$column) !== 1) {
                throw new RuntimeException('Invalid UEDB5 maintenance restore column.');
            }
        }
        $columnSql = implode(',', array_map('strval', $columns));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $statement = $this->db->prepare(
            'INSERT INTO ' . $table . ' (' . $columnSql . ') VALUES (' . $placeholders . ')'
        );
        $statement->execute(array_values($row));
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }
}
