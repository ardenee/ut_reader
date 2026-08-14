<?php
/**
 * Persists administrator-selected resource limits without touching queue rows.
 *
 * Queue admission reads the current saved limit when a job is claimed, so a
 * settings change no longer needs to rewrite a large queued backlog.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;

final class CatalogJobResourceLimitWriter
{
    private const TABLE = 'ue_job_resource_limits';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string,int> $limits
     * @return array{updated_jobs:int,updated_settings:int,rekeyed_jobs:int,per_class:array<string,int>,projection_rows:int}
     */
    public function save(array $limits, ?int $updatedBy): array
    {
        $definitions = JobResourcePolicy::definitions();
        $normalized = [];
        foreach ($limits as $class => $value) {
            $class = trim((string)$class);
            if (!isset($definitions[$class])) {
                throw new \InvalidArgumentException('Unknown job resource class: ' . $class);
            }
            $normalized[$class] = max(1, min(100, (int)$value));
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one job resource limit is required.');
        }

        $this->db->beginTransaction();
        try {
            $current = [];
            $statement = $this->db->query('SELECT resource_class,limit_value FROM ' . self::TABLE);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $class = trim((string)($row['resource_class'] ?? ''));
                if ($class !== '') {
                    $current[$class] = max(1, min(100, (int)($row['limit_value'] ?? 1)));
                }
            }

            $upsert = $this->db->prepare(
                'INSERT INTO ' . self::TABLE . ' (resource_class,limit_value,updated_by) VALUES (?,?,?) '
                . 'ON DUPLICATE KEY UPDATE limit_value=VALUES(limit_value),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
            );
            $changed = 0;
            foreach ($normalized as $class => $limit) {
                if (array_key_exists($class, $current) && $current[$class] === $limit) {
                    continue;
                }
                $upsert->execute([$class, $limit, $updatedBy !== null && $updatedBy > 0 ? $updatedBy : null]);
                $changed++;
            }

            $this->db->commit();
            return [
                'updated_jobs' => 0,
                'updated_settings' => $changed,
                'rekeyed_jobs' => 0,
                'per_class' => [],
                'projection_rows' => 0,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
