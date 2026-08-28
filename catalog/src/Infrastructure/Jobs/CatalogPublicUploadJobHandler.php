<?php
/**
 * Background validation and unverified staging for public contribution uploads.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicUploadTransferStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadDuplicateDetector;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

final class CatalogPublicUploadJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogScanner.php';
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::PROCESS_PUBLIC_UPLOAD;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $publicUploadId = max(0, (int)($job->payload['public_upload_id'] ?? 0));
        $token = strtolower(trim((string)($job->payload['upload_token'] ?? '')));
        if ($publicUploadId < 1 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new \InvalidArgumentException('Public upload background-job payload is incomplete.');
        }

        $store = new CatalogPublicUploadTransferStore($this->db, $this->config);
        $row = $store->ledgerForJob($publicUploadId, $token);
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (in_array($status, ['unverified', 'duplicate'], true) && (int)($row['unverified_file_id'] ?? 0) > 0) {
            return $this->result($row, 'completed');
        }
        if (!in_array($status, ['uploaded', 'processing'], true)) {
            throw new RuntimeException('Public upload is not ready for background processing: status=' . $status . '.');
        }

        try {
            $resolved = $store->resolveForJob($publicUploadId, $token);
            $row = $resolved;
        } catch (RuntimeException $resolveError) {
            if ($status === 'processing') {
                $recovered = $this->recoverPublishedStage($publicUploadId, $row);
                if ($recovered !== null) {
                    return $recovered;
                }
            }
            throw $resolveError;
        }

        $this->updateLedger($publicUploadId, [
            'status' => 'processing',
            'background_job_id' => $job->id,
            'result_message' => 'Authoritative server validation is in progress.',
        ]);

        $sourcePath = (string)$row['physical_path'];
        $sourceName = trim((string)($row['original_name'] ?? ''));
        $relativePath = trim((string)($row['relative_path'] ?? $sourceName));
        $decodedPath = '';
        $workingPath = $sourcePath;
        $workingName = $sourceName;

        try {
            $context->checkpoint([
                'stage' => 'server_validation',
                'percent' => 5,
                'message' => 'Validating public contribution ' . $sourceName . ' on the server.',
            ]);

            if ((new CatalogRedirectArchiveProcessor($this->config))->supports($sourceName)) {
                $decoded = (new CatalogRedirectArchiveProcessor($this->config))->decompressToTemp(
                    $sourcePath,
                    $sourceName,
                    function (array $progress) use ($context, $sourceName): void {
                        $done = max(0, (int)($progress['done'] ?? $progress['bytes'] ?? 0));
                        $total = max(1, (int)($progress['total'] ?? $progress['expected_bytes'] ?? 1));
                        $percent = max(5, min(40, 5 + (int)floor(($done * 35) / $total)));
                        $context->heartbeatIfDue([
                            'stage' => 'decompress_redirect',
                            'percent' => $percent,
                            'message' => 'Decompressing public redirect ' . $sourceName . '.',
                        ]);
                    },
                    true
                );
                $decodedPath = (string)$decoded['path'];
                $workingPath = $decodedPath;
                $workingName = trim((string)($decoded['filename'] ?? '')) ?: preg_replace('/\.(?:uz|uz2|uz3)$/i', '', $sourceName);
            }

            if (!is_file($workingPath) || !\scanner_file_has_unreal_package_magic($workingPath)) {
                throw new RuntimeException('Magic not found');
            }

            $identity = $this->hashFile($workingPath, $context, $workingName);
            $clientMd5 = strtolower(trim((string)($row['client_md5'] ?? '')));
            $clientSha1 = strtolower(trim((string)($row['client_sha1'] ?? '')));
            // For normal packages these hashes describe the uploaded bytes. For
            // UZ2/UZ3 public redirects they describe the browser-decoded package
            // identity used by the 100-file duplicate preflight. Either way the
            // authoritative server result must match whenever the client supplied
            // an identity.
            if ($clientMd5 !== '' && !hash_equals($clientMd5, $identity['md5'])) {
                throw new RuntimeException(
                    'Public upload MD5 mismatch: client=' . $clientMd5 . ', server=' . $identity['md5'] . '.'
                );
            }
            if ($clientSha1 !== '' && !hash_equals($clientSha1, $identity['sha1'])) {
                throw new RuntimeException(
                    'Public upload SHA-1 mismatch: client=' . $clientSha1 . ', server=' . $identity['sha1'] . '.'
                );
            }

            $this->updateLedger($publicUploadId, [
                'server_md5' => $identity['md5'],
                'server_sha1' => $identity['sha1'],
            ]);

            $duplicateCheck = (new CatalogUploadDuplicateDetector($this->db, $this->config))->inspect(
                $identity['size'],
                $identity['md5'],
                $identity['sha1']
            );
            $existing = is_array($duplicateCheck['duplicate'] ?? null)
                ? $duplicateCheck['duplicate']
                : null;
            if ($existing !== null) {
                $existingFileId = max(0, (int)($existing['file_id'] ?? 0));
                $store->removeQuarantine($token);
                if ($decodedPath !== '' && is_file($decodedPath)) {
                    @unlink($decodedPath);
                }
                $this->updateLedger($publicUploadId, [
                    'status' => 'duplicate',
                    'unverified_file_id' => $existingFileId,
                    'server_guid' => '',
                    'active_identity_key' => null,
                    'quarantine_relative_path' => null,
                    'result_message' => 'Server validation physically confirmed identical bytes as file #' . $existingFileId . '.',
                ]);
                return [
                    'status' => 'duplicate',
                    'public_upload_id' => $publicUploadId,
                    'file_id' => $existingFileId,
                    'md5' => $identity['md5'],
                    'sha1' => $identity['sha1'],
                    'message' => 'Physically confirmed identical bytes already exist as file #' . $existingFileId . '.',
                ];
            }

            $context->checkpoint([
                'stage' => 'stage_unverified',
                'percent' => 65,
                'message' => 'Staging validated public contribution as unverified for administrator review.',
                'md5' => $identity['md5'],
                'sha1' => $identity['sha1'],
            ]);

            $staged = (new LegacyUnverifiedFileStager($this->db, $this->config))->stageBucketUpload(
                $workingPath,
                $workingName,
                'Public contribution upload; awaiting administrator review.',
                null,
                $relativePath
            );
            $fileId = max(0, (int)($staged['file_id'] ?? 0));
            if ($fileId < 1) {
                throw new RuntimeException('Public upload was staged without an unverified file ID.');
            }

            if ($workingPath !== $sourcePath) {
                $store->removeQuarantine($token);
            }
            if ($decodedPath !== '' && is_file($decodedPath)) {
                @unlink($decodedPath);
            }

            $file = $this->db->prepare('SELECT package_guid FROM ue_files WHERE id=? LIMIT 1');
            $file->execute([$fileId]);
            $serverGuid = trim((string)($file->fetchColumn() ?: ''));

            $this->updateLedger($publicUploadId, [
                'status' => (string)($staged['status'] ?? '') === 'duplicate' ? 'duplicate' : 'unverified',
                'unverified_file_id' => $fileId,
                'server_guid' => $serverGuid,
                'active_identity_key' => null,
                'quarantine_relative_path' => null,
                'result_message' => (string)($staged['message'] ?? 'Public contribution staged as unverified.'),
            ]);

            // Expensive exact object/game compatibility evidence is intentionally
            // separate background work, never part of the public HTTP upload.
            (new PdoJobQueue($this->db))->enqueue(
                $job->queue,
                JobType::REFRESH_UNVERIFIED_GAME_MATCHES,
                ['file_id' => $fileId, 'scope' => 'file'],
                60,
                null,
                'public-upload-unverified-match:file:' . $fileId,
                null,
                3
            );

            $context->checkpoint([
                'stage' => 'complete',
                'percent' => 100,
                'message' => 'Public contribution is ready for administrator review as unverified file #' . $fileId . '.',
                'file_id' => $fileId,
            ]);

            return [
                'status' => (string)($staged['status'] ?? '') === 'duplicate' ? 'duplicate' : 'unverified',
                'public_upload_id' => $publicUploadId,
                'file_id' => $fileId,
                'md5' => $identity['md5'],
                'sha1' => $identity['sha1'],
                'package_guid' => $serverGuid,
                'message' => 'Public contribution staged as unverified file #' . $fileId . ' for administrator review.',
            ];
        } catch (\Throwable $error) {
            if ($decodedPath !== '' && is_file($decodedPath)) {
                @unlink($decodedPath);
            }
            // Failed extraction/validation is the one case where the original
            // contribution should remain staged for diagnosis/retry.
            $this->updateLedger($publicUploadId, [
                'status' => 'failed',
                'active_identity_key' => null,
                'result_message' => substr(trim($error->getMessage()) ?: get_class($error), 0, 1000),
            ]);
            throw $error;
        }
    }

    /** @return array{md5:string,sha1:string,size:int} */
    private function hashFile(string $path, JobExecutionContext $context, string $name): array
    {
        $size = filesize($path);
        if ($size === false || (int)$size < 1) {
            throw new RuntimeException('Validated public upload is empty.');
        }

        $input = @fopen($path, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Could not open public upload for authoritative hashing.');
        }
        $md5 = hash_init('md5');
        $sha1 = hash_init('sha1');
        $read = 0;
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new RuntimeException('Could not read public upload while hashing.');
                }
                if ($buffer === '') {
                    if (feof($input)) {
                        break;
                    }
                    throw new RuntimeException('Public upload hashing stopped before EOF.');
                }
                $read += strlen($buffer);
                hash_update($md5, $buffer);
                hash_update($sha1, $buffer);
                $context->heartbeatIfDue([
                    'stage' => 'server_hash',
                    'percent' => max(40, min(60, 40 + (int)floor(($read * 20) / max(1, (int)$size)))),
                    'message' => 'Authoritatively hashing ' . $name . ': ' . $read . '/' . (int)$size . ' bytes.',
                ]);
            }
        } finally {
            fclose($input);
        }

        if ($read !== (int)$size) {
            throw new RuntimeException(
                'Public upload hash byte count mismatch: expected=' . (int)$size . ', read=' . $read . '.'
            );
        }
        return [
            'md5' => hash_final($md5),
            'sha1' => hash_final($sha1),
            'size' => (int)$size,
        ];
    }

    /** @param array<string,mixed> $ledger @return array<string,mixed>|null */
    private function recoverPublishedStage(int $publicUploadId, array $ledger): ?array
    {
        $md5 = strtolower(trim((string)($ledger['server_md5'] ?? '')));
        $sha1 = strtolower(trim((string)($ledger['server_sha1'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT id,package_guid,scan_status FROM ue_files '
            . 'WHERE md5=? AND sha1=? AND scan_status IN ("verified","unverified") ORDER BY id LIMIT 1'
        );
        $statement->execute([$md5, $sha1]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            return null;
        }

        $fileId = max(0, (int)($file['id'] ?? 0));
        if ($fileId < 1) {
            return null;
        }
        $scanStatus = strtolower(trim((string)($file['scan_status'] ?? '')));
        $ledgerStatus = $scanStatus === 'unverified' ? 'unverified' : 'duplicate';
        $guid = trim((string)($file['package_guid'] ?? ''));
        $message = 'Recovered public upload after staging completed before ledger publication; file #'
            . $fileId . ' already holds the authoritative hashes.';

        $this->updateLedger($publicUploadId, [
            'status' => $ledgerStatus,
            'unverified_file_id' => $fileId,
            'server_guid' => $guid,
            'active_identity_key' => null,
            'quarantine_relative_path' => null,
            'result_message' => $message,
        ]);

        return [
            'status' => $ledgerStatus,
            'public_upload_id' => $publicUploadId,
            'file_id' => $fileId,
            'md5' => $md5,
            'sha1' => $sha1,
            'package_guid' => $guid,
            'message' => $message,
        ];
    }


    /** @param array<string,mixed> $values */
    private function updateLedger(int $publicUploadId, array $values): void
    {
        if ($values === []) {
            return;
        }
        $allowed = [
            'status', 'background_job_id', 'server_md5', 'server_sha1', 'server_guid',
            'unverified_file_id', 'result_message', 'active_identity_key', 'quarantine_relative_path',
        ];
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $sets[] = $column . '=?';
            $params[] = $value === '' && in_array($column, ['server_guid', 'active_identity_key', 'quarantine_relative_path'], true)
                ? null
                : $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at=UTC_TIMESTAMP(6)';
        $params[] = $publicUploadId;
        $statement = $this->db->prepare(
            'UPDATE ue_public_uploads SET ' . implode(',', $sets) . ' WHERE id=?'
        );
        $statement->execute($params);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function result(array $row, string $status): array
    {
        return [
            'status' => $status,
            'public_upload_id' => (int)($row['id'] ?? 0),
            'file_id' => (int)($row['unverified_file_id'] ?? 0),
            'md5' => (string)($row['server_md5'] ?? ''),
            'sha1' => (string)($row['server_sha1'] ?? ''),
            'package_guid' => (string)($row['server_guid'] ?? ''),
            'message' => (string)($row['result_message'] ?? 'Public upload already processed.'),
        ];
    }
}
