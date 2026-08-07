<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `GameBackupImportCleanupJobHandler` for game backup import cleanup job
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

use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;

/**
 * Ensures per-file backup restore working copies are removed even when the
 * scanner returns early for a duplicate or alias.
 */
final class GameBackupImportCleanupJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly JobHandler $inner,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $this->inner->supports($jobType);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        try {
            return $this->inner->handle($job, $context);
        } finally {
            $this->removeJobWorkingCopies($job->id);
        }
    }

    private function removeJobWorkingCopies(int $jobId): void
    {
        if ($jobId < 1) {
            return;
        }
        $storage = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storage === '') {
            return;
        }
        $directory = $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'game-backup-import';
        if (!is_dir($directory)) {
            return;
        }
        $prefix = 'restore-' . $jobId . '-';
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            if (str_starts_with($entry->getFilename(), $prefix)) {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }
}
