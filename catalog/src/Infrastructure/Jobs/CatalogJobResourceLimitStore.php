<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogJobResourceLimitStore` for catalog job resource limit store.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/**
 * Persists administrator-selected job-class limits and applies them to the
 * durable queue. The small in-process cache avoids one settings query for
 * every file in a large enqueue batch while still refreshing regularly.
 */
final class CatalogJobResourceLimitStore
{
    private const TABLE = 'ue_job_resource_limits';
    private const CACHE_SECONDS = 5.0;
    private const PROJECTION_CONCURRENCY_KEY = 'projection:catalog-maintenance';

    /** @var array<string,int> */
    private array $limits = [];
    private float $loadedAt = 0.0;
    private ?bool $available = null;
    private string $queueName;

    public function __construct(private readonly PDO $db, string $queueName = 'catalog')
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80) {
            throw new \InvalidArgumentException('Invalid background-job queue name.');
        }
        $this->queueName = $queueName;
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $statement = $this->db->query(
                'SELECT 1 FROM information_schema.tables '
                . 'WHERE table_schema=DATABASE() AND table_name="' . self::TABLE . '" LIMIT 1'
            );
            return $this->available = (bool)$statement->fetchColumn();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function resolve(string $resourceClass, int $fallback): int
    {
        $fallback = self::limit($fallback);
        if (!$this->isAvailable()) {
            return $fallback;
        }

        if ($this->loadedAt <= 0.0 || microtime(true) - $this->loadedAt >= self::CACHE_SECONDS) {
            $this->reload();
        }

        return $this->limits[$resourceClass] ?? $fallback;
    }

    /**
     * @return list<array{
     *   resource_class:string,label:string,description:string,default_limit:int,limit:int,
     *   running:int,queued:int,ready:int,available_slots:int,class_blocked:int,is_limiting:bool
     * }>
     */
    public function summaries(): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'The job resource-limit migration has not been applied. Run catalog/bin/migrate.php migrate.'
            );
        }

        $this->reload();
        $metrics = [];
        $statement = $this->db->prepare(
            'SELECT resource_class,'
            . 'SUM(status="running") running_jobs,'
            . 'SUM(status="queued") queued_jobs,'
            . 'SUM(status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()) ready_jobs '
            . 'FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status IN ("queued","running") GROUP BY resource_class'
        );
        $statement->execute([$this->queueName]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $class = trim((string)($row['resource_class'] ?? ''));
            if ($class === '') {
                continue;
            }
            $metrics[$class] = [
                'running' => max(0, (int)($row['running_jobs'] ?? 0)),
                'queued' => max(0, (int)($row['queued_jobs'] ?? 0)),
                'ready' => max(0, (int)($row['ready_jobs'] ?? 0)),
            ];
        }

        $definitions = JobResourcePolicy::definitions();
        foreach (array_keys($metrics) as $class) {
            if (!isset($definitions[$class])) {
                $definitions[$class] = [
                    'label' => ucwords(str_replace('-', ' ', $class)),
                    'default' => $this->limits[$class] ?? 1,
                    'description' => 'Resource class retained by existing queue rows.',
                ];
            }
        }

        $rows = [];
        foreach ($definitions as $class => $definition) {
            $default = self::limit((int)($definition['default'] ?? 1));
            $limit = $this->limits[$class] ?? $default;
            $running = (int)($metrics[$class]['running'] ?? 0);
            $queued = (int)($metrics[$class]['queued'] ?? 0);
            $ready = (int)($metrics[$class]['ready'] ?? 0);
            $slots = max(0, $limit - $running);
            $blocked = max(0, $ready - $slots);
            $rows[] = [
                'resource_class' => $class,
                'label' => (string)($definition['label'] ?? $class),
                'description' => (string)($definition['description'] ?? ''),
                'default_limit' => $default,
                'limit' => $limit,
                'running' => $running,
                'queued' => $queued,
                'ready' => $ready,
                'available_slots' => $slots,
                'class_blocked' => $blocked,
                'is_limiting' => $blocked > 0,
            ];
        }

        return $rows;
    }

    /**
     * Rewrites current queued rows to the current saved limits and repairs
     * policy changes that older queue rows cannot learn by themselves.
     *
     * Projection reconciliation uses one global MySQL maintenance lock. Older
     * rows were persisted as search-heavy with per-file concurrency keys, so
     * several workers could claim them even though only one could make progress.
     * Normalize only the projection coordinator to dependency-heavy and one
     * global concurrency key before a worker pool is started or resized.
     *
     * Per-file dependency rebuilds are independent durable units. Older rows
     * used dependency-heavy, serializing an entire whole-game workflow to one
     * worker. Reclassify only queued file units to the bounded parallel class;
     * preserve their per-file concurrency keys and all retry/progress state.
     *
     * Staged archive/PAK imports created by a profiled upload batch are source
     * roots, not one game-wide critical section. Older queued rows used the
     * archive coordinator class plus import:game:<id>, which serialized every
     * source in the same game and made worker-count settings ineffective. Move
     * those queued roots to the bounded source-archive class and a per-job key.
     *
     * Affected-dependency coordinators also have a legacy rekey rule. Per-file
     * children and pre-upgrade batch compatibility rows are intentionally excluded
     * because their narrower concurrency keys are required for safe fan-out.
     *
     * @return array{updated_jobs:int,updated_limits:int,projection_rows:int,dependency_file_rows:int,source_archive_rows:int,rekeyed_jobs:int,per_class:array<string,int>}
     */
    public function synchronizeQueuedPolicies(): array
    {
        if (!$this->isAvailable()) {
            return [
                'updated_jobs' => 0,
                'updated_limits' => 0,
                'projection_rows' => 0,
                'dependency_file_rows' => 0,
                'source_archive_rows' => 0,
                'rekeyed_jobs' => 0,
                'per_class' => [],
            ];
        }

        $this->reload();
        $definitions = JobResourcePolicy::definitions();
        $dependencyDefault = self::limit((int)($definitions[JobResourcePolicy::DEPENDENCY_HEAVY]['default'] ?? 1));
        $dependencyLimit = $this->limits[JobResourcePolicy::DEPENDENCY_HEAVY] ?? $dependencyDefault;
        $dependencyFileDefault = self::limit((int)($definitions[JobResourcePolicy::AFFECTED_DEPENDENCY_BATCH]['default'] ?? 4));
        $dependencyFileLimit = $this->limits[JobResourcePolicy::AFFECTED_DEPENDENCY_BATCH] ?? $dependencyFileDefault;
        $sourceArchiveDefault = self::limit((int)($definitions[JobResourcePolicy::SOURCE_ARCHIVE_IMPORT]['default'] ?? 4));
        $sourceArchiveLimit = $this->limits[JobResourcePolicy::SOURCE_ARCHIVE_IMPORT] ?? $sourceArchiveDefault;

        $projection = $this->db->prepare(
            'UPDATE ue_background_jobs SET resource_class=?,resource_limit=?,concurrency_key=? '
            . 'WHERE queue_name=? AND status="queued" AND job_type=? '
            . 'AND (resource_class<>? OR resource_limit<>? OR concurrency_key IS NULL OR concurrency_key<>?)'
        );
        $projection->execute([
            JobResourcePolicy::DEPENDENCY_HEAVY,
            $dependencyLimit,
            self::PROJECTION_CONCURRENCY_KEY,
            $this->queueName,
            JobType::RECONCILE_CATALOG_PROJECTIONS,
            JobResourcePolicy::DEPENDENCY_HEAVY,
            $dependencyLimit,
            self::PROJECTION_CONCURRENCY_KEY,
        ]);
        $projectionRows = $projection->rowCount();

        $dependencyFiles = $this->db->prepare(
            'UPDATE ue_background_jobs SET resource_class=?,resource_limit=? '
            . 'WHERE queue_name=? AND status="queued" AND job_type=? '
            . 'AND (resource_class<>? OR resource_limit<>?)'
        );
        $dependencyFiles->execute([
            JobResourcePolicy::AFFECTED_DEPENDENCY_BATCH,
            $dependencyFileLimit,
            $this->queueName,
            JobType::REBUILD_FILE_DEPENDENCIES,
            JobResourcePolicy::AFFECTED_DEPENDENCY_BATCH,
            $dependencyFileLimit,
        ]);
        $dependencyFileRows = $dependencyFiles->rowCount();

        $sourceArchives = $this->db->prepare(
            'UPDATE ue_background_jobs SET resource_class=?,resource_limit=?,concurrency_key=CONCAT("import:source-job:",id) '
            . 'WHERE queue_name=? AND status="queued" AND job_type IN (?,?) '
            . 'AND (resource_class<>? OR resource_limit<>? OR concurrency_key IS NULL '
            . 'OR concurrency_key<>CONCAT("import:source-job:",id))'
        );
        $sourceArchives->execute([
            JobResourcePolicy::SOURCE_ARCHIVE_IMPORT,
            $sourceArchiveLimit,
            $this->queueName,
            JobType::IMPORT_STAGED_PAK,
            JobType::IMPORT_STAGED_ARCHIVE,
            JobResourcePolicy::SOURCE_ARCHIVE_IMPORT,
            $sourceArchiveLimit,
        ]);
        $sourceArchiveRows = $sourceArchives->rowCount();

        $updateLimit = $this->db->prepare(
            'UPDATE ue_background_jobs SET resource_limit=? '
            . 'WHERE queue_name=? AND status="queued" AND resource_class=? AND resource_limit<>?'
        );
        $updatedLimits = 0;
        $perClass = [];
        foreach ($definitions as $class => $definition) {
            $default = self::limit((int)($definition['default'] ?? 1));
            $limit = $this->limits[$class] ?? $default;
            $updateLimit->execute([$limit, $this->queueName, $class, $limit]);
            $perClass[$class] = $updateLimit->rowCount();
            $updatedLimits += $perClass[$class];
        }

        $rekeyedJobs = $this->rekeyQueuedAffectedDependencyJobs();
        return [
            'updated_jobs' => $projectionRows + $dependencyFileRows + $sourceArchiveRows + $updatedLimits,
            'updated_limits' => $updatedLimits,
            'projection_rows' => $projectionRows,
            'dependency_file_rows' => $dependencyFileRows,
            'source_archive_rows' => $sourceArchiveRows,
            'rekeyed_jobs' => $rekeyedJobs,
            'per_class' => $perClass,
        ];
    }

    /**
     * @param array<string,int> $limits
     * @return array{updated_jobs:int,updated_settings:int,rekeyed_jobs:int,per_class:array<string,int>,projection_rows:int}
     */
    public function save(array $limits, ?int $updatedBy): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'The job resource-limit migration has not been applied. Run catalog/bin/migrate.php migrate.'
            );
        }

        $definitions = JobResourcePolicy::definitions();
        $normalized = [];
        foreach ($limits as $class => $value) {
            $class = trim((string)$class);
            if (!isset($definitions[$class])) {
                throw new \InvalidArgumentException('Unknown job resource class: ' . $class);
            }
            $normalized[$class] = self::limit((int)$value);
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one job resource limit is required.');
        }

        $this->reload();
        $changed = [];
        foreach ($normalized as $class => $limit) {
            if (!array_key_exists($class, $this->limits) || $this->limits[$class] !== $limit) {
                $changed[$class] = $limit;
            }
        }

        $this->db->beginTransaction();
        try {
            $upsert = $this->db->prepare(
                'INSERT INTO ' . self::TABLE . ' (resource_class,limit_value,updated_by) VALUES (?,?,?) '
                . 'ON DUPLICATE KEY UPDATE limit_value=VALUES(limit_value),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
            );
            foreach ($changed as $class => $limit) {
                $upsert->execute([$class, $limit, $updatedBy !== null && $updatedBy > 0 ? $updatedBy : null]);
            }

            // Always synchronize queued rows, even when the numeric setting did
            // not change. Old rows may carry an obsolete class, concurrency key
            // or persisted limit from code that pre-dates the current policy.
            $sync = $this->synchronizeQueuedPolicies();
            $this->db->commit();

            if ($changed !== []) {
                $this->limits = $changed + $this->limits;
                $this->loadedAt = microtime(true);
            }

            return [
                'updated_jobs' => (int)$sync['updated_jobs'],
                'updated_settings' => count($changed),
                'rekeyed_jobs' => (int)$sync['rekeyed_jobs'],
                'per_class' => (array)$sync['per_class'],
                'projection_rows' => (int)$sync['projection_rows'],
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function rekeyQueuedAffectedDependencyJobs(): int
    {
        $gameIdExpression = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.game_id")) AS UNSIGNED)';
        $expectedKey = 'CONCAT("dependency:affected-game:",' . $gameIdExpression . ')';
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET concurrency_key=' . $expectedKey . ' '
            . 'WHERE queue_name=? AND status="queued" AND job_type=? '
            . 'AND JSON_VALID(payload_json) AND ' . $gameIdExpression . '>0 '
            . 'AND JSON_EXTRACT(payload_json,"$.affected_file_id") IS NULL '
            . 'AND JSON_EXTRACT(payload_json,"$.affected_file_ids") IS NULL '
            . 'AND (concurrency_key IS NULL OR concurrency_key<>' . $expectedKey . ')'
        );
        $statement->execute([$this->queueName, JobType::REBUILD_AFFECTED_DEPENDENCIES]);
        return $statement->rowCount();
    }

    private function reload(): void
    {
        $limits = [];
        try {
            $statement = $this->db->query('SELECT resource_class,limit_value FROM ' . self::TABLE);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $class = trim((string)($row['resource_class'] ?? ''));
                if ($class !== '') {
                    $limits[$class] = self::limit((int)($row['limit_value'] ?? 1));
                }
            }
        } catch (Throwable) {
            $this->available = false;
            $limits = [];
        }
        $this->limits = $limits;
        $this->loadedAt = microtime(true);
    }

    private static function limit(int $value): int
    {
        return max(1, min(100, $value));
    }
}
