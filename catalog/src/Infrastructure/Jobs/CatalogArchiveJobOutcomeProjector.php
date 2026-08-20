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
            'SELECT parent_job_id,status,result_json,last_error '
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
                    'failed' => 0,
                    'cancelled' => 0,
                    'other_terminal' => 0,
                    'errors' => [],
                ];
            }
            $summary =& $summaries[$parentId];
            $summary['total']++;
            $status = strtolower(trim((string)($child['status'] ?? '')));
            $result = $this->decode((string)($child['result_json'] ?? ''));
            $resultStatus = strtolower(trim((string)($result['status'] ?? '')));

            if ($status === 'queued') {
                $summary['waiting']++;
            } elseif ($status === 'running') {
                $summary['running']++;
            } elseif ($status === 'cancelled') {
                $summary['cancelled']++;
            } elseif (in_array($status, ['failed', 'dead_letter'], true)) {
                $summary['failed']++;
                $error = trim((string)($child['last_error'] ?? ''));
                if ($error !== '' && count($summary['errors']) < 10) {
                    $summary['errors'][] = $error;
                }
            } elseif ($status === 'completed') {
                if ($resultStatus === 'duplicate') {
                    $summary['duplicate']++;
                } elseif (in_array($resultStatus, ['failed', 'rejected', 'unverified', 'partial', 'error'], true)) {
                    $summary['failed']++;
                    $error = trim((string)($result['message'] ?? $child['last_error'] ?? ''));
                    if ($error !== '' && count($summary['errors']) < 10) {
                        $summary['errors'][] = $error;
                    }
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

            if ($pending > 0) {
                $message = 'Archive members: '
                    . number_format((int)$summary['successful']) . ' ' . $successLabel . ', '
                    . number_format((int)$summary['duplicate']) . ' duplicate, '
                    . number_format((int)$summary['failed']) . ' failed, '
                    . number_format((int)$summary['waiting']) . ' waiting, '
                    . number_format((int)$summary['running']) . ' running.';
            } else {
                $message = 'Archive processing complete: '
                    . number_format((int)$summary['successful']) . ' ' . $successLabel . ', '
                    . number_format((int)$summary['duplicate']) . ' duplicate, '
                    . number_format((int)$summary['failed']) . ' failed';
                if ((int)$summary['cancelled'] > 0) {
                    $message .= ', ' . number_format((int)$summary['cancelled']) . ' cancelled';
                }
                $message .= '.';
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
            if ($pending === 0 && ((int)$summary['failed'] > 0 || (int)$summary['cancelled'] > 0)) {
                $row['result']['status'] = 'partial';
                $row['progress']['status'] = 'partial';
            }
        }
        unset($row);

        return $rows;
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
