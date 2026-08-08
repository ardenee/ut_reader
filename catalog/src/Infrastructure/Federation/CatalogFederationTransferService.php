<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation transfer cancel/retry controls, status mapping, and policy-visible tab counts.
 * Why: Transfer mutation SQL and status semantics must not be duplicated inside the Transfer Queue rendering page.
 * Role: Infrastructure service preserving existing federation transfer administration behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;

final class CatalogFederationTransferService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/FederationBaseGamePolicy.php';
    }

    /** @return list<string> */
    public static function statusesForTab(string $tab): array
    {
        return match ($tab) {
            'waiting' => ['downloaded'],
            'failed' => ['failed'],
            'completed' => ['imported'],
            'cancelled' => ['cancelled'],
            default => ['queued', 'running'],
        };
    }

    /** @return array{flash:string,tab:string} */
    public function handle(int $jobId, string $action, string $tab): array
    {
        $visible = \federation_visible_transfer_job_sql($this->db, 'j');
        $job = \catalog_one(
            $this->db,
            'SELECT j.* FROM ue_federation_transfer_jobs j WHERE j.id=? AND ' . $visible,
            [$jobId]
        );
        if (!$job) {
            throw new RuntimeException('Transfer job not found or excluded by policy.');
        }

        $action = strtolower(trim($action));
        if ($action === 'cancel') {
            if (!in_array((string)$job['status'], ['queued', 'failed'], true)) {
                throw new RuntimeException('Only queued or failed transfers can be cancelled here.');
            }
            $this->db->prepare(
                'UPDATE ue_federation_transfer_jobs '
                . 'SET status="cancelled",finished_at=NOW(),last_error="Cancelled by administrator." WHERE id=?'
            )->execute([$jobId]);
            \fed_log(
                $this->db,
                (int)$job['peer_id'],
                $jobId,
                'INFO',
                'JOB_CANCEL',
                'Transfer cancelled by administrator.'
            );
            return ['flash' => 'Transfer #' . $jobId . ' cancelled.', 'tab' => $tab];
        }

        if ($action === 'retry') {
            if (!in_array((string)$job['status'], ['failed', 'cancelled'], true)) {
                throw new RuntimeException('Only failed or cancelled transfers can be retried.');
            }
            $this->db->prepare(
                'UPDATE ue_federation_transfer_jobs SET status="queued",bytes_done=0,incoming_path=NULL,'
                . 'downloaded_md5=NULL,downloaded_sha1=NULL,started_at=NULL,finished_at=NULL,last_error=NULL WHERE id=?'
            )->execute([$jobId]);
            \fed_log($this->db, (int)$job['peer_id'], $jobId, 'INFO', 'JOB_RETRY', 'Transfer reset to queued.');
            return ['flash' => 'Transfer #' . $jobId . ' reset to queued.', 'tab' => 'active'];
        }

        throw new RuntimeException('Unknown transfer action.');
    }

    /** @return array{active:int,waiting:int,failed:int,completed:int,cancelled:int} */
    public function counts(): array
    {
        $visible = \federation_visible_transfer_job_sql($this->db, 'j');
        $counts = [];
        foreach (['active', 'waiting', 'failed', 'completed', 'cancelled'] as $tab) {
            $statuses = self::statusesForTab($tab);
            $quoted = implode(',', array_map([$this->db, 'quote'], $statuses));
            $counts[$tab] = (int)(\catalog_one(
                $this->db,
                'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j '
                . 'WHERE j.status IN (' . $quoted . ') AND ' . $visible
            )['c'] ?? 0);
        }
        return $counts;
    }
}
