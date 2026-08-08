<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies and imports one downloaded federation payload, preserving failed packages in unverified storage.
 * Why: Download transport and package import/recovery have different failure modes and should not share one procedural worker.
 * Role: Infrastructure federation import use case preserving existing parent notification and staging behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;

final class CatalogFederationImportWorker
{
    private readonly PdoFederationTransferJobStore $jobs;
    private readonly CatalogFederationTransferStorage $storage;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/CatalogImport.php';
        $this->jobs = new PdoFederationTransferJobStore($db);
        $this->storage = new CatalogFederationTransferStorage($config);
    }

    /** @return array<string,mixed> */
    public function runOne(): array
    {
        $job = $this->jobs->claimDownloadedImport();
        if ($job === null) {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'No downloaded jobs waiting for import.',
            ];
        }

        $jobId = (int)$job['id'];
        $incoming = $this->storage->incomingPath((string)$job['incoming_path']);
        $md5 = md5_file($incoming) ?: '';
        $sha1 = sha1_file($incoming) ?: '';
        if (!empty($job['downloaded_md5'])
            && !hash_equals((string)$job['downloaded_md5'], $md5)) {
            $message = 'MD5 mismatch before import for job ' . $jobId;
            $this->jobs->markFailed($jobId, $message);
            throw new RuntimeException($message);
        }
        if (!empty($job['downloaded_sha1'])
            && !hash_equals((string)$job['downloaded_sha1'], $sha1)) {
            $message = 'SHA1 mismatch before import for job ' . $jobId;
            $this->jobs->markFailed($jobId, $message);
            throw new RuntimeException($message);
        }

        $originalName = $this->originalName($job);
        $preferredGameId = in_array(
            (string)$job['direction'],
            ['download_from_parent', 'upload_to_parent'],
            true
        ) ? null : $this->preferredGameId($job);

        try {
            $result = \catalog_import_file(
                $this->db,
                $this->config,
                $incoming,
                $originalName,
                $preferredGameId,
                $_SESSION['user']['id'] ?? null
            );
            $status = ($result['status'] === 'verified'
                || str_starts_with((string)$result['status'], 'duplicate_'))
                ? 'imported'
                : 'failed';
            if (str_starts_with((string)$result['status'], 'duplicate_')
                && is_file($incoming)) {
                @unlink($incoming);
            }

            $this->jobs->markImportResult(
                $jobId,
                $status,
                isset($result['file_id']) ? (int)$result['file_id'] : null,
                null,
                (string)($result['message'] ?? $result['status'])
            );
            \fed_log(
                $this->db,
                (int)$job['peer_id'],
                $jobId,
                $status === 'imported' ? 'INFO' : 'WARN',
                'FEDERATION_IMPORT',
                (string)json_encode($result, JSON_UNESCAPED_SLASHES)
            );
            $this->notifyParent($job, $result, $status);
            return [
                'ok' => true,
                'job_id' => $jobId,
                'result' => $result,
                'notified_parent' => (string)$job['direction'] === 'download_from_parent',
            ];
        } catch (Throwable $error) {
            $staged = null;
            try {
                $staged = $this->stageFailedImport(
                    $job,
                    $incoming,
                    $originalName,
                    $preferredGameId,
                    $error
                );
            } catch (Throwable $stageError) {
                \fed_log(
                    $this->db,
                    (int)$job['peer_id'],
                    $jobId,
                    'ERROR',
                    'FEDERATION_STAGE_FAIL',
                    $stageError->getMessage()
                );
            }

            $message = $error->getMessage();
            $stagedFileId = null;
            $stagedPath = (string)$job['incoming_path'];
            if (is_array($staged)) {
                $stagedFileId = (int)$staged['file_id'];
                $stagedPath = CatalogUnverifiedStagingIndex::storageRelative(
                    $this->config,
                    (string)$staged['path']
                );
                $message .= ' Staged as unverified file #' . $stagedFileId . '.';
            }
            $this->jobs->markImportResult(
                $jobId,
                'failed',
                $stagedFileId,
                $stagedPath,
                $message
            );
            \fed_log(
                $this->db,
                (int)$job['peer_id'],
                $jobId,
                'ERROR',
                'FEDERATION_IMPORT_FAIL',
                $message
            );
            $this->notifyParent(
                $job,
                ['status' => 'failed', 'message' => $message],
                'failed'
            );
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    public function originalName(array $job): string
    {
        if ((string)$job['direction'] === 'upload_to_parent') {
            $file = \catalog_one(
                $this->db,
                'SELECT original_name FROM ue_files WHERE id=?',
                [(int)$job['remote_file_id']]
            );
            if ($file && trim((string)$file['original_name']) !== '') {
                return (string)$file['original_name'];
            }
        }
        $peerFile = \catalog_one(
            $this->db,
            'SELECT original_name FROM ue_federation_peer_files '
            . 'WHERE peer_id=? AND remote_file_id=? ORDER BY id DESC LIMIT 1',
            [(int)$job['peer_id'], (int)$job['remote_file_id']]
        );
        if ($peerFile && trim((string)$peerFile['original_name']) !== '') {
            return (string)$peerFile['original_name'];
        }
        return basename((string)$job['incoming_path']);
    }

    /** @param array<string,mixed> $job */
    public function preferredGameId(array $job): ?int
    {
        $peerFile = \catalog_one(
            $this->db,
            'SELECT game_id,remote_engine_key FROM ue_federation_peer_files '
            . 'WHERE peer_id=? AND remote_file_id=? ORDER BY id DESC LIMIT 1',
            [(int)$job['peer_id'], (int)$job['remote_file_id']]
        );
        if ($peerFile
            && !empty($peerFile['game_id'])
            && \catalog_one(
                $this->db,
                'SELECT id FROM ue_games WHERE id=?',
                [(int)$peerFile['game_id']]
            )) {
            return (int)$peerFile['game_id'];
        }
        if ($peerFile && !empty($peerFile['remote_engine_key'])) {
            return $this->gameIdForEngine((string)$peerFile['remote_engine_key']);
        }
        return null;
    }

    public function gameIdForEngine(string $engineKey): ?int
    {
        $engineKey = strtoupper(trim($engineKey));
        if ($engineKey === '') {
            return null;
        }
        $game = \catalog_one(
            $this->db,
            'SELECT g.id FROM ue_games g '
            . 'JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 '
            . 'WHERE UPPER(p.engine_key)=? ORDER BY g.id LIMIT 1',
            [$engineKey]
        );
        return $game ? (int)$game['id'] : null;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $result */
    public function notifyParent(array $job, array $result, string $status): void
    {
        if ((string)$job['direction'] !== 'download_from_parent'
            || empty($job['remote_request_item_id'])) {
            return;
        }
        $peer = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers '
            . 'WHERE id=? AND peer_role="parent" AND is_active=1',
            [(int)$job['peer_id']]
        );
        if (!$peer
            || (empty($peer['shared_secret_plain'])
                && \fed_outgoing_signature_algorithm() !== 'ed25519')) {
            \fed_log(
                $this->db,
                (int)$job['peer_id'],
                (int)$job['id'],
                'WARN',
                'PARENT_STATUS_NOTIFY_SKIP',
                'Parent peer missing or has no usable signing credential.'
            );
            return;
        }

        $payload = [
            'request_item_id' => (int)$job['remote_request_item_id'],
            'status' => $status === 'imported' ? 'imported' : 'failed',
            'child_local_file_id' => $result['file_id'] ?? null,
            'md5' => (string)($job['downloaded_md5'] ?? ''),
            'sha1' => (string)($job['downloaded_sha1'] ?? ''),
            'message' => (string)($result['message'] ?? $result['status'] ?? ''),
        ];
        $url = rtrim((string)$peer['site_url'], '/')
            . '/api/federation/request-item-status-update.php';
        $response = \fed_http_post_signed(
            $url,
            (string)\fed_setting($this->db, 'site_id', ''),
            (string)$peer['shared_secret_plain'],
            $payload
        );
        \fed_log(
            $this->db,
            (int)$peer['id'],
            (int)$job['id'],
            !empty($response['ok']) ? 'INFO' : 'ERROR',
            'PARENT_STATUS_NOTIFY',
            (string)json_encode($response, JSON_UNESCAPED_SLASHES)
        );
    }

    /** @param array<string,mixed> $job @return array<string,mixed>|null */
    private function stageFailedImport(
        array $job,
        string $incoming,
        string $originalName,
        ?int $preferredGameId,
        Throwable $error
    ): ?array {
        if (!is_file($incoming)) {
            return null;
        }
        $queueGameId = $preferredGameId ?? $this->preferredGameId($job);
        if ($queueGameId === null) {
            $detected = \catalog_import_detect_game(
                $this->db,
                (string)pathinfo($originalName, PATHINFO_EXTENSION)
            );
            $queueGameId = $detected ? (int)$detected['id'] : null;
        }
        if ($queueGameId === null) {
            return null;
        }

        $reason = 'Federation import job ' . (int)$job['id']
            . ' failed for ' . $originalName . ': ' . $error->getMessage();
        return (new LegacyUnverifiedFileStager($this->db, $this->config))->stageFailedUpload(
            $queueGameId,
            $incoming,
            $originalName,
            $reason,
            null,
            ''
        );
    }
}
