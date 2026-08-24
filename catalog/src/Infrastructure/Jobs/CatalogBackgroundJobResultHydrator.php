<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Hydrates bounded durable-job rows into the stable admin/API payload shape.
 * Why: Cursor and offset job endpoints previously duplicated payload/progress/result decoding and integrity checks.
 * Role: Infrastructure read-model mapper; no SQL or HTTP concerns.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogBackgroundJobResultHydrator
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function hydrate(array $rows): array
    {
        foreach ($rows as &$row) {
            $payload = $this->payload((string)($row['payload_json'] ?? ''));
            unset($row['payload_json']);
            $row['payload'] = $payload;

            $progress = $this->jsonObject((string)($row['progress_json'] ?? ''));
            unset($row['progress_json']);

            $result = $this->jsonObject((string)($row['result_json'] ?? ''));
            unset($row['result_json']);

            if (is_array($result)) {
                $this->normalizeResult($row, $payload, $progress, $result);
            }

            $failureText = self::failureText($row, $progress, $result);
            $retryBlocked = JobFailureRetryPolicy::isDeterministicFailureText(
                (string)($row['job_type'] ?? ''),
                $failureText
            );
            $row['retry_blocked'] = $retryBlocked;
            $row['retry_block_reason'] = $retryBlocked
                ? 'The retained source bytes contradict their package/archive metadata; restarting cannot change this result.'
                : '';
            $row['progress'] = $progress;
            $row['result'] = $result;
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function payload(string $json): array
    {
        $decoded = $this->jsonObject($json);
        if (!is_array($decoded)) {
            return [];
        }
        $payload = [];
        foreach ([
            'original_name',
            'source_relative_path',
            'queue_name',
            'queue_game_id',
            'game_id',
            'file_id',
            'expected_size',
            'max_files',
            'package_name',
            'affected_total',
            'affected_file_id',
            'source_file_id',
            'workflow_parent_job_id',
            'pak_id',
            'entry_index',
            'reconcile_game_id',
            'reconcile_queue_name',
            'prune_unit',
            'batch_number',
            'batch_count',
            'batch_start',
            'batch_end',
            'archive_source_name',
            'archive_entry_path',
        ] as $field) {
            if (array_key_exists($field, $decoded)) {
                $payload[$field] = $decoded[$field];
            }
        }

        $this->addChildDisplayLabel($payload, $decoded);
        return $payload;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $decoded */
    private function addChildDisplayLabel(array &$payload, array $decoded): void
    {
        if (trim((string)($payload['source_relative_path'] ?? '')) !== '') {
            return;
        }

        $parentId = max(0, (int)($decoded['workflow_parent_job_id'] ?? 0));
        $affectedFileId = max(0, (int)($decoded['affected_file_id'] ?? 0));
        if ($affectedFileId > 0) {
            $package = trim((string)($decoded['package_name'] ?? ''));
            $provider = max(0, (int)($decoded['file_id'] ?? 0));
            $label = ($package !== '' ? $package . ' · ' : '')
                . ($provider > 0 ? 'provider #' . $provider . ' · ' : '')
                . 'affected file #' . $affectedFileId;
            if ($parentId > 0) {
                $label .= ' · workflow #' . $parentId;
            }
            $payload['source_relative_path'] = $label;
            return;
        }

        $pakId = max(0, (int)($decoded['pak_id'] ?? 0));
        $entryIndex = (int)($decoded['entry_index'] ?? -1);
        if ($pakId > 0 && $entryIndex >= 0) {
            $label = 'PAK #' . $pakId . ' · entry ' . ($entryIndex + 1);
            if ($parentId > 0) {
                $label .= ' · workflow #' . $parentId;
            }
            $payload['source_relative_path'] = $label;
            return;
        }

        $sourceFileId = max(0, (int)($decoded['source_file_id'] ?? 0));
        if ($sourceFileId > 0 && $parentId > 0) {
            $payload['source_relative_path'] = 'Source file #' . $sourceFileId . ' · workflow #' . $parentId;
            return;
        }

        $reconcileName = trim((string)($decoded['reconcile_queue_name'] ?? ''));
        if ($reconcileName !== '') {
            $payload['source_relative_path'] = $reconcileName
                . ' · unverified game #' . max(0, (int)($decoded['reconcile_game_id'] ?? 0))
                . ($parentId > 0 ? ' · workflow #' . $parentId : '');
            return;
        }

        $pruneUnit = trim((string)($decoded['prune_unit'] ?? ''));
        if ($pruneUnit !== '') {
            $payload['source_relative_path'] = 'Artifact cleanup · ' . $pruneUnit
                . ($parentId > 0 ? ' · workflow #' . $parentId : '');
            return;
        }

        // Compatibility reporting for affected-dependency children created by
        // the previous 50-file batch implementation.
        if (array_key_exists('affected_file_ids', $decoded)
            && (int)($decoded['file_id'] ?? 0) > 0) {
            $label = '';
            $packageName = trim((string)($decoded['package_name'] ?? ''));
            if ($packageName !== '') {
                $label .= $packageName . ' · ';
            }
            $label .= 'provider #' . (int)$decoded['file_id'];

            $batchNumber = max(0, (int)($decoded['batch_number'] ?? 0));
            $batchCount = max(0, (int)($decoded['batch_count'] ?? 0));
            if ($batchNumber > 0) {
                $label .= ' · batch ' . $batchNumber . ($batchCount > 0 ? '/' . $batchCount : '');
            }

            $batchStart = max(0, (int)($decoded['batch_start'] ?? 0));
            $batchEnd = max(0, (int)($decoded['batch_end'] ?? 0));
            $affectedTotal = max(0, (int)($decoded['affected_total'] ?? 0));
            if ($batchStart > 0 && $batchEnd >= $batchStart) {
                $label .= ' · affected positions ' . $batchStart . '-' . $batchEnd;
                if ($affectedTotal > 0) {
                    $label .= '/' . $affectedTotal;
                }
            }
            $payload['source_relative_path'] = $label;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $progress
     * @param array<string,mixed>|null $result
     */
    private static function failureText(array $row, ?array $progress, ?array $result): string
    {
        $lastError = trim((string)($row['last_error'] ?? ''));
        if ($lastError !== '') {
            return $lastError;
        }
        foreach ([$result, $progress] as $state) {
            if (!is_array($state)) {
                continue;
            }
            $message = trim((string)($state['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
            $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $error = trim((string)($first['error'] ?? ''));
            if ($error !== '') {
                return $error;
            }
        }
        return '';
    }

    /** @return array<string,mixed>|null */
    private function jsonObject(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $progress
     * @param array<string,mixed> $result
     */
    private function normalizeResult(array $row, array $payload, ?array &$progress, array &$result): void
    {
        $expectedJobId = (int)$row['id'];
        $resultJobId = (int)($result['job_id'] ?? 0);
        $jobType = (string)($row['job_type'] ?? '');
        $expectedName = trim((string)($payload['original_name'] ?? ''));
        $resultName = trim((string)($result['original_name'] ?? $result['job_original_name'] ?? ''));
        $jobMismatch = $resultJobId > 0 && $resultJobId !== $expectedJobId;
        $nameMismatch = !$this->namesMatch($jobType, $expectedName, $resultName, $result);

        if ($jobMismatch || $nameMismatch) {
            $details = [];
            if ($jobMismatch) {
                $details[] = 'result belongs to job #' . $resultJobId;
            }
            if ($nameMismatch) {
                $details[] = 'result names ' . $resultName . ' instead of ' . $expectedName;
            }
            $retryInstruction = $jobType === JobType::REPAIR_UNVERIFIED_METADATA
                ? 'Re-run this metadata repair.'
                : 'Restart this job.';
            $result = [
                'status' => 'failed',
                'message' => 'Stored result identity mismatch for job #' . $expectedJobId . ': '
                    . implode('; ', $details) . '. ' . $retryInstruction,
                'integrity_mismatch' => true,
                'job_id' => $expectedJobId,
                'job_original_name' => $expectedName,
            ];
            return;
        }

        if ($jobType === JobType::REPAIR_UNVERIFIED_METADATA) {
            $displayName = $expectedName !== '' ? $expectedName : ($resultName !== '' ? $resultName : 'this file');
            $parseError = trim((string)($result['parse_error'] ?? ''));
            if ($parseError !== '') {
                $result['message'] = 'Basic metadata was repaired for ' . $displayName
                    . ', but package tables remain unreadable: ' . $parseError;
            } elseif (array_key_exists('name_count', $result)
                && array_key_exists('import_count', $result)
                && array_key_exists('export_count', $result)) {
                $result['message'] = 'Metadata repair completed for ' . $displayName . ': Header, '
                    . (int)$result['name_count'] . ' Names, '
                    . (int)$result['import_count'] . ' Imports and '
                    . (int)$result['export_count'] . ' Exports recorded.';
            }
        } elseif (in_array($jobType, [JobType::PROCESS_BUCKET_UPLOAD, JobType::PROCESS_BUCKET_STAGED_PACKAGE], true)
            && strtolower(trim((string)($result['status'] ?? ''))) === 'bucketed') {
            $this->appendBucketPhysicalState($progress, $result);
        }

        $resultStatus = strtolower(trim((string)($result['status'] ?? '')));
        $successfulCompletion = (string)($row['status'] ?? '') === 'completed'
            && empty($result['integrity_mismatch'])
            && trim((string)($result['parse_error'] ?? '')) === ''
            && !in_array($resultStatus, ['failed', 'rejected', 'unverified', 'error'], true);
        if ($successfulCompletion) {
            $completionMessage = trim((string)($result['message'] ?? ''));
            if ($completionMessage !== '') {
                if (!is_array($progress)) {
                    $progress = [];
                }
                if (trim((string)($progress['message'] ?? '')) === '') {
                    $progress['message'] = $completionMessage;
                }
            }
        }

        if ((string)($row['status'] ?? '') === 'completed' && empty($result['integrity_mismatch'])) {
            $resultMessage = trim((string)($result['message'] ?? ''));
            $progressMessage = is_array($progress) ? trim((string)($progress['message'] ?? '')) : '';
            $normalizedResult = preg_replace('/\s+/', ' ', $resultMessage) ?? $resultMessage;
            $normalizedProgress = preg_replace('/\s+/', ' ', $progressMessage) ?? $progressMessage;
            if ($resultMessage !== '' && $progressMessage !== '' && $normalizedResult === $normalizedProgress) {
                unset($result['message']);
            } elseif ($successfulCompletion) {
                unset($result['message']);
            }
        }
    }

    /** @param array<string,mixed> $result */
    private function namesMatch(string $jobType, string $expectedName, string $resultName, array $result): bool
    {
        if ($expectedName === '' || $resultName === '' || strcasecmp($expectedName, $resultName) === 0) {
            return true;
        }

        $extension = strtolower((string)pathinfo($expectedName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['uz', 'uz2', 'uz3'], true)) {
            return false;
        }
        $packageName = substr($expectedName, 0, -strlen('.' . $extension));
        if (!is_string($packageName) || $packageName === '' || strcasecmp($packageName, $resultName) !== 0) {
            return false;
        }

        if ($jobType === JobType::IMPORT_STAGED_PACKAGE) {
            if (empty($result['decompressed'])) {
                return false;
            }
            $redirectSourceName = trim((string)($result['redirect_source_name'] ?? ''));
            return $redirectSourceName === '' || strcasecmp($redirectSourceName, $expectedName) === 0;
        }

        return in_array($jobType, [JobType::PROCESS_BUCKET_UPLOAD, JobType::PROCESS_BUCKET_STAGED_PACKAGE], true)
            && trim((string)($result['decoder'] ?? '')) !== '';
    }

    /** @param array<string,mixed>|null $progress @param array<string,mixed> $result */
    private function appendBucketPhysicalState(?array &$progress, array &$result): void
    {
        $queueFile = basename((string)($result['queue_name'] ?? ''));
        $storageRoot = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($queueFile === '' || $storageRoot === '') {
            return;
        }
        $physicalPath = $storageRoot . DIRECTORY_SEPARATOR . 'upload-bucket' . DIRECTORY_SEPARATOR . $queueFile;
        $physicalExists = is_file($physicalPath);
        $result['physical_path'] = $physicalPath;
        $result['physical_exists'] = $physicalExists;
        if (!is_array($progress)) {
            $progress = [];
        }
        $currentMessage = trim((string)($progress['message'] ?? $result['message'] ?? 'Upload Bucket processing completed.'));
        $pathMessage = $physicalExists
            ? 'Stored at: ' . $physicalPath
            : 'Expected physical file is missing: ' . $physicalPath;
        if (!str_contains($currentMessage, $physicalPath)) {
            $progress['message'] = rtrim($currentMessage, " \t\n\r\0\x0B.") . '. ' . $pathMessage;
        }
    }
}
