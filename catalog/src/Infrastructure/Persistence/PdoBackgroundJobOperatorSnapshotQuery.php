<?php
/**
 * Operator-facing current-job snapshot shared by production diagnostics.
 *
 * Durable execution rows remain authoritative for worker admission/health, but
 * operator pages report stable parent jobs. Routine workflow children are folded
 * into their parent; failed/dead-letter children remain visible because they need
 * direct operator attention. This is the same visibility/status policy used by
 * the Background Jobs browser.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobOperatorSnapshotQuery
{
    private readonly PdoBackgroundJobSearchScope $scope;
    private readonly PdoBackgroundJobDisplayCountQuery $counts;

    public function __construct(private readonly PDO $db)
    {
        $this->scope = new PdoBackgroundJobSearchScope($db);
        $this->counts = new PdoBackgroundJobDisplayCountQuery($db);
    }

    /**
     * Current operational state only. Completed/cancelled history is excluded so
     * a diagnostics page never has to aggregate the retained terminal archive.
     *
     * @return array{
     *   queue:string,total:int,queued:int,running:int,completed:int,failed:int,
     *   dead_letter:int,cancelled:int,oldest_queued_seconds:int,
     *   longest_running_seconds:int,concurrency_blocked:int
     * }
     */
    public function current(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $scope = $this->scope->build($queueName, '');
        $whereSql = '(' . $scope['where'] . ') AND j.status IN ("queued","running","failed","dead_letter")';
        $counts = $this->counts->counts($scope['from'], $whereSql, $scope['params']);

        $operatorStatus = BackgroundJobDisplaySql::operatorStatus('j');
        $operatorStarted = BackgroundJobDisplaySql::operatorStartedAt('j');
        $ageSql = 'SELECT '
            . 'COALESCE(MAX(CASE WHEN ' . $operatorStatus . '="queued" '
            . 'THEN TIMESTAMPDIFF(SECOND,j.created_at,UTC_TIMESTAMP()) ELSE 0 END),0) oldest_queued_seconds,'
            . 'COALESCE(MAX(CASE WHEN ' . $operatorStatus . '="running" '
            . 'THEN TIMESTAMPDIFF(SECOND,' . $operatorStarted . ',UTC_TIMESTAMP()) ELSE 0 END),0) longest_running_seconds '
            . 'FROM ' . $scope['from'] . ' WHERE ' . $whereSql;
        $ageStatement = $this->db->prepare($ageSql);
        $ageStatement->execute($scope['params']);
        $ages = $ageStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        // Admission still occurs on execution rows. Report the number of affected
        // top-level jobs, not the number of internal child rows waiting on the key.
        $blockedStatement = $this->db->prepare(
            'SELECT COUNT(DISTINCT COALESCE(q.parent_job_id,q.id)) '
            . 'FROM ue_background_jobs q '
            . 'JOIN (SELECT queue_name,concurrency_key FROM ue_background_jobs '
            . 'WHERE status="running" AND concurrency_key IS NOT NULL AND concurrency_key<>"" '
            . 'GROUP BY queue_name,concurrency_key) r '
            . 'ON r.queue_name=q.queue_name AND r.concurrency_key=q.concurrency_key '
            . 'WHERE q.queue_name=? AND q.status="queued" AND q.cancel_requested_at IS NULL '
            . 'AND q.available_at<=UTC_TIMESTAMP()'
        );
        $blockedStatement->execute([$queueName]);

        return [
            'queue' => $queueName,
            'total' => max(0, (int)($counts['all'] ?? 0)),
            'queued' => max(0, (int)($counts['queued'] ?? 0)),
            'running' => max(0, (int)($counts['running'] ?? 0)),
            'completed' => 0,
            'failed' => max(0, (int)($counts['failed'] ?? 0)),
            'dead_letter' => max(0, (int)($counts['dead_letter'] ?? 0)),
            'cancelled' => 0,
            'oldest_queued_seconds' => max(0, (int)($ages['oldest_queued_seconds'] ?? 0)),
            'longest_running_seconds' => max(0, (int)($ages['longest_running_seconds'] ?? 0)),
            'concurrency_blocked' => max(0, (int)$blockedStatement->fetchColumn()),
        ];
    }
}
