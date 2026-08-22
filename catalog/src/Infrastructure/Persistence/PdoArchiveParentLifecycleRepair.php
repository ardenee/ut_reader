<?php
/**
 * One-way compatibility repair for archive parents completed by the legacy
 * enqueue-only lifecycle while their child jobs were still active.
 *
 * New archive parents are deferred by CatalogArchiveWorkflowJobHandler and never
 * need this repair. It exists so deploying that lifecycle fix immediately brings
 * already-active workflows back into the same invariant.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class PdoArchiveParentLifecycleRepair
{
    private const BATCH_LIMIT = 1000;
    private const MAX_BATCHES = 20;

    public function __construct(private readonly PDO $db)
    {
    }

    public function reopenCompletedParentsWithActiveChildren(string $queueName): int
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $select = $this->db->prepare(
            'SELECT DISTINCT p.id,p.result_json,p.progress_json '
            . 'FROM ue_background_jobs p '
            . 'JOIN ue_background_jobs c ON c.parent_job_id=p.id AND c.queue_name=p.queue_name '
            . 'WHERE p.queue_name=? AND p.parent_job_id IS NULL AND p.status="completed" '
            . 'AND p.job_type IN (?,?) AND c.status IN ("queued","running") '
            . 'ORDER BY p.id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $update = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=GREATEST(attempts-1,0),available_at=?,'
            . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
            . 'result_json=NULL,progress_json=?,progress_updated_at=?,completed_at=NULL,dead_lettered_at=NULL,updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status="completed"'
        );

        $reopened = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $select->execute([
                $queueName,
                JobType::PROCESS_BUCKET_ARCHIVE,
                JobType::IMPORT_STAGED_ARCHIVE,
            ]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                break;
            }

            $now = gmdate('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $id = max(0, (int)($row['id'] ?? 0));
                $archiveResult = $this->jsonObject((string)($row['result_json'] ?? ''));
                if ($id < 1 || $archiveResult === []) {
                    // Do not destroy the only terminal result if it cannot be safely
                    // transferred into coordinator progress.
                    continue;
                }
                $previous = $this->jsonObject((string)($row['progress_json'] ?? ''));
                $progress = [
                    'archive_workflow_version' => 1,
                    'stage' => 'archive_wait_children',
                    'done' => 0,
                    'total' => max(1, (int)($archiveResult['queued_files'] ?? 1)),
                    'percent' => 85,
                    'status' => 'running',
                    'message' => 'Archive child jobs are still active; parent lifecycle reopened after worker upgrade.',
                    'archive_result' => $archiveResult,
                    'entry_cursor' => max(0, (int)($archiveResult['archive_entries'] ?? 0)),
                    'queued' => max(0, (int)($archiveResult['queued_files'] ?? 0)),
                    'skipped' => max(0, (int)($archiveResult['skipped_files'] ?? 0)),
                    'failed' => max(0, (int)($archiveResult['failed_files'] ?? 0)),
                    'unpacked_bytes' => max(0, (int)($archiveResult['unpacked_bytes'] ?? 0)),
                    'errors' => is_array($archiveResult['errors'] ?? null) ? $archiveResult['errors'] : [],
                    'source_retained' => !empty($archiveResult['source_retained']),
                    'sequential_archive' => !empty($archiveResult['sequential_archive']),
                    'archive_format' => (string)($archiveResult['archive_format'] ?? ''),
                ];
                if (is_array($previous['job_telemetry'] ?? null)) {
                    $progress['job_telemetry'] = $previous['job_telemetry'];
                }

                $encoded = json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) {
                    continue;
                }
                $update->execute([$now, $encoded, $now, $now, $id, $queueName]);
                $reopened += $update->rowCount();
            }

            if (count($rows) < self::BATCH_LIMIT) {
                break;
            }
        }
        return $reopened;
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
