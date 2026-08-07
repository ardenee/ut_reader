<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogProjectionReconciliationQueue` for catalog projection reconciliation
 *          queue.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobResourceLimitStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Queues durable reconciliation after direct catalogue identity/status writes. */
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

            /*
             * Projection reconciliation takes one global catalogue-maintenance
             * advisory lock. Giving each file its own concurrency key only lets
             * several workers claim jobs that then wait on the same lock. Persist
             * the real serialization rule on the queue row so only one projection
             * job can be running while other workers remain free for other classes.
             */
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

            // Queueing must remain a short foreground operation. Worker lifecycle
            // is controlled from Background Jobs and must not delay maintenance,
            // rename or other interactive requests that enqueue reconciliation.
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
