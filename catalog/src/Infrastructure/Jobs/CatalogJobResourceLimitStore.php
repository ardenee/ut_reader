<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;

/**
 * Persists administrator-selected job-class limits and applies them to the
 * durable queue. A small JSON projection lets legacy pages that construct a
 * queue directly use the same limits without opening another settings query.
 */
final class CatalogJobResourceLimitStore
{
    private const TABLE = 'ue_job_resource_limits';
    private const CACHE_SECONDS = 5.0;

    /** @var array<string,int> */
    private array $limits = [];
    private float $loadedAt = 0.0;
    private ?bool $available = null;

    public function __construct(
        private readonly PDO $db,
        private readonly ?string $settingsFile = null
    ) {
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
        $statement = $this->db->query(
            'SELECT resource_class,'
            . 'SUM(status="running") running_jobs,'
            . 'SUM(status="queued") queued_jobs,'
            . 'SUM(status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()) ready_jobs '
            . 'FROM ue_background_jobs WHERE status IN ("queued","running") GROUP BY resource_class'
        );
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
                'is_limiting' => $ready > 0 && $running >= $limit,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,int> $limits
     * @return array{updated_jobs:int,updated_settings:int,per_class:array<string,int>}
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

        $this->db->beginTransaction();
        try {
            $upsert = $this->db->prepare(
                'INSERT INTO ' . self::TABLE . ' (resource_class,limit_value,updated_by) VALUES (?,?,?) '
                . 'ON DUPLICATE KEY UPDATE limit_value=VALUES(limit_value),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
            );
            $updateJobs = $this->db->prepare(
                'UPDATE ue_background_jobs SET resource_limit=?,updated_at=updated_at '
                . 'WHERE resource_class=? AND status IN ("queued","running")'
            );

            $updatedJobs = 0;
            $perClass = [];
            foreach ($normalized as $class => $limit) {
                $upsert->execute([$class, $limit, $updatedBy !== null && $updatedBy > 0 ? $updatedBy : null]);
                $updateJobs->execute([$limit, $class]);
                $perClass[$class] = $updateJobs->rowCount();
                $updatedJobs += $perClass[$class];
            }
            $this->db->commit();
            $this->limits = $normalized + $this->limits;
            $this->loadedAt = microtime(true);
            $this->writeSettingsFile($this->limits);

            return [
                'updated_jobs' => $updatedJobs,
                'updated_settings' => count($normalized),
                'per_class' => $perClass,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
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

    /** @param array<string,int> $limits */
    private function writeSettingsFile(array $limits): void
    {
        $path = trim((string)$this->settingsFile);
        if ($path === '') {
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the job resource-limit settings directory: ' . $directory);
        }

        ksort($limits, SORT_STRING);
        $json = json_encode([
            'version' => 1,
            'updated_at' => gmdate(DATE_ATOM),
            'limits' => $limits,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the job resource-limit settings file.');
        }
        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not publish the job resource-limit settings file.');
        }
    }

    private static function limit(int $value): int
    {
        return max(1, min(100, $value));
    }
}
