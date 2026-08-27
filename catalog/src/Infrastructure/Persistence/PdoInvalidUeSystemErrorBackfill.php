<?php
/**
 * Backfills System Errors for historical invalid Unreal package outcomes.
 *
 * Jobs are already terminal. This reads only durable job/file metadata, records
 * the invalid file once, and marks the job result so worker restarts are
 * idempotent. It never reopens package/archive source bytes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogInvalidUeFileReporter;

final class PdoInvalidUeSystemErrorBackfill
{
    private const BATCH_LIMIT = 1000;
    private const MAX_BATCHES = 100;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{recorded:int,failed:int,locked:bool} */
    public function run(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $lockName = 'unrealdb-invalid-ue-system-error:' . substr(hash('sha256', $queueName), 0, 24);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return ['recorded' => 0, 'failed' => 0, 'locked' => true];
        }

        try {
            return $this->backfill($queueName);
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable) {
            }
        }
    }

    /** @return array{recorded:int,failed:int,locked:bool} */
    private function backfill(string $queueName): array
    {
        $select = $this->db->prepare(
            'SELECT id,parent_job_id,job_type,payload_json,result_json FROM ue_background_jobs '
            . 'WHERE queue_name=? AND id>? AND status="completed" '
            . 'AND display_status="invalid_ue_package" AND JSON_VALID(result_json) '
            . 'AND COALESCE(JSON_EXTRACT(result_json,"$.system_error_recorded"),false)<>true '
            . 'ORDER BY id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $mark = $this->db->prepare(
            'UPDATE ue_background_jobs SET '
            . 'result_json=JSON_SET(result_json,"$.system_error_recorded",true,"$.system_error_type","InvalidUnrealPackage"),'
            . 'progress_json=CASE WHEN JSON_VALID(progress_json) '
            . 'THEN JSON_SET(progress_json,"$.system_error_recorded",true,"$.system_error_type","InvalidUnrealPackage") '
            . 'ELSE progress_json END,updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status="completed" AND display_status="invalid_ue_package"'
        );
        $fileIdentity = $this->db->prepare(
            'SELECT LOWER(COALESCE(md5,"")) md5,LOWER(COALESCE(sha1,"")) sha1,file_size '
            . 'FROM ue_files WHERE id=? LIMIT 1'
        );
        $parentSource = $this->db->prepare(
            'SELECT payload_json FROM ue_background_jobs WHERE id=? AND queue_name=? LIMIT 1'
        );

        $recorded = 0;
        $failed = 0;
        $afterId = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $select->execute([$queueName, $afterId]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $id = max(0, (int)($row['id'] ?? 0));
                $afterId = max($afterId, $id);
                $payload = $this->decode((string)($row['payload_json'] ?? ''));
                $result = $this->decode((string)($row['result_json'] ?? ''));
                if ($id < 1 || $result === []) {
                    continue;
                }

                $fileId = max(0, (int)($result['file_id'] ?? 0));
                $identity = ['md5' => '', 'sha1' => '', 'file_size' => 0];
                if ($fileId > 0) {
                    $fileIdentity->execute([$fileId]);
                    $found = $fileIdentity->fetch(PDO::FETCH_ASSOC);
                    if (is_array($found)) {
                        $identity = $found;
                    }
                }

                $parentJobId = max(0, (int)($row['parent_job_id'] ?? 0));
                $archiveSourceName = trim((string)($payload['archive_source_name'] ?? ''));
                if ($archiveSourceName === '' && $parentJobId > 0) {
                    $parentSource->execute([$parentJobId, $queueName]);
                    $parentPayload = $this->decode((string)($parentSource->fetchColumn() ?: ''));
                    $archiveSourceName = trim((string)($parentPayload['original_name'] ?? ''));
                }

                $fileName = trim((string)($result['original_name'] ?? $payload['original_name'] ?? ''));
                $sourceRelativePath = trim((string)(
                    $result['source_relative_path'] ?? $payload['source_relative_path'] ?? $fileName
                ));
                $reason = trim((string)($result['message'] ?? 'Invalid Unreal package.'));

                $ok = CatalogInvalidUeFileReporter::record([
                    'job_id' => $id,
                    'parent_job_id' => $parentJobId,
                    'job_type' => (string)($row['job_type'] ?? ''),
                    'user_id' => max(0, (int)($payload['user_id'] ?? 0)),
                    'game_id' => max(0, (int)($payload['game_id'] ?? 0)),
                    'file_id' => $fileId,
                    'file_name' => $fileName,
                    'source_relative_path' => $sourceRelativePath,
                    'archive_source_name' => $archiveSourceName,
                    'archive_entry_path' => (string)($payload['archive_entry_path'] ?? $fileName),
                    'size' => max(0, (int)($identity['file_size'] ?? $result['bytes'] ?? 0)),
                    'md5' => (string)($result['md5'] ?? $identity['md5'] ?? ''),
                    'sha1' => (string)($result['sha1'] ?? $identity['sha1'] ?? ''),
                    'reason' => $reason,
                ]);
                if (!$ok) {
                    $failed++;
                    continue;
                }

                $mark->execute([gmdate('Y-m-d H:i:s'), $id, $queueName]);
                if ($mark->rowCount() > 0) {
                    $recorded++;
                }
            }

            if (count($rows) < self::BATCH_LIMIT) {
                break;
            }
        }

        return ['recorded' => $recorded, 'failed' => $failed, 'locked' => false];
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
