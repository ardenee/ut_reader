<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Dependency\CatalogAffectedDependencyRefreshService;
use UnrealDb\Catalog\Application\Dependency\CatalogDependencyReadSource;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/** Rebuilds file/game dependencies and their compact projections together. */
final class CatalogDependencyRefreshJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [
            JobType::REBUILD_GAME_DEPENDENCIES,
            JobType::REBUILD_FILE_DEPENDENCIES,
        ], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        return $job->type === JobType::REBUILD_GAME_DEPENDENCIES
            ? $this->rebuildGame($job, $context)
            : $this->rebuildFile($job, $context);
    }

    private function rebuildFile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = $this->positiveInt($job->payload, 'file_id');
        $file = $this->one(
            'SELECT id,game_id,package_name,original_name FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if ($file === null) {
            throw new \RuntimeException('Verified file no longer exists: ' . $fileId);
        }

        \scanner_rebuild_dependencies(
            $this->db,
            $this->config,
            $fileId,
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue($progress);
            },
            0,
            70,
            'Refreshing file dependency links'
        );

        $context->checkpoint([
            'stage' => 'package_provider',
            'done' => 1,
            'total' => 4,
            'percent' => 74,
            'message' => 'Reconciling the imported package provider.',
            'file_id' => $fileId,
        ]);
        (new PdoPackageProviderRepository($this->db))->reconcileFile($fileId);

        $context->checkpoint([
            'stage' => 'dependency_summary',
            'done' => 2,
            'total' => 4,
            'percent' => 82,
            'message' => 'Rebuilding the imported file dependency summary.',
            'file_id' => $fileId,
        ]);
        $summary = (new PdoDependencyPackageSummary($this->db))->rebuildFile($fileId);

        $affectedJobId = 0;
        $postImport = !empty($job->payload['post_import']);
        if ($postImport) {
            $context->checkpoint([
                'stage' => 'affected_detection',
                'done' => 3,
                'total' => 4,
                'percent' => 90,
                'message' => 'Checking whether existing files reference the imported package.',
                'file_id' => $fileId,
                'dependency_summary_rows' => (int)$summary['summary_rows'],
            ]);
            $affectedJobId = CatalogAffectedDependencyRefreshService::enqueueIfNeeded(
                $this->db,
                (int)$file['game_id'],
                $fileId,
                (string)$file['package_name'],
                true,
                true
            );
        }

        $gameStats = null;
        if ($affectedJobId < 1) {
            $context->checkpoint([
                'stage' => 'game_stats',
                'done' => 4,
                'total' => 4,
                'percent' => 95,
                'message' => 'Refreshing cached game counters.',
                'file_id' => $fileId,
                'dependency_summary_rows' => (int)$summary['summary_rows'],
            ]);
            $gameStats = (new PdoGameCatalogStats($this->db))->rebuildGame((int)$file['game_id']);
        } else {
            $context->checkpoint([
                'stage' => 'affected_queued',
                'done' => 4,
                'total' => 4,
                'percent' => 100,
                'message' => 'Queued affected-file refresh job #' . $affectedJobId
                    . '; that chain will publish final game counters.',
                'file_id' => $fileId,
                'affected_job_id' => $affectedJobId,
                'dependency_summary_rows' => (int)$summary['summary_rows'],
            ]);
        }

        return [
            'operation' => 'rebuild_file_dependencies',
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'post_import' => $postImport,
            'package_provider_reconciled' => true,
            'dependency_summary_rows' => (int)$summary['summary_rows'],
            'affected_job_id' => $affectedJobId,
            'game_stats_refreshed' => $gameStats !== null,
            'stats' => $this->stats([$fileId]),
        ];
    }

    private function rebuildGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $offset = max(0, (int)($job->payload['offset'] ?? 0));
        $game = $this->one('SELECT id,name FROM ue_games WHERE id=?', [$gameId]);
        if ($game === null) {
            throw new \RuntimeException('Game no longer exists: ' . $gameId);
        }

        $statement = $this->db->prepare(
            'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" '
            . 'ORDER BY package_name,id LIMIT 18446744073709551615 OFFSET ' . $offset
        );
        $statement->execute([$gameId]);
        $files = $statement->fetchAll(PDO::FETCH_ASSOC);
        $total = count($files);
        $processedIds = [];
        $summaryRows = 0;
        $summaryWriter = new PdoDependencyPackageSummary($this->db);
        $providerWriter = new PdoPackageProviderRepository($this->db);

        if ($total === 0) {
            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => 1,
                'total' => 1,
                'percent' => 90,
                'message' => 'No verified files were found for the selected game and offset.',
            ]);
        }

        foreach ($files as $index => $file) {
            $fileId = (int)$file['id'];
            $position = $index + 1;
            \scanner_rebuild_dependencies(
                $this->db,
                $this->config,
                $fileId,
                static function (array $progress) use ($context): void {
                    $context->heartbeatIfDue($progress);
                },
                (int)floor(($index * 90) / max(1, $total)),
                (int)floor(($position * 90) / max(1, $total)),
                'Refreshing game dependency links ' . $position . '/' . $total . ' (' . (string)$file['package_name'] . ')'
            );
            $providerWriter->reconcileFile($fileId);
            $summary = $summaryWriter->rebuildFile($fileId);
            $summaryRows += (int)$summary['summary_rows'];
            $processedIds[] = $fileId;
            $context->checkpoint([
                'stage' => 'dependency_summary',
                'done' => $position,
                'total' => max(1, $total),
                'percent' => (int)floor(($position * 90) / max(1, $total)),
                'message' => 'Processed dependency file ' . $position . '/' . $total . '.',
                'dependency_summary_rows' => $summaryRows,
            ]);
        }

        $context->checkpoint([
            'stage' => 'game_stats',
            'done' => max(1, $total),
            'total' => max(1, $total),
            'percent' => 95,
            'message' => 'Refreshing cached game counters.',
            'game_id' => $gameId,
        ]);
        $gameStats = (new PdoGameCatalogStats($this->db))->rebuildGame($gameId);

        return [
            'operation' => 'rebuild_game_dependencies',
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'offset' => $offset,
            'processed_files' => $total,
            'dependency_summary_rows' => $summaryRows,
            'game_stats_refreshed' => $gameStats !== null,
            'stats' => $this->stats($processedIds),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = (int)($payload[$key] ?? 0);
        if ($value < 1) {
            throw new \RuntimeException('A positive ' . $key . ' is required.');
        }
        return $value;
    }

    /** @param list<mixed> $args @return array<string,mixed>|null */
    private function one(string $sql, array $args): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<int> $fileIds @return array<string,int> */
    private function stats(array $fileIds): array
    {
        if ($fileIds === []) {
            return ['total' => 0, 'resolved' => 0, 'missing' => 0, 'package_only' => 0, 'common' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $statement = $this->db->prepare(
            'SELECT COUNT(*) total,SUM(status="resolved") resolved,SUM(status="missing") missing,'
            . 'SUM(status="package_only") package_only,SUM(status="common") common '
            . 'FROM ' . CatalogDependencyReadSource::sql($this->db) . ' dependencies '
            . 'WHERE dependencies.file_id IN (' . $placeholders . ')'
        );
        $statement->execute($fileIds);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'resolved' => (int)($row['resolved'] ?? 0),
            'missing' => (int)($row['missing'] ?? 0),
            'package_only' => (int)($row['package_only'] ?? 0),
            'common' => (int)($row['common'] ?? 0),
        ];
    }
}
