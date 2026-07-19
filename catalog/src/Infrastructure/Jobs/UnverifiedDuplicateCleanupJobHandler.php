<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Composition\CatalogServiceFactory;

final class UnverifiedDuplicateCleanupJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::CLEAN_UNVERIFIED_DUPLICATES;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $service = (new CatalogServiceFactory($this->db, $this->config))->unverifiedDuplicateCleanup();
        $result = $service->deleteDuplicates(
            static function (array $progress) use ($context): void {
                $context->heartbeatIfDue($progress);
            }
        );
        $errors = array_values((array)$result['errors']);

        return [
            'operation' => 'clean_unverified_duplicates',
            'physical_files' => (int)$result['physical_files'],
            'hashed_files' => (int)$result['hashed_files'],
            'duplicate_groups' => (int)$result['duplicate_groups'],
            'duplicate_files_found' => (int)$result['duplicate_files_found'],
            'deleted_files' => (int)$result['deleted_files'],
            'deleted_bytes' => (int)$result['deleted_bytes'],
            'deleted_bytes_text' => \catalog_bytes((int)$result['deleted_bytes']),
            'deleted' => array_values((array)$result['deleted']),
            'deleted_list_truncated' => !empty($result['deleted_list_truncated']),
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 200),
            'errors_truncated' => count($errors) > 200,
        ];
    }
}
