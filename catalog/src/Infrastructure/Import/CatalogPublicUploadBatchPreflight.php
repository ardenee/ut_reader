<?php
/**
 * Batched public upload eligibility checks and reservation creation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogPublicUploadSettingsStore;

final class CatalogPublicUploadBatchPreflight
{
    public const MAX_FILES = 100;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param list<array<string,mixed>> $manifest
     * @return array{files:list<array<string,mixed>>,accepted:int,skipped:int,rejected:int,expired_released:int}
     */
    public function inspect(array $manifest, string $ipAddress, string $userAgent): array
    {
        if ($manifest === [] || count($manifest) > self::MAX_FILES) {
            throw new \InvalidArgumentException('Public upload preflight accepts between 1 and 100 files per batch.');
        }

        $settings = (new CatalogPublicUploadSettingsStore($this->db, $this->config))->settings();
        if (empty($settings['enabled'])) {
            throw new RuntimeException('Public contributions are currently disabled.');
        }
        $storageRoot = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        $free = $storageRoot !== '' ? @disk_free_space($storageRoot) : false;
        if ($free !== false && (int)$free < (int)$settings['min_free_bytes']) {
            throw new RuntimeException('Public contributions are temporarily paused because the storage reserve has been reached.');
        }

        $packedIp = @inet_pton(trim($ipAddress));
        if (!is_string($packedIp) || $packedIp === '') {
            throw new RuntimeException('Contributor address is unavailable.');
        }

        $policy = new CatalogUploadBucketFilePolicy($this->db, $this->config);
        $normalized = [];
        $results = [];
        $identityKeys = [];
        $md5s = [];
        $guids = [];

        foreach (array_values($manifest) as $index => $item) {
            $clientId = trim((string)($item['client_id'] ?? (string)$index));
            if ($clientId === '' || strlen($clientId) > 80) {
                $clientId = (string)$index;
            }

            try {
                $name = $policy->cleanName((string)($item['name'] ?? ''), 'Public upload filename is missing.');
                if ($policy->isArchive($name) || $policy->isPakContainer($name)) {
                    throw new \InvalidArgumentException(
                        'Public contribution upload currently accepts Unreal packages and .uz/.uz2/.uz3 redirects only.'
                    );
                }
                $policy->validateName($name, true);

                $size = (int)($item['size'] ?? 0);
                if ($size < 1 || $size > (int)$settings['max_file_bytes']) {
                    throw new \InvalidArgumentException(
                        'File size is outside the public contribution limit (maximum '
                        . $this->formatBytes((int)$settings['max_file_bytes']) . ').'
                    );
                }

                $relativePath = $this->relativePath((string)($item['relative_path'] ?? $name), $name);
                $redirect = $policy->isRedirectWrapper($name);
                $md5 = strtolower(trim((string)($item['md5'] ?? '')));
                $sha1 = strtolower(trim((string)($item['sha1'] ?? '')));
                if (!$redirect && (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1)) {
                    throw new \InvalidArgumentException('Client MD5 and SHA-1 are required before this package can be uploaded.');
                }
                if ($redirect) {
                    $md5 = preg_match('/^[a-f0-9]{32}$/', $md5) === 1 ? $md5 : '';
                    $sha1 = preg_match('/^[a-f0-9]{40}$/', $sha1) === 1 ? $sha1 : '';
                }

                $guid = strtoupper(trim((string)($item['guid'] ?? '')));
                if ($guid !== '' && preg_match('/^[A-F0-9-]{16,80}$/', $guid) !== 1) {
                    $guid = '';
                }

                $identityKey = $md5 !== '' && $sha1 !== ''
                    ? hash('sha256', $md5 . "\0" . $sha1 . "\0" . $size)
                    : '';
                if ($identityKey !== '' && isset($identityKeys[$identityKey])) {
                    $results[$index] = [
                        'client_id' => $clientId,
                        'action' => 'skip',
                        'reason' => 'duplicate_in_batch',
                        'message' => 'An identical file is already present earlier in this 100-file batch.',
                    ];
                    continue;
                }
                if ($identityKey !== '') {
                    $identityKeys[$identityKey] = $index;
                    $md5s[$md5] = true;
                }
                if ($guid !== '') {
                    $guids[$guid] = true;
                }

                $normalized[$index] = [
                    'client_id' => $clientId,
                    'name' => $name,
                    'relative_path' => $relativePath,
                    'size' => $size,
                    'md5' => $md5,
                    'sha1' => $sha1,
                    'guid' => $guid,
                    'redirect' => $redirect,
                    'identity_key' => $identityKey,
                ];
            } catch (\Throwable $error) {
                $results[$index] = [
                    'client_id' => $clientId,
                    'action' => 'reject',
                    'reason' => 'invalid',
                    'message' => trim($error->getMessage()) ?: 'File is not eligible for public contribution upload.',
                ];
            }
        }

        $expiredReleased = $this->releaseExpiredReservations();
        $existingByIdentity = $this->catalogIdentityMatches(array_keys($md5s));
        $pendingByIdentity = $this->pendingIdentityMatches(array_keys($identityKeys));
        $guidMatches = $this->guidMatches(array_keys($guids));

        foreach ($normalized as $index => $item) {
            $identity = (string)$item['identity_key'];
            if ($identity !== '' && isset($existingByIdentity[$identity])) {
                $match = $existingByIdentity[$identity];
                $results[$index] = [
                    'client_id' => $item['client_id'],
                    'action' => 'skip',
                    'reason' => 'already_catalogued',
                    'file_id' => (int)$match['file_id'],
                    'message' => 'Identical bytes are already held by UnrealDB as file #' . (int)$match['file_id'] . '.',
                ];
                unset($normalized[$index]);
                continue;
            }
            if ($identity !== '' && isset($pendingByIdentity[$identity])) {
                $results[$index] = [
                    'client_id' => $item['client_id'],
                    'action' => 'skip',
                    'reason' => 'already_pending',
                    'message' => 'Identical bytes are already reserved, uploading, or awaiting public-upload processing.',
                ];
                unset($normalized[$index]);
            }
        }

        $lockName = 'unrealdb-public-upload-ip-' . substr(hash('sha256', $packedIp), 0, 40);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,5)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Public upload preflight is busy for this address. Retry the batch.');
        }

        try {
            $usage = $this->usage($packedIp);
            $remainingFiles = max(0, (int)$settings['files_per_hour'] - $usage['files']);
            $remainingBytes = max(0, (int)$settings['bytes_per_hour'] - $usage['bytes']);
            $remainingOutstanding = max(0, (int)$settings['max_outstanding'] - $usage['outstanding']);

            $reservations = [];
            foreach ($normalized as $index => $item) {
                if ($remainingFiles < 1) {
                    $results[$index] = $this->limitedResult($item, 'Hourly public-upload file limit reached.');
                    continue;
                }
                if ((int)$item['size'] > $remainingBytes) {
                    $results[$index] = $this->limitedResult($item, 'Hourly public-upload byte limit reached.');
                    continue;
                }
                if ($remainingOutstanding < 1) {
                    $results[$index] = $this->limitedResult($item, 'Too many public uploads are currently reserved from this address.');
                    continue;
                }

                $token = bin2hex(random_bytes(32));
                $clientKey = hash(
                    'sha256',
                    (string)$item['relative_path'] . "\0" . (string)$item['size'] . "\0"
                    . (string)$item['md5'] . "\0" . (string)$item['sha1']
                );
                $reservations[$index] = $item + [
                    'token' => $token,
                    'client_key' => $clientKey,
                    'active_identity_key' => (string)$item['identity_key'] !== '' ? (string)$item['identity_key'] : null,
                ];
                $remainingFiles--;
                $remainingBytes -= (int)$item['size'];
                $remainingOutstanding--;
            }

            $insertedTokens = $this->insertReservations(
                $reservations,
                $packedIp,
                substr(trim($userAgent), 0, 500),
                (int)$settings['reservation_seconds']
            );

            foreach ($reservations as $index => $item) {
                $token = (string)$item['token'];
                if (!isset($insertedTokens[$token])) {
                    $results[$index] = [
                        'client_id' => $item['client_id'],
                        'action' => 'skip',
                        'reason' => 'already_pending',
                        'message' => 'Identical bytes were reserved by another contributor while this batch was being checked.',
                    ];
                    continue;
                }

                $guid = (string)$item['guid'];
                $guidInfo = $guid !== '' && isset($guidMatches[$guid]) ? $guidMatches[$guid] : null;
                $results[$index] = [
                    'client_id' => $item['client_id'],
                    'action' => 'upload',
                    'upload_token' => $token,
                    'reservation_expires_seconds' => (int)$settings['reservation_seconds'],
                    'guid_match' => $guidInfo,
                    'message' => is_array($guidInfo)
                        ? 'Upload allowed. This package GUID already appears in the catalog, but the physical hashes differ; it will be retained for admin review.'
                        : 'Upload allowed.',
                ];
            }
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable) {
            }
        }

        ksort($results);
        $files = array_values($results);
        $accepted = count(array_filter($files, static fn(array $row): bool => ($row['action'] ?? '') === 'upload'));
        $rejected = count(array_filter($files, static fn(array $row): bool => ($row['action'] ?? '') === 'reject'));
        return [
            'files' => $files,
            'accepted' => $accepted,
            'skipped' => count($files) - $accepted - $rejected,
            'rejected' => $rejected,
            'expired_released' => $expiredReleased,
        ];
    }

    private function releaseExpiredReservations(): int
    {
        $statement = $this->db->prepare(
            'UPDATE ue_public_uploads SET status="expired",active_identity_key=NULL,'
            . 'result_message="Reservation expired before upload completed",updated_at=UTC_TIMESTAMP(6) '
            . 'WHERE status IN ("reserved","uploading") AND reservation_expires_at<=UTC_TIMESTAMP(6) LIMIT 1000'
        );
        $statement->execute();
        return max(0, $statement->rowCount());
    }

    /** @return array<string,array{file_id:int}> */
    private function catalogIdentityMatches(array $md5s): array
    {
        if ($md5s === []) {
            return [];
        }
        $matches = [];
        foreach (array_chunk($md5s, self::MAX_FILES) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,game_id,relative_path,file_size,LOWER(md5) md5,LOWER(sha1) sha1,'
                . 'scan_status,unverified_queue_game_id,unverified_queue_name FROM ue_files '
                . 'WHERE md5 IN (' . $placeholders . ') AND scan_status IN ("verified","unverified")'
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $md5 = strtolower((string)($row['md5'] ?? ''));
                $sha1 = strtolower((string)($row['sha1'] ?? ''));
                $size = max(0, (int)($row['file_size'] ?? 0));
                if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1 || $size < 1) {
                    continue;
                }
                $physicalPath = (new CatalogUploadDuplicateDetector($this->db, $this->config))
                    ->locatePhysicalPath($row);
                if ($physicalPath === null) {
                    continue;
                }
                $physicalSize = filesize($physicalPath);
                if ($physicalSize === false || (int)$physicalSize !== $size) {
                    continue;
                }
                $key = hash('sha256', $md5 . "\0" . $sha1 . "\0" . $size);
                $matches[$key] ??= ['file_id' => (int)$row['id']];
            }
        }
        return $matches;
    }

    /** @return array<string,true> */
    private function pendingIdentityMatches(array $identityKeys): array
    {
        if ($identityKeys === []) {
            return [];
        }
        $matches = [];
        foreach (array_chunk($identityKeys, self::MAX_FILES) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT active_identity_key FROM ue_public_uploads WHERE active_identity_key IN (' . $placeholders . ') '
                . 'AND status IN ("reserved","uploading","uploaded","processing") '
                . 'AND (status NOT IN ("reserved","uploading") OR reservation_expires_at>UTC_TIMESTAMP(6))'
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $key) {
                $value = strtolower(trim((string)$key));
                if (preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                    $matches[$value] = true;
                }
            }
        }
        return $matches;
    }

    /** @return array<string,array{count:int,file_id:int}> */
    private function guidMatches(array $guids): array
    {
        if ($guids === []) {
            return [];
        }
        $matches = [];
        foreach (array_chunk($guids, self::MAX_FILES) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT UPPER(package_guid) package_guid,COUNT(*) match_count,MIN(id) file_id FROM ue_files '
                . 'WHERE package_guid IN (' . $placeholders . ') AND scan_status IN ("verified","unverified") '
                . 'GROUP BY package_guid'
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $guid = strtoupper(trim((string)($row['package_guid'] ?? '')));
                if ($guid !== '') {
                    $matches[$guid] = [
                        'count' => max(1, (int)($row['match_count'] ?? 1)),
                        'file_id' => max(0, (int)($row['file_id'] ?? 0)),
                    ];
                }
            }
        }
        return $matches;
    }

    /** @return array{files:int,bytes:int,outstanding:int} */
    private function usage(string $packedIp): array
    {
        $hour = $this->db->prepare(
            'SELECT COUNT(*) file_count,COALESCE(SUM(file_size),0) byte_count FROM ue_public_uploads '
            . 'WHERE submitter_ip=? AND created_at>=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR '
            . 'AND status NOT IN ("cancelled","rejected")'
        );
        $hour->execute([$packedIp]);
        $row = $hour->fetch(PDO::FETCH_ASSOC) ?: [];

        $active = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_public_uploads WHERE submitter_ip=? '
            . 'AND status IN ("reserved","uploading") AND reservation_expires_at>UTC_TIMESTAMP(6)'
        );
        $active->execute([$packedIp]);

        return [
            'files' => max(0, (int)($row['file_count'] ?? 0)),
            'bytes' => max(0, (int)($row['byte_count'] ?? 0)),
            'outstanding' => max(0, (int)$active->fetchColumn()),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $reservations
     * @return array<string,true>
     */
    private function insertReservations(array $reservations, string $packedIp, string $userAgent, int $reservationSeconds): array
    {
        if ($reservations === []) {
            return [];
        }

        $values = [];
        $params = [];
        foreach ($reservations as $item) {
            $values[] = '(?,?,?,?,?,?,?,?,?,?,?,?)';
            array_push(
                $params,
                (string)$item['token'],
                (string)$item['client_key'],
                (string)$item['name'],
                (string)$item['relative_path'],
                (int)$item['size'],
                (string)$item['md5'] !== '' ? (string)$item['md5'] : null,
                (string)$item['sha1'] !== '' ? (string)$item['sha1'] : null,
                (string)$item['guid'] !== '' ? (string)$item['guid'] : null,
                $item['active_identity_key'],
                $packedIp,
                $userAgent,
                gmdate('Y-m-d H:i:s', time() + $reservationSeconds)
            );
        }

        $statement = $this->db->prepare(
            'INSERT IGNORE INTO ue_public_uploads '
            . '(upload_token,client_key,original_name,relative_path,file_size,client_md5,client_sha1,client_guid,'
            . 'active_identity_key,submitter_ip,user_agent,reservation_expires_at) VALUES '
            . implode(',', $values)
        );
        $statement->execute($params);

        $tokens = array_values(array_map(static fn(array $item): string => (string)$item['token'], $reservations));
        $placeholders = implode(',', array_fill(0, count($tokens), '?'));
        $check = $this->db->prepare(
            'SELECT upload_token FROM ue_public_uploads WHERE upload_token IN (' . $placeholders . ')'
        );
        $check->execute($tokens);
        $inserted = [];
        foreach ($check->fetchAll(PDO::FETCH_COLUMN) ?: [] as $token) {
            $inserted[(string)$token] = true;
        }
        return $inserted;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function limitedResult(array $item, string $message): array
    {
        return [
            'client_id' => (string)$item['client_id'],
            'action' => 'reject',
            'reason' => 'rate_limited',
            'message' => $message,
        ];
    }

    private function relativePath(string $path, string $fallback): string
    {
        $path = trim(str_replace('\\', '/', str_replace("\0", '', $path)));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = ltrim($path, '/');
        if ($path === '' || strlen($path) > 1000 || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return $fallback;
        }
        return $path;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, (float)$bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? number_format($value, 0) : number_format($value, 2)) . ' ' . $units[$unit];
    }
}
