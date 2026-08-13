<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides small indexed operational reads for durable background jobs.
 * Why: Worker/status services need exact live queue state without embedding durable-job SQL in Presentation or process orchestration.
 * Role: Infrastructure query object for live worker/queue diagnostics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCountCache;

final class PdoBackgroundJobOperationalQuery
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    /** @return array{queued:int,ready:int,running:int,terminal:int,total:int} */
    public function queueCounts(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(status="queued"),0) queued,'
            . 'COALESCE(SUM(status="running"),0) running,'
            . 'COALESCE(SUM(status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()),0) ready '
            . 'FROM ue_background_jobs WHERE queue_name=? AND status IN ("queued","running")'
        );
        $statement->execute([$queueName]);
        $live = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $queued = max(0, (int)($live['queued'] ?? 0));
        $running = max(0, (int)($live['running'] ?? 0));
        $ready = max(0, (int)($live['ready'] ?? 0));

        $terminalCounts = (new CatalogBackgroundJobCountCache($this->config))->remember(
            'worker-terminal:' . $queueName,
            function () use ($queueName): array {
                $statement = $this->db->prepare(
                    'SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=? '
                    . 'AND status IN ("completed","failed","dead_letter","cancelled")'
                );
                $statement->execute([$queueName]);
                return ['terminal' => (int)$statement->fetchColumn()];
            }
        );
        $terminal = max(0, (int)($terminalCounts['terminal'] ?? 0));
        return [
            'queued' => $queued,
            'ready' => $ready,
            'running' => $running,
            'terminal' => $terminal,
            'total' => $queued + $running + $terminal,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function runningWork(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $statement = $this->db->prepare(
            'SELECT r.id task_id,r.parent_job_id,r.job_type task_type,r.worker_id,r.leased_at,'
            . 'r.last_heartbeat_at,r.progress_updated_at,r.updated_at,r.payload_json,r.progress_json,'
            . 'COALESCE(p.id,r.id) job_id,COALESCE(p.job_type,r.job_type) job_type,p.payload_json parent_payload_json '
            . 'FROM ue_background_jobs r LEFT JOIN ue_background_jobs p ON p.id=r.parent_job_id '
            . 'WHERE r.queue_name=? AND r.status="running" ORDER BY COALESCE(r.worker_id,""),r.id LIMIT 64'
        );
        $statement->execute([$queueName]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $taskPayload = $this->jsonObject((string)($row['payload_json'] ?? ''));
            $parentPayload = $this->jsonObject((string)($row['parent_payload_json'] ?? ''));
            $progress = $this->jsonObject((string)($row['progress_json'] ?? ''));
            $taskId = max(0, (int)($row['task_id'] ?? 0));
            $jobId = max(0, (int)($row['job_id'] ?? $taskId));
            $result[] = [
                'task_id' => $taskId,
                'job_id' => $jobId,
                'is_child' => $taskId > 0 && $jobId > 0 && $taskId !== $jobId,
                'task_type' => (string)($row['task_type'] ?? ''),
                'job_type' => (string)($row['job_type'] ?? ''),
                'worker_id' => (string)($row['worker_id'] ?? ''),
                'started_at' => (string)($row['leased_at'] ?? ''),
                'last_activity_at' => (string)($row['progress_updated_at'] ?? $row['last_heartbeat_at'] ?? $row['updated_at'] ?? ''),
                'target' => $this->targetLabel($taskPayload, $taskId),
                'job_target' => $this->targetLabel($parentPayload !== [] ? $parentPayload : $taskPayload, $jobId),
                'stage' => trim((string)($progress['stage'] ?? 'running')) ?: 'running',
                'percent' => max(0, min(100, (int)($progress['percent'] ?? 0))),
                'message' => trim((string)($progress['message'] ?? '')),
            ];
        }
        return $result;
    }

    /** @return array{id:int,job_type:string,progress_json:?string,updated_at:string}|null */
    public function firstRunningJob(string $queueName): ?array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $statement = $this->db->prepare(
            'SELECT id,job_type,progress_json,updated_at FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" ORDER BY id LIMIT 1'
        );
        $statement->execute([$queueName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return [
            'id' => (int)$row['id'],
            'job_type' => (string)$row['job_type'],
            'progress_json' => $row['progress_json'] !== null ? (string)$row['progress_json'] : null,
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public function queuedCount(string $queueName): int
    {
        return $this->statusCount(PdoJobQueueSupport::requiredIdentifier($queueName, 'queue'), 'queued');
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $json): array
    {
        if ($json === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload */
    private function targetLabel(array $payload, int $fallbackId): string
    {
        foreach (['source_relative_path', 'original_name'] as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') return $value;
        }
        $affectedFileId = max(0, (int)($payload['affected_file_id'] ?? 0));
        if ($affectedFileId > 0) return 'Affected file #' . $affectedFileId;
        $fileId = max(0, (int)($payload['file_id'] ?? $payload['source_file_id'] ?? 0));
        if ($fileId > 0) return 'File #' . $fileId;
        $packageName = trim((string)($payload['package_name'] ?? ''));
        if ($packageName !== '') return $packageName;
        return $fallbackId > 0 ? 'Job #' . $fallbackId : '';
    }

    private function statusCount(string $queueName, string $status): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=? AND status=?');
        $statement->execute([$queueName, $status]);
        return (int)$statement->fetchColumn();
    }
}
