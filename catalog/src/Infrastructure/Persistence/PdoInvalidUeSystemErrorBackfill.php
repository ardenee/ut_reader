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
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier;
use UnrealDb\Catalog\Application\Telemetry\CatalogSystemErrorNormalizer;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogInvalidUeFileReporter;

final class PdoInvalidUeSystemErrorBackfill
{
    private const BATCH_LIMIT = 1000;
    private const MAX_BATCHES = 100;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{recorded:int,historical_terminal_recorded:int,provenance_normalized:int,validation_records_normalized:int,failed:int,locked:bool} */
    public function run(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $lockName = 'unrealdb-invalid-ue-system-error:' . substr(hash('sha256', $queueName), 0, 24);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return ['recorded' => 0, 'historical_terminal_recorded' => 0, 'provenance_normalized' => 0, 'validation_records_normalized' => 0, 'failed' => 0, 'locked' => true];
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
                    'error_code' => trim((string)($result['validation_code'] ?? '')),
                    'arguments' => is_array($result['validation_arguments'] ?? null)
                        ? $result['validation_arguments']
                        : [],
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

        $historicalTerminalRecorded = 0;
        $terminalSelect = $this->db->prepare(
            'SELECT id,parent_job_id,job_type,payload_json,result_json,last_error FROM ue_background_jobs '
            . 'WHERE queue_name=? AND id>? AND status IN ("failed","dead_letter") '
            . 'AND job_type IN (?,?,?) AND COALESCE(last_error,"")<>"" '
            . 'AND (NOT JSON_VALID(result_json) OR COALESCE(JSON_EXTRACT(result_json,"$.system_error_recorded"),false)<>true) '
            . 'ORDER BY id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $terminalMark = $this->db->prepare(
            'UPDATE ue_background_jobs SET '
            . 'result_json=JSON_SET(CASE WHEN JSON_VALID(result_json) THEN result_json ELSE JSON_OBJECT() END,'
            . '"$.system_error_recorded",true,"$.system_error_type","InvalidUnrealPackage"),updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status IN ("failed","dead_letter")'
        );

        $afterTerminalId = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $terminalSelect->execute([
                $queueName,
                $afterTerminalId,
                JobType::PROCESS_BUCKET_UPLOAD,
                JobType::PROCESS_BUCKET_STAGED_PACKAGE,
                JobType::IMPORT_STAGED_PACKAGE,
            ]);
            $terminalRows = $terminalSelect->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($terminalRows === []) {
                break;
            }

            foreach ($terminalRows as $row) {
                $id = max(0, (int)($row['id'] ?? 0));
                $afterTerminalId = max($afterTerminalId, $id);
                $jobType = trim((string)($row['job_type'] ?? ''));
                $reason = trim((string)($row['last_error'] ?? ''));
                if ($id < 1 || !JobFailureRetryPolicy::isInvalidPackageContentText($jobType, $reason)) {
                    continue;
                }

                $payload = $this->decode((string)($row['payload_json'] ?? ''));
                $result = $this->decode((string)($row['result_json'] ?? ''));
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

                $fileName = trim((string)($payload['original_name'] ?? ''));
                $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? $fileName));
                $validation = CatalogInvalidUeErrorClassifier::classify(
                    $reason,
                    trim((string)($result['validation_code'] ?? '')),
                    is_array($result['validation_arguments'] ?? null)
                        ? $result['validation_arguments']
                        : []
                );
                $ok = CatalogInvalidUeFileReporter::record([
                    'job_id' => $id,
                    'parent_job_id' => $parentJobId,
                    'job_type' => $jobType,
                    'user_id' => max(0, (int)($payload['user_id'] ?? 0)),
                    'game_id' => max(0, (int)($payload['game_id'] ?? 0)),
                    'file_id' => $fileId,
                    'file_name' => $fileName,
                    'source_relative_path' => $sourceRelativePath,
                    'archive_source_name' => $archiveSourceName,
                    'archive_entry_path' => (string)($payload['archive_entry_path'] ?? $fileName),
                    'size' => max(0, (int)($result['bytes'] ?? $payload['size'] ?? $identity['file_size'] ?? 0)),
                    'md5' => (string)($result['md5'] ?? $payload['md5'] ?? $identity['md5'] ?? ''),
                    'sha1' => (string)($result['sha1'] ?? $payload['sha1'] ?? $identity['sha1'] ?? ''),
                    'reason' => $validation['reason'],
                    'error_code' => $validation['code'],
                    'arguments' => $validation['arguments'],
                ]);
                if (!$ok) {
                    $failed++;
                    continue;
                }

