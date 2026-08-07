<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogStorageMaintenanceJobHandler` for catalog storage maintenance job
 *          handler.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;

final class CatalogStorageMaintenanceJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::RECONCILE_UNVERIFIED_STORAGE, JobType::PRUNE_STALE_ARTIFACTS], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return match ($job->type) {
            JobType::RECONCILE_UNVERIFIED_STORAGE => $this->reconcileUnverified($job, $context),
            JobType::PRUNE_STALE_ARTIFACTS => $this->pruneArtifacts($job, $context),
            default => throw new \RuntimeException('Unsupported storage maintenance job: ' . $job->type),
        };
    }

    private function reconcileUnverified(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogUnverifiedIndex.php';

        $limit = max(1, min((int)($job->payload['max_files'] ?? 1000), 10000));
        $storage = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        $games = \catalog_all($this->db, 'SELECT id,name,slug FROM ue_games ORDER BY id');
        $candidates = [];
        foreach ($games as $game) {
            $directory = $storage . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . (string)$game['slug'] . DIRECTORY_SEPARATOR . 'unverified';
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $name) {
                if ($name === '.' || $name === '..' || str_ends_with($name, '.txt')) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if (!is_file($path) || is_link($path)) {
                    continue;
                }
                $candidates[] = ['game' => $game, 'name' => $name, 'path' => $path];
                if (count($candidates) >= $limit) {
                    break 2;
                }
            }
        }

        $indexed = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];
        $total = count($candidates);
        $context->checkpoint([
            'stage' => 'reconcile_unverified',
            'done' => 0,
            'total' => max(1, $total),
            'percent' => 0,
            'message' => $total > 0 ? 'Reconciling ' . $total . ' unverified files.' : 'No unverified files require reconciliation.',
        ]);

        foreach ($candidates as $index => $candidate) {
            $game = $candidate['game'];
            $name = (string)$candidate['name'];
            $path = (string)$candidate['path'];
            $originalName = preg_replace('/^\d{8}_\d{6}_[a-f0-9]{8}_/i', '', $name) ?: $name;
            $notePath = $path . '.txt';
            $reason = is_file($notePath) ? trim((string)file_get_contents($notePath)) : 'Recovered from unverified filesystem reconciliation.';
            try {
                $result = \catalog_unverified_index_path(
                    $this->db,
                    $this->config,
                    (int)$game['id'],
                    $name,
                    $path,
                    $originalName,
                    $reason,
                    null,
                    '',
                    false
                );
                if ((string)$result['status'] === 'existing') {
                    $existing++;
                } else {
                    $indexed++;
                }
            } catch (\Throwable $error) {
                $failed++;
                if (count($errors) < 100) {
                    $errors[] = (string)$game['slug'] . '/' . $name . ': ' . $error->getMessage();
                }
            }

            $done = $index + 1;
            $context->checkpoint([
                'stage' => 'reconcile_unverified',
                'done' => $done,
                'total' => max(1, $total),
                'percent' => (int)floor(($done * 100) / max(1, $total)),
                'message' => 'Reconciled unverified file ' . $done . '/' . max(1, $total) . ': ' . $name,
                'indexed' => $indexed,
                'existing' => $existing,
                'failed' => $failed,
            ]);
        }

        return [
            'operation' => 'reconcile_unverified_storage',
            'processed' => $total,
            'indexed' => $indexed,
            'existing' => $existing,
            'failed' => $failed,
            'errors' => $errors,
            'errors_truncated' => $failed > count($errors),
            'limit_reached' => $total >= $limit,
        ];
    }

    private function pruneArtifacts(ClaimedJob $job, JobExecutionContext $context): array
    {
        $minimumAge = max(
            60,
            min(
                (int)($job->payload['orphan_min_age_seconds']
                    ?? $job->payload['incoming_max_age_seconds']
                    ?? 172800),
                30 * 86400
            )
        );
        $context->checkpoint([
            'stage' => 'prune_artifacts',
            'done' => 0,
            'total' => 3,
            'percent' => 0,
            'message' => 'Pruning generated package artifacts.',
        ]);
        $generated = (new GeneratedPackageStore((string)$this->config['storage_path']))->prune();
        $context->checkpoint([
            'stage' => 'prune_artifacts',
            'done' => 1,
            'total' => 3,
            'percent' => 33,
            'message' => 'Pruning stale incomplete chunk uploads outside the interactive upload path.',
            'generated' => $generated,
        ]);
        $chunkedUploads = (new CatalogChunkedUploadCleanup($this->config))->pruneIncomplete();
        $context->checkpoint([
            'stage' => 'prune_artifacts',
            'done' => 2,
            'total' => 3,
            'percent' => 66,
            'message' => 'Pruning unreferenced incoming sources and abandoned backup restore working copies.',
            'generated' => $generated,
            'chunked_uploads' => $chunkedUploads,
        ]);
        $jobStorage = (new CatalogJobStorageCleanup($this->db, $this->config))->prune($minimumAge);
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 3,
            'total' => 3,
            'percent' => 100,
            'message' => 'Stale artifact cleanup complete.',
            'generated' => $generated,
            'chunked_uploads' => $chunkedUploads,
            'job_storage' => $jobStorage,
        ]);
        return [
            'operation' => 'prune_stale_artifacts',
            'generated' => $generated,
            'chunked_uploads' => $chunkedUploads,
            'job_storage' => $jobStorage,
            'orphan_min_age_seconds' => $minimumAge,
        ];
    }
}
