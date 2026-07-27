<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Dependency\CatalogAffectedDependencyRefreshService;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/** Rebuilds existing files affected by one newly available package. */
final class CatalogAffectedDependencyRefreshJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REBUILD_AFFECTED_DEPENDENCIES;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = (int)($job->payload['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new \RuntimeException('Affected dependency refresh requires a positive file_id.');
        }

        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name FROM ue_files '
            . 'WHERE id=? AND scan_status="verified"'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            throw new \RuntimeException('Verified source file no longer exists: ' . $fileId);
        }

        require_once __DIR__ . '/../../../lib/CatalogScanner.php';

        $gameId = (int)$file['game_id'];
        $packageName = (string)$file['package_name'];
        $affectedIds = CatalogAffectedDependencyRefreshService::findAffectedFileIds(
            $this->db,
            $gameId,
            $fileId,
            $packageName
        );
        $total = count($affectedIds);
        $processed = 0;
        $failureCount = 0;
        $failures = [];

        $context->checkpoint([
            'stage' => 'dependencies',
            'done' => 0,
            'total' => max(1, $total),
            'percent' => $total === 0 ? 100 : 0,
            'message' => $total === 0
                ? 'No existing files require an affected dependency refresh.'
                : 'Refreshing ' . $total . ' affected file(s) for ' . $packageName . '.',
            'package_name' => $packageName,
            'failures' => 0,
        ]);

        foreach ($affectedIds as $index => $affectedFileId) {
            $position = $index + 1;
            try {
                \scanner_rebuild_dependencies(
                    $this->db,
                    $this->config,
                    $affectedFileId,
                    static function (array $progress) use ($context, $position, $total, $packageName, $failureCount): void {
                        $context->heartbeatIfDue([
                            'stage' => 'dependencies',
                            'done' => $position - 1,
                            'total' => max(1, $total),
                            'percent' => (int)floor((($position - 1) * 100) / max(1, $total)),
                            'message' => 'Refreshing affected file ' . $position . '/' . $total . ' for ' . $packageName
                                . (!empty($progress['message']) ? ' — ' . (string)$progress['message'] : ''),
                            'package_name' => $packageName,
                            'failures' => $failureCount,
                        ]);
                    },
                    0,
                    100,
                    'Refreshing affected file ' . $position . '/' . $total
                );
                $processed++;
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (Throwable $error) {
                $failureCount++;
                if (count($failures) < 100) {
                    $failures[] = [
                        'file_id' => $affectedFileId,
                        'error' => $error->getMessage(),
                    ];
                }
                error_log(
                    '[UnrealDB affected dependency refresh] source_file_id=' . $fileId
                    . ' affected_file_id=' . $affectedFileId
                    . ' error=' . $error->getMessage()
                );
            }

            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => $position,
                'total' => max(1, $total),
                'percent' => (int)floor(($position * 100) / max(1, $total)),
                'message' => 'Processed affected file ' . $position . '/' . $total . ' for ' . $packageName . '.',
                'package_name' => $packageName,
                'processed' => $processed,
                'failures' => $failureCount,
            ]);
        }

        return [
            'operation' => 'rebuild_affected_dependencies',
            'file_id' => $fileId,
            'game_id' => $gameId,
            'package_name' => $packageName,
            'original_name' => (string)$file['original_name'],
            'affected_files' => $total,
            'processed_files' => $processed,
            'failure_count' => $failureCount,
            'failures' => $failures,
            'failures_truncated' => $failureCount > count($failures),
        ];
    }
}