                $terminalMark->execute([gmdate('Y-m-d H:i:s'), $id, $queueName]);
                if ($terminalMark->rowCount() > 0) {
                    $historicalTerminalRecorded++;
                }
            }

            if (count($terminalRows) < self::BATCH_LIMIT) {
                break;
            }
        }

        $provenanceNormalized = $this->normalizeRecordedProvenance();
        $validationRecordsNormalized = $this->normalizeRecordedValidationErrors();

        return [
            'recorded' => $recorded,
            'historical_terminal_recorded' => $historicalTerminalRecorded,
            'provenance_normalized' => $provenanceNormalized,
            'validation_records_normalized' => $validationRecordsNormalized,
            'failed' => $failed,
            'locked' => false,
        ];
    }

    private function normalizeRecordedValidationErrors(): int
    {
        $rows = $this->db->query(
            'SELECT * FROM ue_system_errors '
            . 'WHERE source_kind="unreal-file-validation" '
            . 'AND (error_type="InvalidUnrealPackage" '
            . 'OR error_type NOT LIKE "InvalidUnrealPackage.%" '
            . 'OR COALESCE(trace_text,"")<>"") '
            . 'ORDER BY id ASC LIMIT 100000'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return 0;
        }

        $findDuplicate = $this->db->prepare(
            'SELECT id FROM ue_system_errors WHERE error_key=? AND id<>? LIMIT 1'
        );
        $update = $this->db->prepare(
            'UPDATE ue_system_errors SET error_key=?,error_type=?,message=?,route=?,request_method=?,http_status=?,'
            . 'source_file="",source_line=0,trace_text=NULL,context_json=?,request_id=?,user_id=? WHERE id=?'
        );
        $merge = $this->db->prepare(
            'UPDATE ue_system_errors SET occurrence_count=occurrence_count+?,'
            . 'first_seen_at=LEAST(first_seen_at,?),last_seen_at=GREATEST(last_seen_at,?),'
            . 'status=CASE WHEN status="open" OR ?="open" THEN "open" ELSE status END '
            . 'WHERE id=?'
        );
        $delete = $this->db->prepare('DELETE FROM ue_system_errors WHERE id=?');

        $normalizedCount = 0;
        foreach ($rows as $row) {
            $id = max(0, (int)($row['id'] ?? 0));
            if ($id < 1) {
                continue;
            }

            $context = $this->decode((string)($row['context_json'] ?? ''));
            $arguments = is_array($context['validation_arguments'] ?? null)
                ? $context['validation_arguments']
                : [];
            $classified = CatalogInvalidUeErrorClassifier::classify(
                (string)($row['message'] ?? ''),
                trim((string)($context['validation_code'] ?? '')),
                $arguments
            );
            $fileName = trim((string)($context['file_name'] ?? ''));
            if ($fileName === '') {
                $fileName = 'unknown Unreal file';
            }
            $context['validation_code'] = $classified['code'];
            $context['validation_group'] = $classified['group'];
            $context['validation_arguments'] = $classified['arguments'];

            $normalized = CatalogSystemErrorNormalizer::normalize([
                'source_kind' => 'unreal-file-validation',
                'severity' => (string)($row['severity'] ?? 'error'),
                'error_type' => $classified['error_type'],
                'message' => $fileName . ': ' . $classified['reason'],
                'route' => (string)($row['route'] ?? ''),
                'request_method' => (string)($row['request_method'] ?? ''),
                'http_status' => (int)($row['http_status'] ?? 0),
                'source_file' => '',
                'source_line' => 0,
                'trace_text' => '',
                'context' => $context,
                'request_id' => (string)($row['request_id'] ?? ''),
                'user_id' => max(0, (int)($row['user_id'] ?? 0)),
            ]);

            $alreadyNormalized = (string)($row['error_key'] ?? '') === $normalized['error_key']
                && (string)($row['error_type'] ?? '') === $normalized['error_type']
                && (string)($row['message'] ?? '') === $normalized['message']
                && trim((string)($row['trace_text'] ?? '')) === ''
                && (string)($row['source_file'] ?? '') === ''
                && (int)($row['source_line'] ?? 0) === 0;
            if ($alreadyNormalized) {
                continue;
            }

            $this->db->beginTransaction();
            try {
                $findDuplicate->execute([$normalized['error_key'], $id]);
                $duplicateId = max(0, (int)($findDuplicate->fetchColumn() ?: 0));
                if ($duplicateId > 0) {
                    $merge->execute([
                        max(1, (int)($row['occurrence_count'] ?? 1)),
                        (string)($row['first_seen_at'] ?? gmdate('Y-m-d H:i:s')),
                        (string)($row['last_seen_at'] ?? gmdate('Y-m-d H:i:s')),
                        (string)($row['status'] ?? 'open'),
                        $duplicateId,
                    ]);
                    $delete->execute([$id]);
                } else {
                    $update->execute([
                        $normalized['error_key'],
                        $normalized['error_type'],
                        $normalized['message'],
                        $normalized['route'],
                        $normalized['request_method'],
                        $normalized['http_status'],
                        $normalized['context_json'] !== '' ? $normalized['context_json'] : null,
                        $normalized['request_id'],
                        $normalized['user_id'] > 0 ? $normalized['user_id'] : null,
                        $id,
                    ]);
                }
                $this->db->commit();
                $normalizedCount++;
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }

        return $normalizedCount;
    }

    private function normalizeRecordedProvenance(): int
    {
        $statement = $this->db->prepare(
            'UPDATE ue_system_errors SET context_json=CASE '
            . 'WHEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.parent_job_id")) AS UNSIGNED),0)=0 '
            . 'AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.archive_source_name")),"")="" '
            . 'THEN JSON_REMOVE(JSON_SET(context_json,"$.source_provenance","direct_file"),'
            . '"$.archive_source_name","$.archive_entry_path") '
            . 'ELSE JSON_SET(context_json,"$.source_provenance","archive_member") END '
            . 'WHERE source_kind="unreal-file-validation" AND error_type="InvalidUnrealPackage" '
            . 'AND JSON_VALID(context_json) AND ('
            . 'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.source_provenance")),"")="" '
            . 'OR (COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.parent_job_id")) AS UNSIGNED),0)=0 '
            . 'AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(context_json,"$.archive_source_name")),"")="" '
            . 'AND (JSON_CONTAINS_PATH(context_json,"one","$.archive_source_name")=1 '
            . 'OR JSON_CONTAINS_PATH(context_json,"one","$.archive_entry_path")=1)))'
        );
        $statement->execute();
        return max(0, $statement->rowCount());
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
