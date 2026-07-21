<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

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
        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;

        require_once __DIR__ . '/../../../lib/CatalogSourceScan.php';

        $result = \catalog_source_scan_run(
            $this->db,
            $this->config,
            $sourceId,
            $importUnknown,
            $strictProfile,
            $userId,
            static function (array $progress) use ($context): void {
                $context->checkpoint($progress);
            }
        );

        return ['operation' => 'source_scan', 'source_id' => $sourceId] + $result;
    }
}
