<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes one claimed federation download/upload transfer.
 * Why: Durable job state, storage publication and transport calls should be orchestrated in one namespaced service,
 *      not duplicated between streaming/non-streaming procedural workers.
 * Role: Infrastructure federation transfer use case; import of downloaded files is handled separately.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationTransferWorker
{
    private readonly PdoFederationTransferJobStore $jobs;
    private readonly CatalogFederationTransferStorage $storage;
    private readonly CatalogFederationTransferClient $client;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/FederationAuth.php';
        $this->jobs = new PdoFederationTransferJobStore($db);
        $this->storage = new CatalogFederationTransferStorage($config);
        $this->client = new CatalogFederationTransferClient($db);
    }

    /** @return array<string,mixed> */
    public function runOne(): array
    {
        $job = $this->jobs->claimTransfer();
        if ($job === null) {
            return ['ok' => true, 'skipped' => true, 'message' => 'No queued transfer jobs.'];
        }
        return $this->runClaimed($job);
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function runClaimed(array $job): array
    {
        try {
            return (string)$job['direction'] === 'upload_to_parent'
                ? $this->upload($job)
                : $this->download($job);
        } catch (Throwable $error) {
            $this->jobs->markFailed((int)$job['id'], $error->getMessage());
            \fed_log(
                $this->db,
                (int)$job['peer_id'],
                (int)$job['id'],
                'ERROR',
                'TRANSFER_FAIL',
                $error->getMessage()
            );
            throw $error;
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function download(array $job): array
    {
        $jobId = (int)$job['id'];
        $incomingDirectory = $this->storage->incomingDirectory();
        $name = 'peer_' . (int)$job['peer_id']
            . '_' . (string)$job['direction']
            . '_remote_' . (int)$job['remote_file_id']
            . '_item_' . (int)($job['remote_request_item_id'] ?? 0)
            . '_' . date('Ymd_His') . '.bin';
        $destination = $incomingDirectory . DIRECTORY_SEPARATOR
            . CatalogFederationTransferStorage::safeName($name);
        $part = $destination . '.part';
        @unlink($part);

        try {
            $transfer = $this->client->downloadTo(
                $job,
                $part,
                $this->client->maxTransferBytes(),
                $this->jobs->progressCallback($jobId)
            );
            $bytes = (int)$transfer['bytes'];
            if (!@rename($part, $destination)) {
                throw new RuntimeException('Could not publish verified federation download.');
            }
            @chmod($destination, 0640);
        } catch (Throwable $error) {
            @unlink($part);
            throw $error;
        }

        $md5 = md5_file($destination) ?: '';
        $sha1 = sha1_file($destination) ?: '';
        $relativeIncoming = $this->storage->incomingRelative($destination);
        $this->jobs->markDownloaded(
            $jobId,
            $bytes,
            $relativeIncoming,
            $md5,
            $sha1,
            'Downloaded to incoming: ' . basename($destination)
        );
        \fed_log(
            $this->db,
            (int)$job['peer_id'],
            $jobId,
            'INFO',
            (string)$transfer['log_event'],
            'Downloaded remote file ' . (int)$job['remote_file_id']
                . ' to ' . basename($destination)
        );

        return [
            'ok' => true,
            'job_id' => $jobId,
            'direction' => (string)$job['direction'],
            'file' => basename($destination),
            'bytes' => $bytes,
            'md5' => $md5,
        ];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function upload(array $job): array
    {
        $jobId = (int)$job['id'];
        $file = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE id=? AND scan_status="verified"',
            [(int)$job['local_file_id']]
        );
        if (!$file) {
            throw new RuntimeException('Local verified file not found for upload job.');
        }

        $path = $this->storage->verifiedFilePath($file);
        $bytesTotal = (int)(filesize($path) ?: 0);
        if ($bytesTotal <= 0 || $bytesTotal > $this->client->maxTransferBytes()) {
            throw new RuntimeException('Upload file size is invalid or exceeds max transfer limit.');
        }
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException('Could not calculate federation upload SHA-256.');
        }

        $response = $this->client->uploadFile(
            $job,
            $file,
            $path,
            $sha256,
            $bytesTotal,
            $this->jobs->progressCallback($jobId)
        );
        if (empty($response['ok'])) {
            throw new RuntimeException(
                'Upload rejected: ' . ($response['error'] ?? 'unknown error')
            );
        }

        $this->jobs->markUploaded(
            $jobId,
            $bytesTotal,
            (string)$file['md5'],
            (string)$file['sha1'],
            'Uploaded to parent; parent job ID ' . ($response['job_id'] ?? '')
        );
        \fed_log(
            $this->db,
            (int)$job['peer_id'],
            $jobId,
            'INFO',
            'UPLOAD_TO_PARENT_DONE',
            'Uploaded local file ID ' . (int)$file['id'] . ' to parent.'
        );
        if ((int)($job['wait_after_seconds'] ?? 0) > 0) {
            sleep((int)$job['wait_after_seconds']);
        }

        return [
            'ok' => true,
            'job_id' => $jobId,
            'direction' => 'upload_to_parent',
            'remote_job_id' => $response['job_id'] ?? null,
            'bytes' => $bytesTotal,
            'md5' => (string)$file['md5'],
            'sha256' => $sha256,
        ];
    }
}
