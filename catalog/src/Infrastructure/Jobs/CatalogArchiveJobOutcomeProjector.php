<?php
/**
 * Projects hidden archive-member child outcomes onto visible archive parent jobs.
 *
 * Archive extraction intentionally delegates each Unreal member to a child job.
 * Operator pages fold routine children into their parent, so without this read
 * projection a completed archive can misleadingly report only "N files queued"
 * long after those children became bucketed, duplicate or failed.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogArchiveJobOutcomeProjector
{
    private const MAX_VISIBLE_FAILURES = 10;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function project(array $rows): array
    {
        $parentIds = [];
        foreach ($rows as $row) {
            if (in_array((string)($row['job_type'] ?? ''), [
                JobType::PROCESS_BUCKET_ARCHIVE,
                JobType::IMPORT_STAGED_ARCHIVE,
            ], true)) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $parentIds[$id] = true;
                }
            }
        }
        if ($parentIds === []) {
            return $rows;
        }

        $ids = array_keys($parentIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            'SELECT id,parent_job_id,status,result_json,last_error,cancel_reason,payload_json '
            . 'FROM ue_background_jobs '
            . 'WHERE parent_job_id IN (' . $placeholders . ') '
            . 'AND workflow_unit_key LIKE "archive:%"'
        );
        $statement->execute($ids);

        $summaries = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $child) {
            $parentId = (int)($child['parent_job_id'] ?? 0);
            if ($parentId < 1) {
                continue;
            }
            if (!isset($summaries[$parentId])) {
                $summaries[$parentId] = [
                    'total' => 0,
                    'waiting' => 0,
                    'running' => 0,
                    'successful' => 0,
                    'duplicate' => 0,
                    'skipped' => 0,
                    'nested_archive' => 0,
                    'unverified' => 0,
                    'invalid_ue' => 0,
                    'failed' => 0,
                    'cancelled' => 0,
                    'other_terminal' => 0,
                    'errors' => [],
                    'failures' => [],
                ];
            }
            $summary =& $summaries[$parentId];
            $summary['total']++;
            $status = strtolower(trim((string)($child['status'] ?? '')));
            $result = $this->decode((string)($child['result_json'] ?? ''));
            $payload = $this->decode((string)($child['payload_json'] ?? ''));
            $resultStatus = strtolower(trim((string)($result['status'] ?? '')));

            if ($status === 'queued') {
                $summary['waiting']++;
            } elseif ($status === 'running') {
                $summary['running']++;
            } elseif ($status === 'cancelled') {
                $summary['cancelled']++;
                $this->appendFailure(
                    $summary,
                    (int)($child['id'] ?? 0),
                    $payload,
                    'cancelled',
                    trim((string)($child['cancel_reason'] ?? '')) ?: 'Archive member job was cancelled.'
                );
            } elseif (in_array($status, ['failed', 'dead_letter'], true)) {
                $summary['failed']++;
                $error = trim((string)($child['last_error'] ?? ''));
                $this->appendFailure(
                    $summary,
                    (int)($child['id'] ?? 0),
                    $payload,
                    $status,
                    $error !== '' ? $error : 'Archive member job failed without an error message.'
                );
            } elseif ($status === 'completed') {
                if ($resultStatus === 'duplicate') {
                    $summary['duplicate']++;
                } elseif ($resultStatus === 'skipped') {
                    $summary['skipped']++;
                } elseif ($resultStatus === 'nested_archive') {
                    $summary['nested_archive']++;
                } elseif (in_array($resultStatus, ['unverified', 'unverified_profile_mismatch'], true)) {
                    $summary['unverified']++;
                } elseif (in_array($resultStatus, ['invalid_ue_package', 'invalid_files', 'rejected'], true)) {
                    $summary['invalid_ue']++;
                    $error = trim((string)($result['message'] ?? $child['last_error'] ?? ''));
                    $this->appendFailure(
                        $summary,
                        (int)($child['id'] ?? 0),
                        $payload,
                        $resultStatus === 'invalid_files' ? 'invalid_files' : 'invalid_ue_package',
                        $error !== '' ? $error : 'Extracted member is not a valid supported Unreal package.'
                    );
                } elseif (in_array($resultStatus, ['failed', 'partial', 'error'], true)) {
                    $summary['failed']++;
                    $error = trim((string)($result['message'] ?? $child['last_error'] ?? ''));
                    $this->appendFailure(
                        $summary,
                        (int)($child['id'] ?? 0),
                        $payload,
                        $resultStatus !== '' ? $resultStatus : 'failed',
                        $error !== '' ? $error : 'Archive member completed with an unsuccessful result.'
                    );
                } else {
                    $summary['successful']++;
                }
            } else {
                $summary['other_terminal']++;
            }
            unset($summary);
        }

        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            if (!isset($summaries[$id])) {
                continue;
            }
            $summary = $summaries[$id];
            $pending = (int)$summary['waiting'] + (int)$summary['running'];
            $profiled = (string)($row['job_type'] ?? '') === JobType::IMPORT_STAGED_ARCHIVE;
            $successLabel = $profiled ? 'imported' : 'added';

            /*
             * Child outcomes describe only members that were successfully
             * extracted and queued. The archive parent separately records members
             * that failed during container expansion (failed_files/progress.failed).
             * Keep those two failure domains distinct in diagnostics, but add them
             * for the human-facing summary so a retained archive can never say
             * "0 failed" immediately before listing failed archive members.
             */
            $parentResult = is_array($row['result'] ?? null) ? $row['result'] : [];
            $parentProgress = is_array($row['progress'] ?? null) ? $row['progress'] : [];
            $archiveMemberFailed = max(
                0,
                (int)($parentResult['failed_files'] ?? 0),
                (int)($parentProgress['failed'] ?? 0)
            );
            $childFailed = max(0, (int)$summary['failed']);
            $totalFailed = $archiveMemberFailed + $childFailed;
            $summary['archive_member_failed'] = $archiveMemberFailed;
            $summary['child_failed'] = $childFailed;
            $summary['total_failed'] = $totalFailed;

            if ($pending > 0) {
                $message = 'Archive members: '
                    . number_format((int)$summary['successful']) . ' ' . $successLabel . ', '
                    . number_format((int)$summary['duplicate']) . ' duplicate, '
                    . number_format((int)$summary['skipped']) . ' skipped, '
                    . number_format((int)$summary['nested_archive']) . ' nested archive, '
                    . number_format((int)$summary['unverified']) . ' unverified/review, '
                    . number_format((int)$summary['invalid_ue']) . ' invalid UE file' . ((int)$summary['invalid_ue'] === 1 ? '' : 's') . ', '
                    . number_format($totalFailed) . ' failed, '
                    . number_format((int)$summary['waiting']) . ' waiting, '
                    . number_format((int)$summary['running']) . ' running.';
            } else {
                $message = 'Archive processing complete: '
                    . number_format((int)$summary['successful']) . ' ' . $successLabel . ', '
                    . number_format((int)$summary['duplicate']) . ' duplicate, '
                    . number_format((int)$summary['skipped']) . ' skipped, '
                    . number_format((int)$summary['nested_archive']) . ' nested archive, '
                    . number_format((int)$summary['unverified']) . ' unverified/review, '
                    . number_format((int)$summary['invalid_ue']) . ' invalid UE file' . ((int)$summary['invalid_ue'] === 1 ? '' : 's') . ', '
                    . number_format($totalFailed) . ' failed';
                if ((int)$summary['cancelled'] > 0) {
                    $message .= ', ' . number_format((int)$summary['cancelled']) . ' cancelled';
                }
                $message .= '.';
            }

            $invalidDetail = $this->failureDetail(
                $summary['failures'],
                ['invalid_ue_package', 'invalid_files']
            );
            if ($invalidDetail !== '') {
                $message .= ' Invalid UE file(s): ' . $invalidDetail;
            }
            $failureDetail = $this->failureDetail(
                $summary['failures'],
                [],
                ['invalid_ue_package', 'invalid_files']
            );
            if ($failureDetail !== '') {
                $message .= ' Failed member(s): ' . $failureDetail;
            }

            if (!is_array($row['progress'] ?? null)) {
                $row['progress'] = [];
            }
            if (!is_array($row['result'] ?? null)) {
                $row['result'] = [];
            }
            $row['progress']['message'] = $message;
            $row['result']['archive_outcomes'] = $summary;
            $row['result']['message'] = $message;
            if ($pending === 0 && ($totalFailed > 0 || (int)$summary['cancelled'] > 0)) {
                $row['result']['status'] = 'partial';
                $row['progress']['status'] = 'partial';
                $row['display_status'] = 'partial';
            } elseif ($pending === 0 && (int)$summary['invalid_ue'] > 0) {
                $row['result']['status'] = 'completed';
                $row['progress']['status'] = 'completed';
                $row['display_status'] = 'completed';
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $payload */
    private function appendFailure(
        array &$summary,
        int $jobId,
        array $payload,
        string $status,
        string $error
    ): void {
        $error = $this->compactText($error, 600);
        if ($error !== '' && count($summary['errors']) < self::MAX_VISIBLE_FAILURES) {
            $summary['errors'][] = $error;
        }
        if (count($summary['failures']) >= self::MAX_VISIBLE_FAILURES) {
            return;
        }

        $member = trim((string)($payload['archive_entry_path'] ?? ''));
        if ($member === '') {
            $member = trim((string)($payload['original_name'] ?? ''));
        }
        if ($member === '') {
            $member = trim((string)($payload['source_relative_path'] ?? ''));
        }
        if ($member === '') {
            $member = 'archive member';
        }

        $summary['failures'][] = [
            'job_id' => max(0, $jobId),
            'member' => $member,
            'status' => $status,
            'error' => $error,
        ];
    }

    /**
     * @param list<array<string,mixed>> $failures
     * @param list<string> $onlyStatuses
     * @param list<string> $excludedStatuses
     */
    private function failureDetail(array $failures, array $onlyStatuses = [], array $excludedStatuses = []): string
    {
        $parts = [];
        foreach ($failures as $failure) {
            $status = strtolower(trim((string)($failure['status'] ?? '')));
            if ($onlyStatuses !== [] && !in_array($status, $onlyStatuses, true)) {
                continue;
            }
            if (in_array($status, $excludedStatuses, true)) {
                continue;
            }
            $member = trim((string)($failure['member'] ?? 'archive member'));
            $jobId = max(0, (int)($failure['job_id'] ?? 0));
            $error = trim((string)($failure['error'] ?? ''));
            $label = $member . ($jobId > 0 ? ' (job #' . $jobId . ')' : '');
            $parts[] = $error !== '' ? $label . ' — ' . $error : $label;
        }
        return implode(' | ', $parts);
    }

    private function compactText(string $value, int $maxBytes): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        if ($value === '' || strlen($value) <= $maxBytes) {
            return $value;
        }

        $limit = max(1, $maxBytes - 3);
        if (function_exists('mb_strcut')) {
            $cut = mb_strcut($value, 0, $limit, 'UTF-8');
        } else {
            $cut = substr($value, 0, $limit);
            while ($cut !== '' && preg_match('//u', $cut) !== 1) {
                $cut = substr($cut, 0, -1);
            }
        }
        return rtrim($cut) . '…';
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
