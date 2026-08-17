<?php
/**
 * Builds a passive worker-monitoring summary from the running-work read model.
 *
 * This class deliberately contains no thresholds or recovery policy. It only
 * aggregates measured evidence so operators and callers can decide what it means.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Jobs;

final class CatalogWorkerMonitoringSummary
{
    /**
     * @param list<array<string,mixed>> $working
     * @return array{
     *   running_jobs:int,
     *   telemetry_jobs:int,
     *   active_worker_ids:list<string>,
     *   oldest_running_seconds:int,
     *   max_activity_age_seconds:int
     * }
     */
    public static function fromRunningWork(array $working): array
    {
        $workerIds = [];
        $oldestRunningSeconds = 0;
        $maxActivityAgeSeconds = 0;
        $telemetryJobs = 0;

        foreach ($working as $work) {
            $workerId = trim((string)($work['worker_id'] ?? ''));
            if ($workerId !== '') {
                $workerIds[$workerId] = true;
            }
            $oldestRunningSeconds = max(
                $oldestRunningSeconds,
                max(0, (int)($work['runtime_seconds'] ?? 0))
            );
            $maxActivityAgeSeconds = max(
                $maxActivityAgeSeconds,
                max(0, (int)($work['activity_age_seconds'] ?? 0))
            );
            if (is_array($work['job_telemetry'] ?? null) && $work['job_telemetry'] !== []) {
                $telemetryJobs++;
            }
        }

        $activeWorkerIds = array_keys($workerIds);
        sort($activeWorkerIds, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'running_jobs' => count($working),
            'telemetry_jobs' => $telemetryJobs,
            'active_worker_ids' => $activeWorkerIds,
            'oldest_running_seconds' => $oldestRunningSeconds,
            'max_activity_age_seconds' => $maxActivityAgeSeconds,
        ];
    }
}
