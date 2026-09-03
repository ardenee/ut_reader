<?php
/**
 * Canonical child-outcome projection for archive parent workflows.
 *
 * Archive parents remain non-terminal until every archive member child is
 * terminal. This query keeps lifecycle/final-result classification independent
 * of the browser read model so workers do not depend on presentation code.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoArchiveChildOutcomeQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   total:int,queued:int,running:int,successful:int,duplicate:int,skipped:int,nested_archive:int,
     *   unverified:int,invalid_ue:int,failed:int,cancelled:int,dead_letter:int,terminal:int
     * }
     */
    public function fetch(int $parentJobId): array
    {
        if ($parentJobId < 1) {
            throw new \InvalidArgumentException('A positive archive parent job id is required.');
        }

        $state = [
            'total' => 0,
            'queued' => 0,
            'running' => 0,
            'successful' => 0,
            'duplicate' => 0,
            'skipped' => 0,
            'nested_archive' => 0,
            'unverified' => 0,
            'invalid_ue' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'dead_letter' => 0,
            'terminal' => 0,
        ];

        $statement = $this->db->prepare(
            'SELECT status,display_status,COUNT(*) c FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key LIKE "archive:%" '
            . 'GROUP BY status,display_status'
        );
        $statement->execute([$parentJobId]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $count = max(0, (int)($row['c'] ?? 0));
            if ($count < 1) {
                continue;
            }
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $displayStatus = strtolower(trim((string)($row['display_status'] ?? '')));
            $state['total'] += $count;

            if ($status === 'queued') {
                $state['queued'] += $count;
                continue;
            }
            if ($status === 'running') {
                $state['running'] += $count;
                continue;
            }

            $state['terminal'] += $count;
            if ($status === 'cancelled') {
                $state['cancelled'] += $count;
                continue;
            }
            if ($status === 'dead_letter') {
                $state['dead_letter'] += $count;
                $state['failed'] += $count;
                continue;
            }
            if ($status === 'failed') {
                $state['failed'] += $count;
                continue;
            }
            if ($status === 'completed') {
                if ($displayStatus === 'duplicate') {
                    $state['duplicate'] += $count;
                } elseif ($displayStatus === 'skipped') {
                    $state['skipped'] += $count;
                } elseif ($displayStatus === 'nested_archive') {
                    $state['nested_archive'] += $count;
                } elseif (in_array($displayStatus, ['unverified', 'unverified_profile_mismatch'], true)) {
                    // The member bytes were successfully handed off to Unverified
                    // Files for administrator review. Profile mismatch is one
                    // reason for that handoff, but neither outcome is an archive
                    // extraction/read failure and replaying the same child job is
                    // not the review workflow.
                    $state['unverified'] += $count;
                } elseif (in_array($displayStatus, ['invalid_ue_package', 'invalid_files', 'rejected'], true)) {
                    // Container extraction completed. The resulting member bytes
                    // are not a valid supported Unreal package, so keep the file
                    // issue separate from archive extraction/retry state.
                    $state['invalid_ue'] += $count;
                } elseif (in_array($displayStatus, ['failed', 'partial', 'error'], true)) {
                    $state['failed'] += $count;
                } else {
                    $state['successful'] += $count;
                }
                continue;
            }

            // Unknown terminal states are failures for parent finalization. It is
            // safer to report a partial archive than silently declare success.
            $state['failed'] += $count;
        }

        return $state;
    }
}
