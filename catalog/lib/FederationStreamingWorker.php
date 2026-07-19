<?php
declare(strict_types=1);

require_once __DIR__ . '/FederationWorker.php';
require_once __DIR__ . '/FederationTransferAuth.php';

function federation_streaming_claim_transfer(PDO $db): ?array
{
    $db->beginTransaction();
    try {
        $sql = 'SELECT j.*,p.site_name peer_name,p.site_url,p.peer_site_id,p.shared_secret_plain,p.signature_algorithm,p.signing_public_key,p.signing_key_id,p.signing_revoked_at '
            . 'FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id '
            . 'WHERE j.status="queued" AND j.direction IN ("parent_pull_from_child","download_from_parent","upload_to_parent") '
            . 'AND p.is_active=1 ORDER BY j.created_at ASC LIMIT 1 FOR UPDATE';
        $job = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            $db->commit();
            return null;
        }
        $statement = $db->prepare(
            'UPDATE ue_federation_transfer_jobs SET status="running",started_at=NOW(),attempts=attempts+1,last_error=NULL '
            . 'WHERE id=? AND status="queued"'
        );
        $statement->execute([(int)$job['id']]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Transfer claim was lost.');
        }
        $db->commit();
        return $job;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function federation_streaming_transfer_limit(PDO $db): int
{
    return max(1, min(1024 * 1024 * 1024 * 8, (int)(fed_setting($db, 'max_transfer_file_size_mb', '1024') ?: 1024) * 1024 * 1024));
}

function federation_streaming_run_one_transfer(PDO $db, array $config): array
{
    $job = federation_streaming_claim_transfer($db);
    if (!$job) {
        return ['ok' => true, 'skipped' => true, 'message' => 'No queued transfer jobs.'];
    }
    try {
        return (string)$job['direction'] === 'upload_to_parent'
            ? federation_worker_run_one_upload($db, $config, $job)
            : federation_worker_run_one_download($db, $config, $job);
    } catch (Throwable $error) {
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed",finished_at=NOW(),last_error=? WHERE id=?')
            ->execute([$error->getMessage(), (int)$job['id']]);
        fed_log($db, (int)$job['peer_id'], (int)$job['id'], 'ERROR', 'TRANSFER_FAIL', $error->getMessage());
        throw $error;
    }
}
