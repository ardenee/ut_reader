<?php
/**
 * Primary dependency rebuild facade used by jobs, imports and maintenance.
 *
 * Public method signatures are retained, but all dependency rebuilding is V5-only.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogAffectedDependencyRefreshCoordinator;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;

final class PdoCatalogDependencyRebuilder
{
    private const FILE_LOCK_PREFIX = 'unrealdb_dependency_file_v1_';
    private const FILE_LOCK_WAIT_SECONDS = 15;

    private readonly Uedb5GameDependencyPassService $v5;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->v5 = new Uedb5GameDependencyPassService($db, $config);
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
            $fileId,$progress,$startPercent,$endPercent,$prefix,$refreshSummary
        ): void {
            $gameId = $this->assertRebuildableFile($fileId, $progress, $endPercent, $prefix);
            if ($gameId === null) {
                return;
            }
            self::emitPercent($progress, 'dependencies', $startPercent, $prefix . ': loading UEDB5 metadata');
            $result = $this->v5->runFile($gameId, $fileId, true, true);
            $detail = (array)($result['result'] ?? []);
            $projection = (array)($detail['projection'] ?? []);
            $message = $prefix . ': dependencies=' . (int)($detail['dependency_count'] ?? 0)
                . ', packages=' . (int)($projection['dependency_packages'] ?? 0);
            if (!$refreshSummary) {
                $message .= ', summary refresh deferred';
            }
            self::emitPercent($progress, 'dependencies', $endPercent, $message);
        });
    }

    /**
     * Package filtering was a V4 mutation optimization. UEDB5 rewrites the
     * authoritative dependency section atomically, so this method performs a
     * complete per-file V5 rebuild while retaining the caller contract.
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
            $gameId = $this->assertRebuildableFile($fileId, null, 100, 'Targeted dependency rebuild');
            if ($gameId === null) {
                return [
                    'file_id'=>$fileId,
                    'imports_processed'=>0,
                    'imports_total'=>0,
                    'dependencies_changed'=>0,
                    'container_rewritten'=>false,
                    'skipped_missing_file'=>true,
                ];
            }
            $run = $this->v5->runFile($gameId, $fileId, true, true);
            $detail = (array)($run['result'] ?? []);
            $projection = (array)($detail['projection'] ?? []);
            return [
                'file_id'=>$fileId,
                'imports_processed'=>(int)($detail['dependency_count'] ?? 0),
                'imports_total'=>(int)($detail['dependency_count'] ?? 0),
                'dependencies_changed'=>(int)($detail['dependency_count'] ?? 0),
                'container_rewritten'=>true,
                'package_filter_requested'=>array_values(array_unique(array_map('strval', $packageNames))),
                'package_filter_mode'=>'full_v5_rebuild',
                'summary_rows'=>(int)($projection['dependency_packages'] ?? 0),
                'summary_refresh_requested'=>$refreshSummary,
                'dependency_result'=>$detail,
            ];
        });
    }

    public function rebuildGame(
        int $gameId,
        ?callable $progress = null,
        int $startPercent = 56,
        int $endPercent = 99
    ): void {
        $statement = $this->db->prepare(
            'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,id'
        );
        $statement->execute([$gameId]);
        $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = max(1, count($files));
        if ($files === []) {
            self::emitPercent($progress, 'dependencies', $endPercent, 'Refreshing game dependencies: no files');
            return;
        }
        foreach ($files as $i => $file) {
            $this->rebuild(
                (int)$file['id'],
                $progress,
                self::rangePercent($startPercent, $endPercent, $i, $total),
                self::rangePercent($startPercent, $endPercent, $i + 1, $total),
                'Refreshing game dependencies ' . ($i + 1) . '/' . $total
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
            self::emitPercent($progress,'dependencies',$endPercent,'Refreshing affected dependencies: imported file missing');
            return;
        }
        $affectedFileIds = CatalogAffectedDependencyRefreshCoordinator::findAffectedFileIds(
            $this->db,(int)$file['game_id'],$newFileId,(string)$file['package_name']
        );
        $this->rebuildFileList(
            $affectedFileIds,$progress,$startPercent,$endPercent,'Refreshing affected dependencies'
        );
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
            $this->db,$gameId,$providerFileId,$packageName
        );
        $this->rebuildFileList(
            $affectedFileIds,$progress,$startPercent,$endPercent,
            'Refreshing alias dependencies'
        );
    }

    /** @param list<int> $fileIds */
    private function rebuildFileList(
        array $fileIds,
        ?callable $progress,
        int $startPercent,
        int $endPercent,
        string $prefix
    ): void {
        $total = count($fileIds);
        if ($total === 0) {
            self::emitPercent($progress,'dependencies',$endPercent,$prefix . ': no existing files affected');
            return;
        }
        foreach ($fileIds as $index => $fileId) {
            $this->rebuild(
                (int)$fileId,
                $progress,
                self::rangePercent($startPercent,$endPercent,$index,$total),
                self::rangePercent($startPercent,$endPercent,$index + 1,$total),
                $prefix . ' ' . ($index + 1) . '/' . $total
            );
        }
    }

    private function assertRebuildableFile(
        int $fileId,
        ?callable $progress,
        int $endPercent,
        string $prefix
    ): ?int {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('UEDB5 dependency rebuilding cannot run inside an existing database transaction.');
        }
        $statement = $this->db->prepare(
            'SELECT f.scan_status,f.game_id,v.format_version FROM ue_files f '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id WHERE f.id=?'
        );
        $statement->execute([$fileId]);
        $metadata = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($metadata)) {
            self::emitPercent($progress,'dependencies',$endPercent,$prefix . ': skipped missing file');
            return null;
        }
        if ((string)($metadata['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('Dependency rebuilding is only supported for verified catalog files.');
        }
        if ((int)($metadata['format_version'] ?? 0) !== 5) {
            throw new RuntimeException('Verified file #' . $fileId . ' has no authoritative UEDB5 metadata.');
        }
        return (int)$metadata['game_id'];
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
            }
        }
    }

    private static function emitPercent(?callable $progress, string $stage, int $percent, string $message): void
    {
        if ($progress === null) {
            return;
        }
        $percent = max(0,min(100,$percent));
        $progress([
            'stage'=>$stage,'done'=>$percent,'total'=>100,'percent'=>$percent,'message'=>$message,
        ]);
    }

    private static function rangePercent(int $start, int $end, int $done, int $total): int
    {
        $total=max(1,$total);
        $done=max(0,min($done,$total));
        return $start + (int)floor((($end - $start) * $done) / $total);
    }
}
