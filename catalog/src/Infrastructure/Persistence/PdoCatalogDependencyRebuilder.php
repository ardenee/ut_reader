<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rebuilds dependency resolution for one file, a whole game, or files affected by a provider change.
 * Why: Dependency persistence, compact/legacy branching and affected-provider lookup are infrastructure concerns.
 * Role: Primary dependency rebuild implementation used by scanner compatibility functions and durable jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogAffectedDependencyRefreshCoordinator;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyRebuilder;

final class PdoCatalogDependencyRebuilder
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function rebuild(
        int $fileId,
        ?callable $progress = null,
        int $startPercent = 0,
        int $endPercent = 100,
        string $prefix = 'Rebuilding dependencies'
    ): void {
        $statement = $this->db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
        $statement->execute([$fileId]);
        $metadata = $statement->fetch(PDO::FETCH_ASSOC);
        if ((int)($metadata['format_version'] ?? 0) >= 2) {
            if ($this->db->inTransaction()) {
                throw new RuntimeException('Compact dependency rebuilding cannot run inside an existing database transaction.');
            }
            $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
            if ($storageRoot === '') {
                throw new RuntimeException('Catalog storage_path is required for compact dependency rebuilding.');
            }
            self::emitPercent($progress, 'dependencies', $startPercent, $prefix . ': loading compact metadata');
            $result = (new CompactDependencyRebuilder($this->db, $storageRoot))->rebuild($fileId);
            self::emitPercent(
                $progress,
                'dependencies',
                $endPercent,
                $prefix . ': compact imports=' . (int)($result['imports_processed'] ?? 0)
                . ', changed=' . (int)($result['dependencies_changed'] ?? 0)
            );
            return;
        }

        (new PdoDependencySchemaManager($this->db))->ensure();
        self::emitPercent($progress, 'dependencies', $startPercent, $prefix . ': clearing old links');
        $this->db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);

        $statement = $this->db->prepare('SELECT game_id FROM ue_files WHERE id=?');
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            self::emitPercent($progress, 'dependencies', $endPercent, $prefix . ': skipped missing file');
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id,root_package,full_path,relative_object_path,is_common '
            . 'FROM ue_imports WHERE file_id=? ORDER BY import_index'
        );
        $statement->execute([$fileId]);
        $imports = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($imports === []) {
            self::emitPercent($progress, 'dependencies', $endPercent, $prefix . ': no imports');
            return;
        }

        $resolutions = PdoDependencyResolver::resolve($this->db, (int)$file['game_id'], $fileId, $imports);
        $total = count($imports);
        $batch = [];
        foreach ($imports as $i => $imp) {
            $resolution = $resolutions[(int)$imp['id']] ?? [
                'status' => 'missing',
                'resolved_file_id' => null,
                'resolved_export_id' => null,
                'source' => 'none',
                'confidence' => 'missing',
            ];
            $batch[] = [
                $fileId,
                (int)$imp['id'],
                (string)$imp['root_package'],
                (string)$imp['full_path'],
                $resolution['resolved_file_id'],
                $resolution['resolved_export_id'],
                (string)$resolution['status'],
                (string)($resolution['source'] ?? 'unknown'),
                (string)($resolution['confidence'] ?? 'unknown'),
            ];

            $done = $i + 1;
            if (count($batch) >= 250 || $done === $total) {
                self::bulkInsert(
                    $this->db,
                    'ue_dependencies',
                    [
                        'file_id',
                        'import_id',
                        'required_package',
                        'required_object_path',
                        'resolved_file_id',
                        'resolved_export_id',
                        'status',
                        'resolution_source',
                        'resolution_confidence',
                    ],
                    $batch
                );
                $batch = [];
                self::emitPercent(
                    $progress,
                    'dependencies',
                    self::rangePercent($startPercent, $endPercent, $done, $total),
                    $prefix . ': import ' . $done . '/' . $total
                );
            }
        }
    }

    public function rebuildGame(
        int $gameId,
        ?callable $progress = null,
        int $startPercent = 56,
        int $endPercent = 99
    ): void {
        $statement = $this->db->prepare(
            'SELECT id, package_name FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" ORDER BY package_name, id'
        );
        $statement->execute([$gameId]);
        $files = $statement->fetchAll(PDO::FETCH_ASSOC);
        $total = max(1, count($files));
        if ($files === []) {
            self::emitPercent($progress, 'dependencies', $endPercent, 'Refreshing game dependency links: no files');
            return;
        }

        foreach ($files as $i => $file) {
            $this->rebuild(
                (int)$file['id'],
                $progress,
                self::rangePercent($startPercent, $endPercent, $i, $total),
                self::rangePercent($startPercent, $endPercent, $i + 1, $total),
                'Refreshing game dependency links ' . ($i + 1) . '/' . (string)$total
                . ' (' . (string)$file['package_name'] . ')'
            );
        }
    }

    public function rebuildAffected(
        int $newFileId,
        ?callable $progress = null,
        int $startPercent = 56,
        int $endPercent = 99
    ): void {
        $statement = $this->db->prepare('SELECT game_id, package_name FROM ue_files WHERE id=?');
        $statement->execute([$newFileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            self::emitPercent(
                $progress,
                'dependencies',
                $endPercent,
                'Refreshing affected dependency links: imported file missing'
            );
            return;
        }

        $affectedFileIds = CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $this->db,
            (int)$file['game_id'],
            $newFileId,
            (string)$file['package_name']
        );
        $total = count($affectedFileIds);
        if ($total === 0) {
            self::emitPercent(
                $progress,
                'dependencies',
                $endPercent,
                'Refreshing affected dependency links: no existing files affected'
            );
            return;
        }
        foreach ($affectedFileIds as $index => $affectedFileId) {
            $this->rebuild(
                $affectedFileId,
                $progress,
                self::rangePercent($startPercent, $endPercent, $index, $total),
                self::rangePercent($startPercent, $endPercent, $index + 1, $total),
                'Refreshing affected dependency links ' . ($index + 1) . '/' . $total
            );
        }
    }

    public function rebuildAffectedForPackage(
        int $gameId,
        string $packageName,
        ?callable $progress = null,
        int $startPercent = 56,
        int $endPercent = 99,
        int $providerFileId = 0
    ): void {
        if ($providerFileId < 1) {
            throw new RuntimeException('Alias dependency refresh requires the provider file ID.');
        }
        $affectedFileIds = CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $this->db,
            $gameId,
            $providerFileId,
            $packageName
        );
        $total = count($affectedFileIds);
        if ($total === 0) {
            self::emitPercent(
                $progress,
                'dependencies',
                $endPercent,
                'Refreshing alias dependency links: no existing files affected'
            );
            return;
        }
        foreach ($affectedFileIds as $index => $affectedFileId) {
            $this->rebuild(
                $affectedFileId,
                $progress,
                self::rangePercent($startPercent, $endPercent, $index, $total),
                self::rangePercent($startPercent, $endPercent, $index + 1, $total),
                'Refreshing alias dependency links ' . ($index + 1) . '/' . $total . ' (' . $packageName . ')'
            );
        }
    }

    private static function emitPercent(?callable $progress, string $stage, int $percent, string $message): void
    {
        if ($progress === null) {
            return;
        }
        $percent = max(0, min(100, $percent));
        $progress([
            'stage' => $stage,
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'message' => $message,
        ]);
    }

    private static function rangePercent(int $start, int $end, int $done, int $total): int
    {
        $total = max(1, $total);
        $done = max(0, min($done, $total));
        return $start + (int)floor((($end - $start) * $done) / $total);
    }

    /** @param list<string> $columns @param list<list<mixed>> $rows */
    private static function bulkInsert(PDO $db, string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || $columns === []) {
            throw new InvalidArgumentException('Invalid bulk insert target.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                throw new InvalidArgumentException('Invalid bulk insert column.');
            }
        }

        $columnCount = count($columns);
        $tuple = '(' . implode(',', array_fill(0, $columnCount, '?')) . ')';
        $values = [];
        $args = [];
        foreach ($rows as $row) {
            if (count($row) !== $columnCount) {
                throw new InvalidArgumentException('Bulk insert row has the wrong column count.');
            }
            $values[] = $tuple;
            array_push($args, ...$row);
        }

        $statement = $db->prepare(
            'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ' . implode(',', $values)
        );
        $statement->execute($args);
    }
}
