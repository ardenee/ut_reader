<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reconciles materialized catalogue projections after direct maintenance writes.
 * Why: Projection maintenance is not an identity write; affected dependency owners must use narrow per-file locking so
 *      long reconciliation jobs cannot stall unrelated Full Sync or catalog maintenance requests.
 * Role: Infrastructure durable-job handler for catalog.reconcile_catalog_projections.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageProviderRepository;

/** Reconciles all materialized catalogue projections after direct maintenance writes. */
final class CatalogProjectionReconciliationJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::RECONCILE_CATALOG_PROJECTIONS;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        // Provider, dependency-summary and game-stat rows are projections. They
        // must not hold unrealdb_catalog_maintenance_write_v1 while iterating
        // potentially thousands of dependency owners. File dependency writes are
        // serialized by PdoCatalogDependencyRebuilder's per-file advisory lock.
        return $this->reconcile($job, $context);
    }

    /** @return array<string,mixed> */
    private function reconcile(ClaimedJob $job, JobExecutionContext $context): array
    {
        $fileId = max(0, (int)($job->payload['file_id'] ?? 0));
        $gameIds = $this->positiveIds((array)($job->payload['game_ids'] ?? []));
        $packageNames = $this->packageNames((array)($job->payload['package_names'] ?? []));
        if ($fileId < 1 && $gameIds === []) {
            throw new \RuntimeException('Projection reconciliation requires a file or game context.');
        }

        $file = null;
        if ($fileId > 0) {
            $statement = $this->db->prepare('SELECT id,game_id,package_name,scan_status FROM ue_files WHERE id=?');
            $statement->execute([$fileId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $file = is_array($row) ? $row : null;
        }

        if ($file !== null) {
            $currentGameId = (int)($file['game_id'] ?? 0);
            if ($currentGameId > 0) {
                $gameIds[] = $currentGameId;
            }
            $packageNames[] = (string)($file['package_name'] ?? '');
            $aliasStatement = $this->db->prepare(
                'SELECT package_name FROM ue_file_package_aliases WHERE file_id=? ORDER BY id'
            );
            $aliasStatement->execute([$fileId]);
            foreach ($aliasStatement->fetchAll(PDO::FETCH_COLUMN) as $aliasName) {
                $packageNames[] = (string)$aliasName;
            }
        }
        $gameIds = $this->positiveIds($gameIds);
        $packageNames = $this->packageNames($packageNames);

        $context->checkpoint([
            'stage' => 'projections',
            'done' => 0,
            'total' => 4,
            'percent' => 0,
            'message' => 'Reconciling package providers and compact dependency summaries.',
            'file_id' => $fileId,
            'game_ids' => $gameIds,
        ]);

        $providers = new PdoPackageProviderRepository($this->db);
        $summaries = new PdoDependencyPackageSummary($this->db);
        if ($fileId > 0) {
            $providers->reconcileFile($fileId);
            $summaryResult = $summaries->rebuildFile($fileId);
        } else {
            $summaryResult = ['summary_rows' => 0, 'available' => $summaries->available()];
        }

        $context->checkpoint([
            'stage' => 'dependencies',
            'done' => 1,
            'total' => 4,
            'percent' => 25,
            'message' => 'Finding dependency owners affected by old and new package identities.',
            'package_names' => $packageNames,
        ]);

        $affectedIds = $this->affectedFileIds($gameIds, $packageNames, $fileId, $summaries->available());
        $affectedTotal = count($affectedIds);
        $processed = 0;
        $failureCount = 0;
        $failures = [];
        $dependencyFilesChanged = 0;
        $compactNoopFiles = 0;
        $targetedImports = 0;
        $summaryRefreshIds = [];
        $dependencyRebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);

        foreach ($affectedIds as $index => $affectedFileId) {
            try {
                $message = 'Reconciling dependency owner ' . ($index + 1) . '/' . $affectedTotal;
                $result = $dependencyRebuilder->rebuildForPackages(
                    $affectedFileId,
                    $packageNames,
                    false
                );
                $changed = (int)($result['dependencies_changed'] ?? 0);
                $targeted = (int)($result['imports_processed'] ?? 0);
                $importsTotal = (int)($result['imports_total'] ?? $targeted);
                $targetedImports += $targeted;
                $message .= ': targeted compact imports=' . $targeted . '/' . $importsTotal
                    . ', changed=' . $changed;
                if ($changed === 0) {
                    $compactNoopFiles++;
                } else {
                    $dependencyFilesChanged++;
                    $summaryRefreshIds[] = $affectedFileId;
                }

                $processed++;
                $context->heartbeatIfDue([
                    'stage' => 'dependencies',
                    'done' => $index + 1,
                    'total' => max(1, $affectedTotal),
                    'percent' => 25 + (int)floor((($index + 1) * 50) / max(1, $affectedTotal)),
                    'message' => $message,
                    'changed_files' => $dependencyFilesChanged,
                    'no_op_files' => $compactNoopFiles,
                ]);
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (Throwable $error) {
                $failureCount++;
                if (count($failures) < 100) {
                    $failures[] = ['file_id' => $affectedFileId, 'error' => $error->getMessage()];
                }
                error_log(
                    '[UnrealDB projection reconciliation] affected_file_id=' . $affectedFileId
                    . ' failed: ' . $error->getMessage()
                );
            }
        }

        $summaryFilesRefreshed = 0;
        if ($summaryRefreshIds !== []) {
            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => max(1, $affectedTotal),
                'total' => max(1, $affectedTotal),
                'percent' => 76,
                'message' => 'Refreshing package summaries for ' . count($summaryRefreshIds)
                    . ' changed dependency owner(s).',
            ]);
            $bulkSummary = $summaries->rebuildFiles($summaryRefreshIds);
            $summaryFilesRefreshed = (int)($bulkSummary['files'] ?? 0);
        }

        $context->checkpoint([
            'stage' => 'game_stats',
            'done' => 3,
            'total' => 4,
            'percent' => 80,
            'message' => 'Refreshing cached game counters.',
            'game_ids' => $gameIds,
        ]);
        $stats = new PdoGameCatalogStats($this->db);
        $statsRefreshed = 0;
        foreach ($gameIds as $gameId) {
            if ($stats->rebuildGame($gameId) !== null) {
                $statsRefreshed++;
            }
        }

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 4,
            'total' => 4,
            'percent' => 100,
            'message' => 'Catalogue projections reconciled.',
            'affected_files' => $affectedTotal,
            'changed_files' => $dependencyFilesChanged,
            'no_op_files' => $compactNoopFiles,
            'failures' => $failureCount,
        ]);

        return [
            'operation' => 'reconcile_catalog_projections',
            'file_id' => $fileId,
            'file_exists' => $file !== null,
            'game_ids' => $gameIds,
            'package_names' => $packageNames,
            'dependency_summary_rows' => (int)($summaryResult['summary_rows'] ?? 0),
            'affected_files' => $affectedTotal,
            'processed_files' => $processed,
            'dependency_files_changed' => $dependencyFilesChanged,
            'compact_no_op_files' => $compactNoopFiles,
            'targeted_imports_processed' => $targetedImports,
            'summary_files_refreshed' => $summaryFilesRefreshed,
            'stats_refreshed' => $statsRefreshed,
            'failure_count' => $failureCount,
            'failures' => $failures,
            'failures_truncated' => $failureCount > count($failures),
        ];
    }

    /** @param list<int> $gameIds @param list<string> $packageNames @return list<int> */
    private function affectedFileIds(array $gameIds, array $packageNames, int $excludeFileId, bool $summaryAvailable): array
    {
        if ($gameIds === [] || $packageNames === []) {
            return [];
        }
        $ids = [];
        foreach ($gameIds as $gameId) {
            foreach (array_chunk($packageNames, 100) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                if ($summaryAvailable) {
                    $sql = 'SELECT DISTINCT s.file_id FROM ue_dependency_package_summaries s '
                        . 'JOIN ue_files f ON f.id=s.file_id '
                        . 'WHERE s.game_id=? AND s.required_package IN (' . $placeholders . ') '
                        . 'AND f.scan_status="verified"';
                } else {
                    $sql = 'SELECT DISTINCT d.file_id FROM ' . PdoDependencyReadSource::sql($this->db) . ' d '
                        . 'JOIN ue_files f ON f.id=d.file_id '
                        . 'WHERE f.game_id=? AND d.required_package IN (' . $placeholders . ') '
                        . 'AND f.scan_status="verified"';
                }
                $args = [$gameId, ...$chunk];
                if ($excludeFileId > 0) {
                    $sql .= $summaryAvailable ? ' AND s.file_id<>?' : ' AND d.file_id<>?';
                    $args[] = $excludeFileId;
                }
                $statement = $this->db->prepare($sql);
                $statement->execute($args);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $ids[(int)$id] = true;
                }
            }
        }
        ksort($ids);
        return array_map('intval', array_keys($ids));
    }

    /** @param array<mixed> $values @return list<int> */
    private function positiveIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<mixed> $values @return list<string> */
    private function packageNames(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
            $name = trim((string)$value);
            if ($name !== '') {
                $names[mb_strtolower($name, 'UTF-8')] = $name;
            }
        }
        ksort($names);
        return array_values($names);
    }
}
