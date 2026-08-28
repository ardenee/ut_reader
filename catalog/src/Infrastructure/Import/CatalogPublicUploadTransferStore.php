<?php
/**
 * Sequential chunk transport for anonymous contribution uploads.
 *
 * Public bytes live under storage/public-uploads until a background worker has
 * authoritatively validated them and moved the package into unverified staging.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogPublicUploadSettingsStore;

final class CatalogPublicUploadTransferStore
{
    private string $storageRoot;
    private string $incomingRoot;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($root === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for public uploads.');
        }
        $this->storageRoot = $root;
        $this->incomingRoot = $root . DIRECTORY_SEPARATOR . 'public-uploads' . DIRECTORY_SEPARATOR . 'incoming';
    }

    /**
     * @return array<string,mixed>
     */
    public function writeChunk(
        string $uploadToken,
        string $ipAddress,
        int $chunkIndex,
        string $temporaryPath,
        int $uploadError
    ): array {
        $token = $this->token($uploadToken);
        $ip = $this->packedIp($ipAddress);
        if ($uploadError !== UPLOAD_ERR_OK || !is_file($temporaryPath) || !is_readable($temporaryPath)) {
            throw new RuntimeException('The public upload chunk is unavailable.');
        }
        $chunkBytes = filesize($temporaryPath);
        if ($chunkBytes === false || (int)$chunkBytes < 1) {
            throw new RuntimeException('The public upload chunk is empty.');
        }

        $settings = (new CatalogPublicUploadSettingsStore($this->db, $this->config))->settings();
        $free = @disk_free_space($this->storageRoot);
        if ($free !== false && (int)$free - (int)$chunkBytes < (int)$settings['min_free_bytes']) {
            throw new RuntimeException('Public uploads are temporarily paused because the storage reserve has been reached.');
        }

        $this->db->beginTransaction();
        try {
            $row = $this->ownedRow($token, $ip, true);
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (!in_array($status, ['reserved', 'uploading'], true)) {
                throw new RuntimeException('This public upload reservation is no longer writable.');
            }
            if (strtotime((string)($row['reservation_expires_at'] ?? '')) <= time()) {
                throw new RuntimeException('This public upload reservation has expired.');
            }
            if ($chunkIndex !== (int)($row['next_chunk_index'] ?? 0)) {
                throw new RuntimeException(
                    'Public upload chunk order mismatch: expected=' . (int)($row['next_chunk_index'] ?? 0)
                    . ', received=' . $chunkIndex . '.'
                );
            }

            if ($status === 'reserved') {
                $active = $this->db->prepare(
                    'SELECT id FROM ue_public_uploads WHERE submitter_ip=? AND status="uploading" AND id<>? LIMIT 1'
                );
                $active->execute([$ip, (int)$row['id']]);
                if ($active->fetchColumn() !== false) {
                    throw new RuntimeException('Only one public upload may transfer at a time from this address.');
                }
            }

            $received = max(0, (int)($row['received_bytes'] ?? 0));
            $expected = max(0, (int)($row['file_size'] ?? 0));
            if ($expected < 1 || $received + (int)$chunkBytes > $expected) {
                throw new RuntimeException(
                    'Public upload byte count exceeds the reservation: expected=' . $expected
                    . ', received_before=' . $received . ', chunk=' . (int)$chunkBytes . '.'
                );
            }

            $path = $this->partPath($token);
            $this->ensureDirectory(dirname($path));
            $this->appendVerified($temporaryPath, $path, $received, (int)$chunkBytes);

            $update = $this->db->prepare(
                'UPDATE ue_public_uploads SET status="uploading",received_bytes=?,next_chunk_index=?,updated_at=UTC_TIMESTAMP(6) '
                . 'WHERE id=?'
            );
            $update->execute([$received + (int)$chunkBytes, $chunkIndex + 1, (int)$row['id']]);
            $this->db->commit();

            return [
                'id' => (int)$row['id'],
                'upload_token' => $token,
                'received_bytes' => $received + (int)$chunkBytes,
                'file_size' => $expected,
                'next_chunk_index' => $chunkIndex + 1,
                'status' => 'uploading',
            ];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function complete(string $uploadToken, string $ipAddress): array
    {
        $token = $this->token($uploadToken);
        $ip = $this->packedIp($ipAddress);

        $this->db->beginTransaction();
        try {
            $row = $this->ownedRow($token, $ip, true);
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (in_array($status, ['uploaded', 'processing', 'unverified', 'duplicate'], true)) {
                $this->db->commit();
                return $row;
            }
            if (!in_array($status, ['reserved', 'uploading'], true)) {
                throw new RuntimeException('This public upload reservation cannot be completed.');
            }

            $expected = max(0, (int)($row['file_size'] ?? 0));
            $received = max(0, (int)($row['received_bytes'] ?? 0));
            if ($expected < 1 || $received !== $expected) {
                throw new RuntimeException(
                    'Public upload is incomplete: expected_bytes=' . $expected . ', received_bytes=' . $received . '.'
                );
            }

            $part = $this->partPath($token);
            clearstatcache(true, $part);
            $physical = is_file($part) ? filesize($part) : false;
            if ($physical === false || (int)$physical !== $expected) {
                throw new RuntimeException(
                    'Public upload staging size mismatch: expected_bytes=' . $expected
                    . ', physical_bytes=' . ($physical === false ? 'missing' : (string)(int)$physical) . '.'
                );
            }

            $final = $this->finalPath($token);
            if (!@rename($part, $final)) {
                throw new RuntimeException('Could not publish the completed public upload into quarantine.');
            }
            $relative = $this->storageRelative($final);
            $update = $this->db->prepare(
                'UPDATE ue_public_uploads SET status="uploaded",quarantine_relative_path=?,completed_at=UTC_TIMESTAMP(6),'
                . 'updated_at=UTC_TIMESTAMP(6) WHERE id=?'
            );
            $update->execute([$relative, (int)$row['id']]);
            $this->db->commit();

            $row['status'] = 'uploaded';
            $row['quarantine_relative_path'] = $relative;
            $row['completed_at'] = gmdate('Y-m-d H:i:s');
            return $row;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function cancel(string $uploadToken, string $ipAddress): void
    {
        $token = $this->token($uploadToken);
        $ip = $this->packedIp($ipAddress);

        $this->db->beginTransaction();
        try {
            $row = $this->ownedRow($token, $ip, true);
            if (!in_array(strtolower((string)$row['status']), ['reserved', 'uploading'], true)) {
                $this->db->rollBack();
                return;
            }
            $statement = $this->db->prepare(
                'UPDATE ue_public_uploads SET status="cancelled",active_identity_key=NULL,'
                . 'quarantine_relative_path=NULL,received_bytes=0,'
                . 'result_message="Cancelled by contributor",updated_at=UTC_TIMESTAMP(6) WHERE id=?'
            );
            $statement->execute([(int)$row['id']]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        @unlink($this->partPath($token));
        @unlink($this->finalPath($token));
    }

    /** @return array<string,mixed> */
    public function ledgerForJob(int $publicUploadId, string $uploadToken): array
    {
        $token = $this->token($uploadToken);
        $statement = $this->db->prepare(
            'SELECT * FROM ue_public_uploads WHERE id=? AND upload_token=? LIMIT 1'
        );
        $statement->execute([$publicUploadId, $token]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Public upload ledger row is missing.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function resolveForJob(int $publicUploadId, string $uploadToken): array
    {
        $row = $this->ledgerForJob($publicUploadId, $uploadToken);
        $relative = trim((string)($row['quarantine_relative_path'] ?? ''));
        if ($relative === '') {
            throw new RuntimeException('Public upload quarantine path is missing.');
        }
        $row['physical_path'] = $this->resolveRelative($relative);
        return $row;
    }

    public function removeQuarantine(string $uploadToken): void
    {
        $token = $this->token($uploadToken);
        @unlink($this->partPath($token));
        @unlink($this->finalPath($token));
        @rmdir(dirname($this->finalPath($token)));
    }

    private function appendVerified(string $temporaryPath, string $destination, int $expectedOffset, int $expectedChunkBytes): void
    {
        $input = @fopen($temporaryPath, 'rb');
        $output = @fopen($destination, 'c+b');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not open public upload staging streams.');
        }

        $written = 0;
        try {
            if (!flock($output, LOCK_EX)) {
                throw new RuntimeException('Could not lock public upload staging.');
            }
            $stat = fstat($output);
            $actualOffset = is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
            if ($actualOffset !== $expectedOffset || fseek($output, $expectedOffset) !== 0) {
                throw new RuntimeException(
                    'Public upload staging offset mismatch: expected=' . $expectedOffset . ', actual=' . $actualOffset . '.'
                );
            }
            while (!feof($input)) {
                $buffer = fread($input, 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new RuntimeException('Could not read the public upload chunk.');
                }
                if ($buffer === '') {
                    if (feof($input)) {
                        break;
                    }
                    throw new RuntimeException('Public upload chunk read stopped before EOF.');
                }
                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $count = fwrite($output, substr($buffer, $offset));
                    if ($count === false || $count < 1) {
                        throw new RuntimeException('Could not write the public upload chunk.');
                    }
                    $offset += $count;
                    $written += $count;
                }
            }
            if (!fflush($output)) {
                throw new RuntimeException('Could not flush public upload staging.');
            }
            if (function_exists('fsync')) {
                @fsync($output);
            }
        } finally {
            @flock($output, LOCK_UN);
            fclose($input);
            fclose($output);
        }

        if ($written !== $expectedChunkBytes) {
            throw new RuntimeException(
                'Public upload chunk byte mismatch: expected=' . $expectedChunkBytes . ', written=' . $written . '.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function ownedRow(string $token, string $packedIp, bool $forUpdate): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM ue_public_uploads WHERE upload_token=? AND submitter_ip=? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$token, $packedIp]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Public upload reservation was not found for this address.');
        }
        return $row;
    }

    private function resolveRelative(string $relative): string
    {
        $normalized = str_replace('\\', '/', trim($relative));
        if (!str_starts_with($normalized, 'public-uploads/incoming/')
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')) {
            throw new RuntimeException('Public upload quarantine path is invalid.');
        }
        $candidate = $this->storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $real = realpath($candidate);
        $root = realpath($this->incomingRoot);
        if ($real === false || $root === false || !is_file($real) || is_link($real)) {
            throw new RuntimeException('Public upload quarantine file is unavailable.');
        }
        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $realNormalized = str_replace('\\', '/', $real);
        $inside = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($realNormalized), strtolower($rootPrefix))
            : str_starts_with($realNormalized, $rootPrefix);
        if (!$inside) {
            throw new RuntimeException('Public upload quarantine file escaped controlled storage.');
        }
        return $real;
    }

    private function storageRelative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->storageRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with(strtolower($normalized), strtolower($root))) {
            throw new RuntimeException('Public upload path escaped catalog storage.');
        }
        return ltrim(substr($normalized, strlen($root)), '/');
    }

    private function partPath(string $token): string
    {
        return $this->tokenDirectory($token) . DIRECTORY_SEPARATOR . $token . '.part';
    }

    private function finalPath(string $token): string
    {
        return $this->tokenDirectory($token) . DIRECTORY_SEPARATOR . $token . '.bin';
    }

    private function tokenDirectory(string $token): string
    {
        return $this->incomingRoot . DIRECTORY_SEPARATOR . substr($token, 0, 2);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create public upload quarantine storage.');
        }
    }

    private function token(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Public upload token is invalid.');
        }
        return $value;
    }

    private function packedIp(string $ipAddress): string
    {
        $packed = @inet_pton(trim($ipAddress));
        if (!is_string($packed) || $packed === '') {
            throw new RuntimeException('Contributor address is unavailable.');
        }
        return $packed;
    }
}
