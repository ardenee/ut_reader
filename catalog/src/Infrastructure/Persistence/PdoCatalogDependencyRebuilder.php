<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rebuilds dependency resolution for verified files from authoritative format-2 metadata.
 * Why: Dependency maintenance must not fall back to retired SQL Import/Dependency projections, and unrelated files
 *      must not serialize behind the global catalog identity-write lock.
 * Role: Primary compact dependency rebuild implementation used by durable jobs and scanner compatibility delegates.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogAffectedDependencyRefreshCoordinator;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyRebuilder;

final class PdoCatalogDependencyRebuilder
{
    private const FILE_LOCK_PREFIX = 'unrealdb_dependency_file_v1_';
    private const FILE_LOCK_WAIT_SECONDS = 15;

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
        string $prefix = 'Rebuilding dependencies',
        bool $refreshSummary = true
    ): void {
        $this->withFileLock($fileId, function () use (
            $fileId,
            $progress,
            $startPercent,
            $endPercent,
            $prefix,
            $refreshSummary
        ): void {
            $storageRoot = $this->assertRebuildableFile($fileId, $progress, $endPercent, $prefix);
            if ($storageRoot === null) {
                return;
            }

            self::emitPercent($progress, 'dependencies', $startPercent, $prefix . ': loading compact metadata');
            $result = (new CompactDependencyRebuilder($this->db, $storageRoot))->rebuild($fileId);

            $summaryRows = null;
            if ($refreshSummary) {
                $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);
                if (empty($summary['available'])) {
                    throw new RuntimeException('Dependency package summary projection is unavailable after compact rebuild.');
                }
                $summaryRows = (int)($summary['summary_rows'] ?? 0);
            }

            $message = $prefix . ': compact imports=' . (int)($result['imports_processed'] ?? 0)
                . ', changed=' . (int)($result['dependencies_changed'] ?? 0);
            $message .= $summaryRows === null
                ? ', summary refresh deferred'
                : ', summary rows=' . $summaryRows;
            self::emitPercent($progress, 'dependencies', $endPercent, $message);
        });
    }

    /**
     * Targeted compact dependency refresh used by projection reconciliation.
     * The caller may bulk-refresh summaries after all changed owners are known.
     *
     * @param list<string> $packageNames
     * @return array<string,mixed>
     */
    public function rebuildForPackages(
        int $fileId,
        array $packageNames,
        bool $refreshSummary = false
    ): array {
        return $this->withFileLock($fileId, function () use ($fileId, $packageNames, $refreshSummary): array {
            $storageRoot = $this->assertRebuildableFile($fileId, null, 100, 'Targeted dependency rebuild');
            if ($storageRoot === null) {
                return [
                    'file_id' => $fileId,
                    'imports_processed' => 0,
                    'imports_total' => 0,
                    'dependencies_changed' => 0,
                    'container_rewritten' => false,
                    'skipped_missing_file' => true,
                ];
            }

            $result = (new CompactDependencyRebuilder($this->db, $storageRoot))
                ->rebuildForPackages($fileId, $packageNames);
            if ($refreshSummary) {
                $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);
                if (empty($summary['available'])) {
                    throw new RuntimeException('Dependency package summary projection is unavailable after targeted compact rebuild.');
                }
                $result['summary_rows'] = (int)($summary['summary_rows'] ?? 0);
            }
            return $result;
        });
    }

    public function rebuildGame(
        int $gameId,
        ?callable $progress = null,
        int $startPercent = 56,
        int $endPercent = 99
    ): void {
        // Include every verified file. rebuild() owns the format-2 invariant and
        // will surface an integrity gap instead of silently skipping that file.
        $statement = $this->db->prepare(
            'SELECT id,package_name FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" ORDER BY package_name,id'
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
        $statement = $this->db->prepare('SELECT game_id,package_name FROM ue_files WHERE id=?');
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

    private function assertRebuildableFile(
        int $fileId,
        ?callable $progress,
        int $endPercent,
        string $prefix
    ): ?string {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact dependency rebuilding cannot run inside an existing database transaction.');
        }

        $statement = $this->db->prepare(
            'SELECT f.scan_status,m.format_version FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id WHERE f.id=?'
        );
        $statement->execute([$fileId]);
        $metadata = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($metadata)) {
            self::emitPercent($progress, 'dependencies', $endPercent, $prefix . ': skipped missing file');
            return null;
        }
        if ((string)($metadata['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('Dependency rebuilding is only supported for verified catalog files.');
        }
        if ((int)($metadata['format_version'] ?? 0) !== 2) {
            throw new RuntimeException(
                'Verified file #' . $fileId . ' has no current format-2 metadata; runtime legacy dependency rebuild is disabled.'
            );
        }

        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact dependency rebuilding.');
        }
        return $storageRoot;
    }

    private function withFileLock(int $fileId, callable $operation): mixed
    {
        if ($fileId < 1) {
            throw new RuntimeException('Dependency rebuilding requires a positive file ID.');
        }
        $lockName = self::FILE_LOCK_PREFIX . $fileId;
        $statement = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$lockName, self::FILE_LOCK_WAIT_SECONDS]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Dependency metadata for file #' . $fileId . ' is already being refreshed.');
        }

        try {
            return $operation();
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // Closing the connection also releases advisory locks.
            }
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
}
