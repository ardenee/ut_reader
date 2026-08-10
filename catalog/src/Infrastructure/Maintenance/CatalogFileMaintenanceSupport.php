<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns shared compact file-maintenance storage, snapshot and dependency-refresh primitives.
 * Why: Reimport, removal, data audit and maintenance actions should reuse one namespaced implementation rather than
 *      procedural filesystem/compact/dependency helpers under catalog/lib.
 * Role: Infrastructure maintenance support preserving the established compact-maintenance behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactFileMaintenanceSnapshot;

final class CatalogFileMaintenanceSupport
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogScanner.php';
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $file */
    public static function storagePath(array $config, array $file): ?string
    {
        $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            throw new RuntimeException('Catalog storage folder is unavailable.');
        }
        $relativePath = ltrim(str_replace('\\', '/', (string)($file['relative_path'] ?? '')), '/');
        if ($relativePath === '') {
            return null;
        }
        $catalogRoot = realpath(dirname(__DIR__, 3));
        if ($catalogRoot === false) {
            throw new RuntimeException('Catalog application folder is unavailable.');
        }
        $candidate = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($candidate)) {
            return null;
        }
        $resolved = realpath($candidate);
        $rootPrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolved === false || !str_starts_with($resolved, $rootPrefix)) {
            throw new RuntimeException('Refusing to use a file outside catalog storage.');
        }
        return $resolved;
    }

    public static function emit(?callable $progress, string $stage, int $percent, string $message): void
    {
        \scanner_emit_percent($progress, $stage, $percent, $message);
    }

    /** @param array<string,mixed> $config */
    public static function storageRoot(array $config): string
    {
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact file maintenance.');
        }
        return $storageRoot;
    }

    /** @param array<string,mixed> $config */
    public static function metadataPath(array $config, int $gameId, int $fileId): string
    {
        return BlockedCompressedMetadataContainer::path(
            self::storageRoot($config),
            $gameId,
            $fileId
        );
    }

    /** @return array<string,mixed> */
    public function snapshot(int $fileId): array
    {
        return (new CompactFileMaintenanceSnapshot(
            $this->db,
            self::storageRoot($this->config)
        ))->capture($fileId);
    }

    /** @param array<string,mixed> $snapshot */
    public function restoreSnapshot(array $snapshot): void
    {
        (new CompactFileMaintenanceSnapshot(
            $this->db,
            self::storageRoot($this->config)
        ))->restore($snapshot);
    }

    /**
     * Restore a captured verified file row and compact metadata without deleting the current ue_files identity.
     * External/Pak/federation/download relationships therefore survive a failed maintenance refresh untouched.
     *
     * @param array<string,mixed> $snapshot
     */
    public function restoreExistingSnapshot(array $snapshot): void
    {
        $file = is_array($snapshot['file'] ?? null) ? $snapshot['file'] : [];
        $metadata = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1 || (int)($metadata['file']['id'] ?? 0) !== $fileId) {
            throw new RuntimeException('Compact maintenance rollback snapshot identity is invalid.');
        }

        $current = $this->db->prepare('SELECT id FROM ue_files WHERE id=?');
        $current->execute([$fileId]);
        if ($current->fetchColumn() === false) {
            $this->restoreSnapshot($snapshot);
            return;
        }

        $columns = [];
        $values = [];
        foreach ($file as $column => $value) {
            $column = (string)$column;
            if ($column === 'id') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                throw new RuntimeException('Invalid column in compact maintenance rollback snapshot.');
            }
            $columns[] = '`' . $column . '`=?';
            $values[] = $value;
        }
        if ($columns === []) {
            throw new RuntimeException('Compact maintenance rollback snapshot has no file fields.');
        }
        $values[] = $fileId;

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE ue_files SET ' . implode(',', $columns) . ' WHERE id=?'
            );
            $statement->execute($values);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        (new BlockedCompressedMetadataSnapshotWriter(
            $this->db,
            self::storageRoot($this->config)
        ))->write($metadata);
    }

    /** Remove current lookup rows that are not protected by ue_files foreign-key cascades. */
    public function deleteFileProjections(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }
        $this->db->prepare('DELETE FROM ue_dependency_links WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_export_lookup WHERE file_id=?')->execute([$fileId]);
    }

    /** @param array<string,mixed> $snapshot */
    public static function sourceRelativePath(array $snapshot): string
    {
        $filePath = \scanner_normalize_source_relative_path(
            (string)($snapshot['file']['source_relative_path'] ?? '')
        );
        if ($filePath !== '') {
            return $filePath;
        }
        foreach ((array)($snapshot['locations'] ?? []) as $location) {
            if (!is_array($location)) {
                continue;
            }
            $path = \scanner_normalize_source_relative_path(
                (string)($location['source_relative_path'] ?? '')
            );
            if ($path !== '') {
                return $path;
            }
        }
        return '';
    }

    /** @return list<int> */
    public function affectedIds(
        int $gameId,
        int $removedFileId,
        string $packageName,
        bool $deferDependencyRefresh = false
    ): array {
        if ($deferDependencyRefresh) {
            return [];
        }

        $packageName = trim($packageName);
        $rows = \catalog_all(
            $this->db,
            'SELECT DISTINCT l.file_id FROM ue_dependency_links l '
            . 'JOIN ue_terms t ON t.id=l.required_package_term_id '
            . 'JOIN ue_files owner ON owner.id=l.file_id '
            . 'WHERE owner.game_id=? AND l.file_id<>? AND ('
            . 'l.resolved_file_id=? OR (t.value_hash=? AND t.value_length=? AND t.value_prefix=?))',
            [
                $gameId,
                $removedFileId,
                $removedFileId,
                md5($packageName, true),
                strlen($packageName),
                substr($packageName, 0, 200),
            ]
        );

        return array_map(static fn(array $row): int => (int)$row['file_id'], $rows);
    }

    /** @param list<int> $fileIds */
    public function refreshIds(
        array $fileIds,
        ?callable $progress,
        int $startPercent,
        int $endPercent,
        string $prefix
    ): void {
        $fileIds = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0
        )));
        $total = count($fileIds);
        if ($total === 0) {
            self::emit($progress, 'dependencies', $endPercent, $prefix . ': no affected packages');
            return;
        }
        foreach ($fileIds as $index => $fileId) {
            \scanner_rebuild_dependencies(
                $this->db,
                $this->config,
                $fileId,
                $progress,
                \scanner_range_percent($startPercent, $endPercent, $index, $total),
                \scanner_range_percent($startPercent, $endPercent, $index + 1, $total),
                $prefix . ' ' . ($index + 1) . '/' . $total
            );
        }
    }
}
