<?php
/**
 * Bounded cleanup of abandoned public-upload quarantine files.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicUploadTransferStore;

final class CatalogPublicUploadMaintenanceJobHandler implements JobHandler
{
    private const BATCH_SIZE = 500;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::PRUNE_PUBLIC_UPLOADS;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $statement = $this->db->prepare(
            'SELECT id,upload_token FROM ue_public_uploads '
            . 'WHERE status="expired" AND quarantine_relative_path IS NOT NULL '
            . 'ORDER BY id LIMIT ' . self::BATCH_SIZE
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $store = new CatalogPublicUploadTransferStore($this->db, $this->config);
        $deleted = 0;

        foreach ($rows as $index => $row) {
            $token = strtolower(trim((string)($row['upload_token'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
                continue;
            }
            $store->removeQuarantine($token);
            $update = $this->db->prepare(
                'UPDATE ue_public_uploads SET quarantine_relative_path=NULL,received_bytes=0,updated_at=UTC_TIMESTAMP(6) '
                . 'WHERE id=? AND status="expired"'
            );
            $update->execute([(int)$row['id']]);
            $deleted += $update->rowCount() > 0 ? 1 : 0;
            $context->heartbeatIfDue([
                'stage' => 'prune_public_uploads',
                'percent' => min(99, (int)floor((($index + 1) * 100) / max(1, count($rows)))),
                'message' => 'Removed ' . $deleted . ' abandoned public-upload quarantine file(s).',
            ]);
        }

        if (count($rows) >= self::BATCH_SIZE) {
            $progress = [
                'stage' => 'prune_public_uploads',
                'percent' => 50,
                'message' => 'Removed ' . $deleted . ' abandoned public-upload file(s); continuing bounded cleanup.',
                'deleted' => $deleted,
            ];
            $context->checkpoint($progress);
            $context->defer(1, $progress, false);
        }

        $context->checkpoint([
            'stage' => 'complete',
            'percent' => 100,
            'message' => 'Public upload quarantine cleanup complete: ' . $deleted . ' abandoned file(s) removed.',
            'deleted' => $deleted,
        ]);
        return [
            'status' => 'completed',
            'deleted' => $deleted,
            'message' => 'Removed ' . $deleted . ' abandoned public-upload quarantine file(s).',
        ];
    }
}
