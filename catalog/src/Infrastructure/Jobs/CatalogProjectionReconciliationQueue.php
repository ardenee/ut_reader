<?php
/**
 * Durable catalog-projection reconciliation queue adapter.
 *
 * Queue row policy, saved resource limits and PDO persistence belong in
 * Infrastructure. Callers only request reconciliation; worker lifecycle remains
 * outside foreground requests.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogProjectionReconciliationQueue
{
    private const CONCURRENCY_KEY = 'projection:catalog-maintenance';

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

            $dependencyLimit = (new CatalogJobResourceLimitStore($db, $queueName))->resolve(
                JobResourcePolicy::DEPENDENCY_HEAVY,
                1
            );
            $serialize = $db->prepare(
                'UPDATE ue_background_jobs SET resource_class=?,resource_limit=?,concurrency_key=? '
                . 'WHERE id=? AND status="queued"'
            );
            $serialize->execute([
                JobResourcePolicy::DEPENDENCY_HEAVY,
                $dependencyLimit,
                self::CONCURRENCY_KEY,
                $jobId,
            ]);

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
