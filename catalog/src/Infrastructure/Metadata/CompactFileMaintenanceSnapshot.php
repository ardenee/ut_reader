<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CompactFileMaintenanceSnapshot` for compact file maintenance snapshot.
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

/**
 * Captures and restores a verified file without reading legacy metadata tables.
 *
 * The relational file/location/alias rows are retained exactly. Names, Imports,
 * Exports and dependencies are loaded from the format-2 container and restored
 * through the compact snapshot writer.
 */
final class CompactFileMaintenanceSnapshot
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot
    ) {
        if (trim($storageRoot) === '') {
            throw new RuntimeException('A catalog storage path is required for compact maintenance snapshots.');
        }
    }

    /** @return array<string,mixed> */
    public function capture(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }

        $file = $this->one('SELECT * FROM ue_files WHERE id=?', [$fileId]);
        if ($file === null) {
            throw new RuntimeException('File #' . $fileId . ' was not found.');
        }
        if ((string)($file['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('File #' . $fileId . ' is not verified.');
        }

        $metadata = (new BlockedCompressedMetadataSnapshotLoader(
            $this->db,
            $this->storageRoot
        ))->load($fileId);

        $registration = $this->one(
            'SELECT format_version,codec,compressed_size,uncompressed_size,payload_sha256,'
            . 'name_count,import_count,export_count FROM ue_file_metadata WHERE file_id=?',
            [$fileId]
        );
        if ($registration === null || (int)$registration['format_version'] !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('File #' . $fileId . ' has no valid format-2 registration.');
        }

        return [
            'format' => 'unrealdb.compact-maintenance-snapshot',
            'format_version' => 1,
            'file' => $file,
            'metadata' => $metadata,
            'registration' => $registration,
            'locations' => $this->rows(
                'SELECT * FROM ue_file_locations WHERE file_id=? ORDER BY id',
                [$fileId]
            ),
            'aliases' => $this->tableExists('ue_file_package_aliases')
                ? $this->rows(
                    'SELECT * FROM ue_file_package_aliases WHERE file_id=? ORDER BY id',
                    [$fileId]
                )
                : [],
            'captured_at' => gmdate('c'),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function restore(array $snapshot): array
    {
        $this->assertSnapshot($snapshot);
        $file = (array)$snapshot['file'];
        $fileId = (int)$file['id'];

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

        try {
            $written = (new BlockedCompressedMetadataSnapshotWriter(
                $this->db,
                $this->storageRoot
            ))->write((array)$snapshot['metadata']);
        } catch (Throwable $error) {
            try {
                $this->db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
            } catch (Throwable $cleanupError) {
                error_log(
                    '[UnrealDB compact maintenance restore] file_id=' . $fileId
                    . ' cleanup failed: ' . $cleanupError->getMessage()
                );
            }
            throw new RuntimeException(
                'Could not restore compact metadata for file #' . $fileId . ': ' . $error->getMessage(),
                0,
                $error
            );
        }

        return array_merge($written, [
            'restored' => true,
            'file_id' => $fileId,
            'locations_restored' => count((array)$snapshot['locations']),
            'aliases_restored' => count((array)$snapshot['aliases']),
            'legacy_metadata_rows_restored' => 0,
            'compact_native' => true,
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(array $snapshot): void
    {
        if ((string)($snapshot['format'] ?? '') !== 'unrealdb.compact-maintenance-snapshot') {
            throw new RuntimeException('Unsupported compact maintenance snapshot format.');
        }
        if ((int)($snapshot['format_version'] ?? 0) !== 1) {
            throw new RuntimeException('Unsupported compact maintenance snapshot version.');
        }
        $file = (array)($snapshot['file'] ?? []);
        $metadata = (array)($snapshot['metadata'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1 || (int)($metadata['file']['id'] ?? 0) !== $fileId) {
            throw new RuntimeException('Compact maintenance snapshot identity mismatch.');
        }
        foreach (['names', 'imports', 'exports', 'dependencies', 'paths'] as $section) {
            if (!array_key_exists($section, $metadata)) {
                throw new RuntimeException('Compact maintenance snapshot is missing ' . $section . '.');
            }
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
            throw new RuntimeException('Invalid compact maintenance restore row.');
        }
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', (string)$column) !== 1) {
                throw new RuntimeException('Invalid compact maintenance restore column.');
            }
        }
        $quoted = implode(',', array_map(
            static fn(string $column): string => '`' . $column . '`',
            array_map('strval', $columns)
        ));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $statement = $this->db->prepare(
            'INSERT INTO `' . $table . '` (' . $quoted . ') VALUES (' . $placeholders . ')'
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
