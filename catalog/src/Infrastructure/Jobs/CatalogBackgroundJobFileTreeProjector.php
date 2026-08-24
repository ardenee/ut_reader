<?php
/**
 * Converts hydrated durable-job rows into the small file-centric operator model
 * used by Background Jobs. Queue/worker internals remain available to controls,
 * while the visible row focuses on file identity, current action and outcome.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogBackgroundJobFileTreeProjector
{
    private const ISSUE_DISPLAY_STATUSES = ['failed', 'rejected', 'unverified', 'partial', 'error'];

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function project(array $rows): array
    {
        foreach ($rows as &$row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $progress = is_array($row['progress'] ?? null) ? $row['progress'] : [];
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            $queueStatus = strtolower(trim((string)($row['status'] ?? '')));
            $displayStatus = strtolower(trim((string)($row['display_status'] ?? $queueStatus)));
            $childCount = max(0, (int)($row['child_count'] ?? 0));
            $childIssues = max(0, (int)($row['child_issue_count'] ?? 0));
            $childActive = max(0, (int)($row['child_active_count'] ?? 0));

            $state = $this->state($queueStatus, $displayStatus, $childIssues, $childActive);
            [$fileName, $filePath] = $this->fileIdentity($payload, $row);
            $size = $this->sizeBytes($payload, $result);
            [$percent, $progressText] = $this->progress($state, $progress, $childCount, $childActive);
            $activityDetail = $this->compact((string)($progress['message'] ?? $result['message'] ?? ''), 500);
            $issueReason = ($state === 'issue' || $childIssues > 0)
                ? $this->issueReason($row, $progress, $result, $childIssues)
                : '';

            $row['file_name'] = $fileName;
            $row['file_path'] = $filePath;
            $row['size_bytes'] = $size;
            $row['operator_state'] = $state;
            $row['operator_status_label'] = match ($state) {
                'working' => $childIssues > 0 ? 'Working · issue' : 'Working',
                'completed' => 'Completed',
                'stopped' => 'Stopped',
                default => 'Issue',
            };
            $row['action_label'] = $this->actionLabel(
                (string)($row['job_type'] ?? ''),
                (string)($progress['stage'] ?? ''),
                $queueStatus,
                $displayStatus,
                $state,
                $childCount,
                $childActive
            );
            $row['activity_detail'] = $activityDetail;
            $row['progress_percent'] = $percent;
            $row['progress_text'] = $progressText;
            $row['issue_reason'] = $issueReason;
            $row['child_issue_count'] = $childIssues;
            $row['child_count'] = $childCount;
            $row['has_children'] = $childCount > 0;
            $row['result_label'] = $this->resultLabel($displayStatus, $result);
        }
        unset($row);
        return $rows;
    }

    private function state(string $queueStatus, string $displayStatus, int $childIssues, int $childActive): string
    {
        if (in_array($queueStatus, ['failed', 'dead_letter'], true)
            || in_array($displayStatus, self::ISSUE_DISPLAY_STATUSES, true)) {
            return 'issue';
        }
        if (in_array($queueStatus, ['queued', 'running'], true) || $childActive > 0) {
            return 'working';
        }
        if ($childIssues > 0) {
            return 'issue';
        }
        if ($queueStatus === 'cancelled') {
            return 'stopped';
        }
        if ($queueStatus === 'completed') {
            return 'completed';
        }
        return 'issue';
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $row @return array{0:string,1:string} */
    private function fileIdentity(array $payload, array $row): array
    {
        $path = trim(str_replace('\\', '/', (string)($payload['source_relative_path'] ?? '')));
        $name = trim((string)($payload['original_name'] ?? ''));
        if ($name === '' && $path !== '') {
            $name = basename($path);
        }
        if ($name === '') {
            $name = trim((string)($payload['package_name'] ?? ''));
        }
        if ($name === '') {
            $fileId = max(0, (int)($payload['file_id'] ?? $payload['affected_file_id'] ?? 0));
            $name = $fileId > 0 ? 'File #' . $fileId : 'Job #' . max(0, (int)($row['id'] ?? 0));
        }
        if ($path === '') {
            $path = $name;
        }
        return [$name, $path];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $result */
    private function sizeBytes(array $payload, array $result): int
    {
        foreach (['size', 'expected_size'] as $field) {
            $value = max(0, (int)($payload[$field] ?? 0));
            if ($value > 0) {
                return $value;
            }
        }
        foreach (['size', 'file_size', 'bytes', 'source_bytes'] as $field) {
            $value = max(0, (int)($result[$field] ?? 0));
            if ($value > 0) {
                return $value;
            }
        }
        return 0;
    }

    /** @param array<string,mixed> $progress @return array{0:int,1:string} */
    private function progress(string $state, array $progress, int $childCount, int $childActive): array
    {
        $hasPercent = array_key_exists('percent', $progress) && is_numeric($progress['percent']);
        $percent = $hasPercent ? max(0, min(100, (int)$progress['percent'])) : -1;
        $done = max(0, (int)($progress['done'] ?? $progress['entry_cursor'] ?? 0));
        $total = max(0, (int)($progress['total'] ?? 0));

        // Once a container has active child files, its own extraction progress is
        // no longer the meaningful operator progress. A ZIP can be 100% expanded
        // while most extracted packages are still waiting/running. Project the
        // direct child completion ratio until those children reach terminal state.
        if ($childCount > 0 && $childActive > 0 && $state === 'working') {
            $done = max(0, $childCount - $childActive);
            $total = $childCount;
            $percent = min(99, (int)floor(($done * 100) / max(1, $total)));
        } elseif ($childCount > 0 && !$hasPercent) {
            $done = max(0, $childCount - $childActive);
            $total = $childCount;
            $percent = min(100, (int)floor(($done * 100) / max(1, $total)));
        }

        if ($percent < 0) {
            $percent = $state === 'completed' ? 100 : 0;
        }
        if ($state === 'completed') {
            $percent = 100;
        }

        $text = $total > 0 ? number_format(min($done, $total)) . ' / ' . number_format($total) : $percent . '%';
        return [$percent, $text];
    }

    private function actionLabel(
        string $jobType,
        string $stage,
        string $queueStatus,
        string $displayStatus,
        string $state,
        int $childCount,
        int $childActive
    ): string {
        if ($state === 'issue' && !in_array($queueStatus, ['queued', 'running'], true)) {
            return 'Could not process';
        }
        if ($state === 'stopped') {
            return 'Stopped';
        }
        if ($state === 'completed') {
            return match ($displayStatus) {
                'duplicate' => 'Duplicate found',
                'skipped' => 'Skipped',
                'bucketed' => 'Added to Upload Bucket',
                'imported', 'verified' => 'Imported',
                'decompressed' => 'Decompressed',
                default => 'Completed',
            };
        }

        $normalized = strtolower(str_replace(['-', ' '], '_', trim($stage)));
        if ($normalized !== '') {
            if (str_contains($normalized, 'chunk')) {
                return 'Uploading chunks';
            }
            if (str_contains($normalized, 'upload')) {
                return 'Uploading';
            }
            if (str_contains($normalized, 'decompress') || str_contains($normalized, 'inflate')) {
                return 'Decompressing';
            }
            if (str_contains($normalized, 'expand_archive') || str_contains($normalized, 'extract')) {
                return 'Extracting archive';
            }
            if (str_contains($normalized, 'wait') && $childCount > 0) {
                return 'Processing extracted files';
            }
            if (str_contains($normalized, 'hash')) {
                return 'Hashing';
            }
            if (str_contains($normalized, 'scan')) {
                return 'Scanning';
            }
            if (str_contains($normalized, 'depend')) {
                return 'Scanning dependencies';
            }
            if (str_contains($normalized, 'index')) {
                return 'Indexing';
            }
            if (str_contains($normalized, 'parse') || str_contains($normalized, 'read')) {
                return 'Reading package';
            }
            if (str_contains($normalized, 'import')) {
                return 'Importing';
            }
            if (str_contains($normalized, 'prepare')) {
                return 'Preparing';
            }
        }

        if ($childActive > 0) {
            return 'Processing child files';
        }
        if ($queueStatus === 'queued') {
            return 'Waiting in queue';
        }

        return match ($jobType) {
            'catalog.prepare_bucket_redirect' => 'Decompressing redirect',
            'catalog.process_bucket_archive', 'catalog.import_staged_archive' => 'Processing archive',
            'catalog.process_bucket_upload' => 'Scanning uploaded file',
            'catalog.process_bucket_staged_package', 'catalog.import_staged_package' => 'Reading package',
            'catalog.import_staged_pak', 'catalog.import_staged_pak_entry' => 'Processing PAK',
            'catalog.full_sync_file' => 'Synchronising file',
            'catalog.full_sync_dependency_file', 'catalog.rebuild_file_dependencies' => 'Scanning dependencies',
            default => 'Processing',
        };
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $progress @param array<string,mixed> $result */
    private function issueReason(array $row, array $progress, array $result, int $childIssues): string
    {
        $lastError = $this->compact((string)($row['last_error'] ?? ''), 900);
        if ($lastError !== '') {
            return $lastError;
        }
        $resultMessage = $this->compact((string)($result['message'] ?? ''), 900);
        if ($resultMessage !== '') {
            return $resultMessage;
        }
        $progressMessage = $this->compact((string)($progress['message'] ?? ''), 900);
        if ($progressMessage !== '') {
            return $progressMessage;
        }
        if ($childIssues > 0) {
            return number_format($childIssues) . ' child file(s) need attention. Expand this row to see them.';
        }
        return 'This file did not complete successfully. Expand related work or inspect the retained source.';
    }

    /** @param array<string,mixed> $result */
    private function resultLabel(string $displayStatus, array $result): string
    {
        $status = strtolower(trim((string)($result['status'] ?? $displayStatus)));
        return match ($status) {
            'duplicate' => 'Duplicate',
            'skipped' => 'Skipped',
            'bucketed' => 'Bucketed',
            'imported', 'verified' => 'Imported',
            'decompressed' => 'Decompressed',
            default => '',
        };
    }

    private function compact(string $value, int $max): string
    {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        if ($value === '') {
            return '';
        }
        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') . '…' : $value;
    }
}
