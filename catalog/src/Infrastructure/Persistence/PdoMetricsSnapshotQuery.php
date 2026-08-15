<?php
/**
 * Database projection for the Prometheus-style metrics endpoint.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoMetricsSnapshotQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function jobs(): array
    {
        $statement = $this->db->query(
            'SELECT queue_name,status,resource_class,COUNT(*) count,COALESCE(SUM(recovery_count),0) recoveries '
            . 'FROM ue_background_jobs GROUP BY queue_name,status,resource_class ORDER BY queue_name,status,resource_class'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function oldestQueued(): array
    {
        $statement = $this->db->query(
            'SELECT queue_name,COALESCE(TIMESTAMPDIFF(SECOND,MIN(created_at),UTC_TIMESTAMP()),0) age_seconds '
            . 'FROM ue_background_jobs WHERE status="queued" GROUP BY queue_name'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function files(): array
    {
        $statement = $this->db->query(
            'SELECT scan_status,COUNT(*) count,COALESCE(SUM(file_size),0) bytes FROM ue_files GROUP BY scan_status'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
