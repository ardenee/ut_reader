<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Queues durable reconciliation after direct catalogue identity/status writes. */
final class CatalogProjectionReconciliationQueue
{
    /**
     * @param list<int> $gameIds
     * @param list<string> $packageNames
     * @param array<string,mixed>|null $config
     */
    public static function enqueue(
        PDO $db,
        int $fileId,
        array $gameIds = [],
        array $packageNames = [],
        ?array $config = null,
        ?int $createdBy = null
    ): int {
        $gameIds = array_values(array_unique(array_filter(array_map('intval', $gameIds), static fn(int $id): bool => $id > 0)));
        $packageNames = self::normalizePackageNames($packageNames);
        if ($fileId < 1 && $gameIds === []) {
            return 0;
        }

        try {
            $config ??= function_exists('catalog_config') ? \catalog_config() : [];
            $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
            $payload = [
                'file_id' => max(0, $fileId),
                'game_ids' => $gameIds,
                'package_names' => $packageNames,
            ];
            $contextHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            $jobId = (new PdoJobQueue($db))->enqueue(
                $queueName,
                JobType::RECONCILE_CATALOG_PROJECTIONS,
                $payload,
                55,
                null,
                'catalog-projections:' . $contextHash,
                $createdBy,
                3
            );

            if ($config !== []) {
                try {
                    (new CatalogDetachedWorker($config))->start($queueName, 10000);
                } catch (Throwable $workerError) {
                    error_log('[UnrealDB projection reconciliation worker] job_id=' . $jobId . ' launch failed: ' . $workerError->getMessage());
                }
            }
            return $jobId;
        } catch (Throwable $error) {
            error_log('[UnrealDB projection reconciliation queue] file_id=' . $fileId . ' enqueue failed: ' . $error->getMessage());
            return 0;
        }
    }

    /** @param list<string> $names @return list<string> */
    private static function normalizePackageNames(array $names): array
    {
        $normalized = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $normalized[mb_strtolower($name, 'UTF-8')] = $name;
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }
}
