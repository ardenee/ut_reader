<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;

final class CatalogSourceScanJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::SOURCE_SCAN;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceId = (int)($job->payload['source_id'] ?? 0);
        if ($sourceId < 1) {
            throw new \RuntimeException('A valid source_id is required for a source scan job.');
        }

        $importUnknown = filter_var($job->payload['import_unknown'] ?? false, FILTER_VALIDATE_BOOL);
        $strictProfile = !array_key_exists('strict_profile', $job->payload)
            || filter_var($job->payload['strict_profile'], FILTER_VALIDATE_BOOL);
        $userIdValue = $job->payload['user_id'] ?? $job->payload['created_by_user_id'] ?? null;
        $userId = (int)$userIdValue > 0 ? (int)$userIdValue : null;

        $containerResult = $this->queueLocalPaks($sourceId, $strictProfile, $userId, $context);
        require_once __DIR__ . '/../../../lib/CatalogSourceScanNoContainers.php';

        $result = \catalog_source_scan_run_without_containers(
            $this->db,
            $this->config,
            $sourceId,
            $importUnknown,
            $strictProfile,
            $userId,
            static function (array $progress) use ($context, $containerResult): void {
                $progress += [
                    'pak_jobs_queued' => $containerResult['pak_jobs_queued'],
                    'pak_job_errors' => count($containerResult['pak_job_errors']),
                ];
                if ((string)($progress['stage'] ?? '') === 'complete') {
                    $context->checkpoint($progress);
                    return;
                }
                $context->heartbeatIfDue($progress);
            }
        );

        return ['operation' => 'source_scan', 'source_id' => $sourceId] + $containerResult + $result;
    }

    /** @return array{pak_jobs_queued:int,pak_job_ids:list<int>,pak_job_errors:list<string>} */
    private function queueLocalPaks(int $sourceId, bool $strictProfile, ?int $userId, JobExecutionContext $context): array
    {
        $statement = $this->db->prepare(
            'SELECT s.id,s.game_id,s.source_type,s.base_path,p.engine_key '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?'
        );
        $statement->execute([$sourceId]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source) || (string)$source['source_type'] !== 'local_path') {
            return ['pak_jobs_queued' => 0, 'pak_job_ids' => [], 'pak_job_errors' => []];
        }
        if (preg_match('/^UE[45]/i', trim((string)($source['engine_key'] ?? ''))) !== 1) {
            return ['pak_jobs_queued' => 0, 'pak_job_ids' => [], 'pak_job_errors' => []];
        }
        $basePath = realpath((string)$source['base_path']);
        if ($basePath === false || !is_dir($basePath) || !is_readable($basePath)) {
            throw new \RuntimeException('Source path is not readable: ' . (string)$source['base_path']);
        }

        $paks = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile() && !$item->isLink()
                && strtolower($item->getExtension()) === 'pak') {
                $paks[] = $item->getPathname();
            }
        }
        if ($paks === []) {
            return ['pak_jobs_queued' => 0, 'pak_job_ids' => [], 'pak_job_errors' => []];
        }

        $queue = new CatalogProfiledUploadQueue($this->db, $this->config);
        $jobIds = [];
        $errors = [];
        $basePrefix = rtrim(str_replace('\\', '/', $basePath), '/') . '/';
        foreach ($paks as $index => $path) {
            try {
                $normalized = str_replace('\\', '/', realpath($path) ?: $path);
                $relative = str_starts_with($normalized, $basePrefix)
                    ? ltrim(substr($normalized, strlen($basePrefix)), '/')
                    : basename($path);
                $queued = $queue->enqueueLocalPak(
                    (int)$source['game_id'],
                    $path,
                    $relative,
                    $strictProfile,
                    $userId,
                    $sourceId
                );
                $jobIds[] = (int)$queued['job_id'];
            } catch (\Throwable $error) {
                if (count($errors) < 50) {
                    $errors[] = $path . ' - ' . $error->getMessage();
                }
            }
            $context->heartbeatIfDue([
                'stage' => 'container_queue',
                'done' => $index + 1,
                'total' => count($paks),
                'percent' => (int)floor((($index + 1) * 100) / max(1, count($paks))),
                'message' => 'Queued local PAK ' . ($index + 1) . '/' . count($paks) . ': ' . basename($path),
                'pak_jobs_queued' => count($jobIds),
                'pak_job_errors' => count($errors),
            ]);
        }

        return ['pak_jobs_queued' => count($jobIds), 'pak_job_ids' => $jobIds, 'pak_job_errors' => $errors];
    }
}
