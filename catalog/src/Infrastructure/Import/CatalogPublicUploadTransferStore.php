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
        int $uploadError,
        string $contentEncoding = 'identity'
    ): array {
        $token = $this->token($uploadToken);
        $ip = $this->packedIp($ipAddress);
        if ($uploadError !== UPLOAD_ERR_OK || !is_file($temporaryPath) || !is_readable($temporaryPath)) {
            throw new RuntimeException('The public upload chunk is unavailable.');
        }
        $transportBytes = filesize($temporaryPath);
        if ($transportBytes === false || (int)$transportBytes < 1) {
            throw new RuntimeException('The public upload chunk is empty.');
        }
        $encoding = strtolower(trim($contentEncoding));
        if (!in_array($encoding, ['identity', 'gzip'], true)) {
            throw new \InvalidArgumentException('Unsupported public upload content encoding: ' . $encoding . '.');
        }
        if ($encoding === 'gzip' && !function_exists('gzopen')) {
            throw new RuntimeException('This server cannot decode gzip public-upload chunks.');
        }

        $settings = (new CatalogPublicUploadSettingsStore($this->db, $this->config))->settings();

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
            $remaining = max(0, $expected - $received);
            $maximumChunk = CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes($this->config);
            $maximumDecoded = min($remaining, $maximumChunk);
            if ($expected < 1 || $maximumDecoded < 1) {
                throw new RuntimeException(
                    'Public upload byte count exceeds the reservation: expected=' . $expected
                    . ', received_before=' . $received . ', transport_chunk=' . (int)$transportBytes . '.'
                );
            }

            $free = @disk_free_space($this->storageRoot);
            if ($free !== false && (int)$free - $maximumDecoded < (int)$settings['min_free_bytes']) {
                throw new RuntimeException('Public uploads are temporarily paused because the storage reserve has been reached.');
            }

            $path = $this->partPath($token);
            $this->ensureDirectory(dirname($path));
            $decodedBytes = $this->appendTransportChunk(
                $temporaryPath,
                $path,
                $received,
                $maximumDecoded,
                $encoding
            );
            if ($received + $decodedBytes > $expected) {
                throw new RuntimeException(
                    'Decoded public upload byte count exceeds the reservation: expected=' . $expected
                    . ', received_before=' . $received . ', decoded_chunk=' . $decodedBytes . '.'
                );
            }

            $update = $this->db->prepare(
                'UPDATE ue_public_uploads SET status="uploading",received_bytes=?,next_chunk_index=?,updated_at=UTC_TIMESTAMP(6) '
                . 'WHERE id=?'
            );
            $update->execute([$received + $decodedBytes, $chunkIndex + 1, (int)$row['id']]);
            $this->db->commit();

            return [
                'id' => (int)$row['id'],
                'upload_token' => $token,
                'received_bytes' => $received + $decodedBytes,
                'file_size' => $expected,
                'transport_bytes' => (int)$transportBytes,
                'decoded_bytes' => $decodedBytes,
                'content_encoding' => $encoding,
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
    /** @return array<string,mixed> */
    public function statusForContributor(string $uploadToken, string $ipAddress): array
    {
        $token = $this->token($uploadToken);
        $ip = $this->packedIp($ipAddress);
        $row = $this->ownedRow($token, $ip, false);

        return [
            'id' => (int)($row['id'] ?? 0),
            'status' => strtolower(trim((string)($row['status'] ?? ''))),
            'background_job_id' => max(0, (int)($row['background_job_id'] ?? 0)),
            'unverified_file_id' => max(0, (int)($row['unverified_file_id'] ?? 0)),
            'server_md5' => strtolower(trim((string)($row['server_md5'] ?? ''))),
            'server_sha1' => strtolower(trim((string)($row['server_sha1'] ?? ''))),
            'server_guid' => trim((string)($row['server_guid'] ?? '')),
            'result_message' => trim((string)($row['result_message'] ?? '')),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }
    /**
     * @param list<string> $uploadTokens
     * @return list<array<string,mixed>>
     */
    public function statusesForContributor(array $uploadTokens, string $ipAddress): array
    {
        $tokens = [];
        foreach ($uploadTokens as $uploadToken) {
            $token = strtolower(trim((string)$uploadToken));
            if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
                $tokens[$token] = true;
            }
        }
        $tokens = array_slice(array_keys($tokens), 0, 100);
        if ($tokens === []) {
            return [];
        }

        $ip = $this->packedIp($ipAddress);
        $placeholders = implode(',', array_fill(0, count($tokens), '?'));
        $statement = $this->db->prepare(
            'SELECT upload_token,id,status,background_job_id,unverified_file_id,server_md5,server_sha1,server_guid,'
            . 'result_message,updated_at FROM ue_public_uploads '
            . 'WHERE submitter_ip=? AND upload_token IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$ip], $tokens));

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'upload_token' => strtolower(trim((string)($row['upload_token'] ?? ''))),
                'id' => (int)($row['id'] ?? 0),
                'status' => strtolower(trim((string)($row['status'] ?? ''))),
                'background_job_id' => max(0, (int)($row['background_job_id'] ?? 0)),
                'unverified_file_id' => max(0, (int)($row['unverified_file_id'] ?? 0)),
                'server_md5' => strtolower(trim((string)($row['server_md5'] ?? ''))),
                'server_sha1' => strtolower(trim((string)($row['server_sha1'] ?? ''))),
                'server_guid' => trim((string)($row['server_guid'] ?? '')),
                'result_message' => trim((string)($row['result_message'] ?? '')),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return $rows;
    }


    /**
     * Delete terminal public-upload ledger rows selected by an administrator.
     * Failed rows may still own quarantined diagnostic bytes; deleting the row
     * explicitly deletes those bytes as part of the same admin cleanup action.
     *
     * @param list<int> $ids
     * @return array{requested:int,deleted:int,ignored:int}
     */
    public function deleteTerminalForAdmin(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        $ids = array_slice($ids, 0, 500);
        if ($ids === []) {
            return ['requested' => 0, 'deleted' => 0, 'ignored' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            'SELECT id,upload_token,status FROM ue_public_uploads '
            . 'WHERE id IN (' . $placeholders . ') '
            . 'AND status IN ("duplicate","failed","cancelled","expired","rejected")'
        );
        $statement->execute($ids);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $deletableIds = [];
        foreach ($rows as $row) {
            $id = max(0, (int)($row['id'] ?? 0));
            $token = strtolower(trim((string)($row['upload_token'] ?? '')));
            if ($id < 1 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
                continue;
            }
            $this->removeQuarantine($token);
            $deletableIds[] = $id;
        }

        if ($deletableIds !== []) {
            $deletePlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
            $delete = $this->db->prepare(
                'DELETE FROM ue_public_uploads WHERE id IN (' . $deletePlaceholders . ') '
                . 'AND status IN ("duplicate","failed","cancelled","expired","rejected")'
            );
            $delete->execute($deletableIds);
            $deleted = max(0, $delete->rowCount());
        } else {
            $deleted = 0;
        }

        return [
            'requested' => count($ids),
            'deleted' => $deleted,
            'ignored' => max(0, count($ids) - $deleted),
        ];
    }

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

    private function appendTransportChunk(
        string $temporaryPath,
        string $destination,
        int $expectedOffset,
        int $maximumDecodedBytes,
        string $encoding
    ): int {
        $output = @fopen($destination, 'c+b');
        if (!is_resource($output)) {
            throw new RuntimeException('Could not open public upload staging stream.');
        }

        $input = null;
        $decoded = 0;
        $locked = false;
        try {
            if (!flock($output, LOCK_EX)) {
                throw new RuntimeException('Could not lock public upload staging.');
            }
            $locked = true;
            $stat = fstat($output);
            $actualOffset = is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
            if ($actualOffset !== $expectedOffset || fseek($output, $expectedOffset) !== 0) {
                throw new RuntimeException(
                    'Public upload staging offset mismatch: expected=' . $expectedOffset
                    . ', actual=' . $actualOffset . '.'
                );
            }

            $input = $encoding === 'gzip'
                ? @gzopen($temporaryPath, 'rb')
                : @fopen($temporaryPath, 'rb');
            if (!is_resource($input)) {
                throw new RuntimeException(
                    $encoding === 'gzip'
                        ? 'Could not open gzip public upload chunk.'
                        : 'Could not open public upload chunk.'
                );
            }

            while ($encoding === 'gzip' ? !gzeof($input) : !feof($input)) {
                $buffer = $encoding === 'gzip'
                    ? gzread($input, 1024 * 1024)
                    : fread($input, 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new RuntimeException('Could not decode/read the public upload chunk.');
                }
                if ($buffer === '') {
                    $atEof = $encoding === 'gzip' ? gzeof($input) : feof($input);
                    if ($atEof) {
                        break;
                    }
                    throw new RuntimeException('Public upload chunk read stopped before EOF.');
                }

                $length = strlen($buffer);
                if ($decoded + $length > $maximumDecodedBytes) {
                    throw new RuntimeException(
                        'Decoded public upload chunk exceeds the allowed logical chunk size: maximum='
                        . $maximumDecodedBytes . ', decoded_before=' . $decoded . ', next=' . $length . '.'
                    );
                }
                $offset = 0;
                while ($offset < $length) {
                    $count = fwrite($output, substr($buffer, $offset));
                    if ($count === false || $count < 1) {
                        throw new RuntimeException('Could not write the decoded public upload chunk.');
                    }
                    $offset += $count;
                    $decoded += $count;
                }
            }
            if ($decoded < 1) {
                throw new RuntimeException('Decoded public upload chunk is empty.');
            }
            if (!fflush($output)) {
                throw new RuntimeException('Could not flush public upload staging.');
            }
            if (function_exists('fsync')) {
                @fsync($output);
            }
            return $decoded;
        } catch (\Throwable $error) {
            // A malformed/compression-bomb chunk must not leave the physical
            // staging file ahead of the database ledger.
            @ftruncate($output, $expectedOffset);
            @fflush($output);
            throw $error;
        } finally {
            if (is_resource($input)) {
                if ($encoding === 'gzip') {
                    gzclose($input);
                } else {
                    fclose($input);
                }
            }
            if ($locked) {
                @flock($output, LOCK_UN);
            }
            fclose($output);
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
