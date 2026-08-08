<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Receives authenticated child-to-parent federation file streams into durable incoming storage.
 * Why: Stream hashing, size enforcement, filesystem finalization and transfer-job persistence should not live in the HTTP endpoint.
 * Role: Infrastructure federation inbound transfer service preserving the existing streaming upload contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationStreamingUploadService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
    }

    /** @param array<string,mixed> $peer @param array<string,mixed> $meta @return array<string,mixed> */
    public function receive(array $peer, array $meta): array
    {
        if ((string)($peer['peer_role'] ?? '') !== 'child') {
            throw new CatalogFederationApiException(
                'Only paired children may upload to this parent.',
                403
            );
        }

        $maxBytes = (int)(\fed_setting($this->db, 'max_transfer_file_size_mb', '1024') ?: 1024)
            * 1024 * 1024;
        $expectedBytes = max(0, (int)($meta['bytes'] ?? 0));
        if ($expectedBytes > $maxBytes) {
            throw new CatalogFederationApiException('Upload exceeds max transfer size', 413);
        }

        $safeName = $this->safeName(
            'upload_peer_' . (int)$peer['id']
            . '_remote_' . (string)($meta['remote_id'] ?? '')
            . '_' . date('Ymd_His')
            . '_' . (string)($meta['name'] ?? 'upload.bin')
        );
        $path = $this->incomingDirectory() . DIRECTORY_SEPARATOR . $safeName;
        $part = $path . '.part';

        try {
            [$bytes, $md5, $sha1, $sha256] = $this->stream(
                $part,
                $expectedBytes,
                (string)($meta['sha256'] ?? ''),
                $maxBytes
            );
            if (!rename($part, $path)) {
                @unlink($part);
                throw new RuntimeException('Could not finalize verified upload.');
            }

            $relative = 'storage/federation/incoming/' . $safeName;
            $statement = $this->db->prepare(
                'INSERT INTO ue_federation_transfer_jobs('
                . 'peer_id,direction,remote_file_id,status,bytes_total,bytes_done,incoming_path,'
                . 'downloaded_md5,downloaded_sha1,finished_at,last_error'
                . ') VALUES(?,"upload_to_parent",?,"downloaded",?,?,?,?,NOW(),?)'
            );
            $statement->execute([
                (int)$peer['id'],
                !empty($meta['remote_id']) ? $meta['remote_id'] : null,
                $bytes,
                $bytes,
                $relative,
                $md5,
                $sha1,
                'Received SHA-256 verified upload from child: ' . (string)($meta['name'] ?? ''),
            ]);
            $jobId = (int)$this->db->lastInsertId();
            \fed_log(
                $this->db,
                (int)$peer['id'],
                $jobId,
                'INFO',
                'UPLOAD_RECEIVED',
                'Received verified streaming upload as ' . $safeName
            );

            return [
                'ok' => true,
                'job_id' => $jobId,
                'status' => 'downloaded',
                'bytes' => $bytes,
                'sha256' => $sha256,
            ];
        } catch (Throwable $error) {
            @unlink($part);
            throw $error;
        }
    }

    private function incomingDirectory(): string
    {
        $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . '/federation/incoming';
        if (!is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Could not create incoming folder.');
        }
        return $directory;
    }

    private function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?: 'upload.bin';
    }

    /** @return array{0:int,1:string,2:string,3:string} */
    private function stream(string $path, int $expectedBytes, string $expectedHash, int $limit): array
    {
        $input = fopen('php://input', 'rb');
        $output = fopen($path, 'xb');
        if (!$input || !$output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($path);
            throw new RuntimeException('Could not open upload stream.');
        }

        $bytes = 0;
        $md5 = hash_init('md5');
        $sha1 = hash_init('sha1');
        $sha256 = hash_init('sha256');
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('Upload stream read failed.');
                }
                if ($chunk === '') {
                    continue;
                }
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > $limit || fwrite($output, $chunk) !== $length) {
                    throw new RuntimeException('Upload stream write failed.');
                }
                hash_update($md5, $chunk);
                hash_update($sha1, $chunk);
                hash_update($sha256, $chunk);
            }

            $sha256Value = hash_final($sha256);
            if ($bytes !== $expectedBytes || !hash_equals($expectedHash, $sha256Value)) {
                throw new RuntimeException('Upload integrity verification failed.');
            }
            return [$bytes, hash_final($md5), hash_final($sha1), $sha256Value];
        } catch (Throwable $error) {
            @unlink($path);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
