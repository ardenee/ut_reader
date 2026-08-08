<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides bounded typed reads for durable background jobs used by feature-specific controllers.
 * Why: Presentation code must not duplicate ue_background_jobs SQL merely to authorize, poll or summarize known jobs.
 * Role: Infrastructure read model for exact job lookup and small typed job lists.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobLookupQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByIdAndType(int $jobId, string $jobType): ?array
    {
        if ($jobId < 1) {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT ' . $this->columns() . ' FROM ue_background_jobs WHERE id=? AND job_type=? LIMIT 1'
        );
        $statement->execute([$jobId, $this->jobType($jobType)]);
        return $this->one($statement);
    }

    /** @return array<string,mixed>|null */
    public function findByIdAndQueue(int $jobId, string $queueName): ?array
    {
        if ($jobId < 1) {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT ' . $this->columns() . ' FROM ue_background_jobs WHERE id=? AND queue_name=? LIMIT 1'
        );
        $statement->execute([$jobId, $this->queueName($queueName)]);
        return $this->one($statement);
    }

    /**
     * @param list<string> $jobTypes
     * @return list<array<string,mixed>>
     */
    public function recentByTypes(array $jobTypes, int $limit = 20): array
    {
        $types = $this->jobTypes($jobTypes);
        $limit = max(1, min(200, $limit));
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $statement = $this->db->prepare(
            'SELECT ' . $this->columns() . ' FROM ue_background_jobs '
            . 'WHERE job_type IN (' . $placeholders . ') ORDER BY id DESC LIMIT ' . $limit
        );
        $statement->execute($types);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<string> $jobTypes
     * @return list<array<string,mixed>>
     */
    public function activeByTypes(array $jobTypes): array
    {
        $types = $this->jobTypes($jobTypes);
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $statement = $this->db->prepare(
            'SELECT ' . $this->columns() . ' FROM ue_background_jobs '
            . 'WHERE job_type IN (' . $placeholders . ') '
            . 'AND status IN ("queued","running") ORDER BY id'
        );
        $statement->execute($types);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<string> $jobTypes */
    public function hasActiveByTypes(array $jobTypes): bool
    {
        $types = $this->jobTypes($jobTypes);
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $statement = $this->db->prepare(
            'SELECT 1 FROM ue_background_jobs WHERE job_type IN (' . $placeholders . ') '
            . 'AND status IN ("queued","running") LIMIT 1'
        );
        $statement->execute($types);
        return $statement->fetchColumn() !== false;
    }

    private function columns(): string
    {
        return 'id,queue_name,job_type,priority,max_attempts,payload_json,status,progress_json,result_json,last_error,'
            . 'cancel_requested_at,created_at,updated_at,completed_at';
    }

    /** @return array<string,mixed>|null */
    private function one(\PDOStatement $statement): ?array
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function jobType(string $jobType): string
    {
        $jobType = trim($jobType);
        if ($jobType === '' || strlen($jobType) > 100 || preg_match('/^[A-Za-z0-9._:-]+$/', $jobType) !== 1) {
            throw new \InvalidArgumentException('A valid background-job type is required.');
        }
        return $jobType;
    }

    private function queueName(string $queueName): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \InvalidArgumentException('A valid background-job queue name is required.');
        }
        return $queueName;
    }

    /** @param list<string> $jobTypes @return list<string> */
    private function jobTypes(array $jobTypes): array
    {
        $normalized = [];
        foreach ($jobTypes as $jobType) {
            $value = $this->jobType((string)$jobType);
            $normalized[$value] = true;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('At least one background-job type is required.');
        }
        return array_keys($normalized);
    }
}
