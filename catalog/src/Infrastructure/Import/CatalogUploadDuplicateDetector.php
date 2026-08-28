<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finds upload duplicates only when matching bytes still exist in controlled storage.
 * Why: Candidate metadata is advisory; duplicate acceptance requires the physical file identity to match size, MD5 and SHA-1.
 * Role: Infrastructure duplicate detector for Upload Bucket preflight/finalization.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;

final class CatalogUploadDuplicateDetector
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/BaseGameProtection.php';
    }

    /**
     * @return array{
     *   duplicate:?array<string,mixed>,
     *   identity_matches:int,
     *   missing_physical_matches:int,
     *   missing_base_game_matches:int,
     *   physical_identity_mismatches:int
     * }
     */
    public function inspect(int $fileSize, string $md5, string $sha1): array
    {
        $md5 = strtolower(trim($md5));
        $sha1 = strtolower(trim($sha1));
        if ($fileSize < 1
            || preg_match('/^[a-f0-9]{32}$/', $md5) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \InvalidArgumentException('A valid size, MD5 and SHA-1 are required for duplicate inspection.');
        }

        \base_game_ensure($this->db);
        $rows = \catalog_all(
            $this->db,
            'SELECT f.id,f.game_id,g.slug game_slug,f.package_name,f.original_name,f.stored_name,f.relative_path,'
                . 'f.file_size,f.md5,f.sha1,f.package_guid,f.scan_status,f.unverified_queue_game_id,f.unverified_queue_name,'
                . 'EXISTS(SELECT 1 FROM ue_base_game_files bg '
                . 'WHERE bg.source_file_id=f.id '
                . 'OR (bg.game_id=f.game_id AND bg.package_guid=f.package_guid AND f.package_guid IS NOT NULL AND f.package_guid<>"")) AS is_base_game '
                . 'FROM ue_files f LEFT JOIN ue_games g ON g.id=f.game_id WHERE LOWER(f.md5)=? '
                . 'ORDER BY (f.scan_status="unverified" AND f.unverified_queue_game_id=0) DESC,f.id LIMIT 200',
            [$md5]
        );

        $missing = 0;
        $missingBaseGame = 0;
        $physicalIdentityMismatches = 0;
        foreach ($rows as $row) {
            $physicalPath = $this->locatePhysicalPath($row);
            if ($physicalPath === null) {
                $missing++;
                if (!empty($row['is_base_game'])) {
                    $missingBaseGame++;
                }
                continue;
            }

            $physicalSize = filesize($physicalPath);
            if ($physicalSize === false || (int)$physicalSize !== $fileSize) {
                $physicalIdentityMismatches++;
                continue;
            }

            $physicalIdentity = $this->physicalIdentity($physicalPath);
            if ($physicalIdentity === null) {
                $missing++;
                if (!empty($row['is_base_game'])) {
                    $missingBaseGame++;
                }
                continue;
            }
            if (!hash_equals($md5, $physicalIdentity['md5'])
                || !hash_equals($sha1, $physicalIdentity['sha1'])) {
                $physicalIdentityMismatches++;
                continue;
            }

            return [
                'duplicate' => [
                    'file_id' => (int)$row['id'],
                    'game_id' => (int)($row['game_id'] ?? 0),
                    'package_name' => (string)($row['package_name'] ?? ''),
                    'original_name' => (string)($row['original_name'] ?? ''),
                    'scan_status' => (string)($row['scan_status'] ?? ''),
                    'location_kind' => (string)($row['scan_status'] ?? '') === 'unverified'
                        && (int)($row['unverified_queue_game_id'] ?? -1) === 0
                        ? 'upload_bucket'
                        : 'catalog_storage',
                    'is_base_game' => !empty($row['is_base_game']),
                    'physical_path' => $physicalPath,
                    'file_size' => $fileSize,
                    'md5' => $physicalIdentity['md5'],
                    'sha1' => $physicalIdentity['sha1'],
                ],
                'identity_matches' => count($rows),
                'missing_physical_matches' => $missing,
                'missing_base_game_matches' => $missingBaseGame,
                'physical_identity_mismatches' => $physicalIdentityMismatches,
            ];
        }

        return [
            'duplicate' => null,
            'identity_matches' => count($rows),
            'missing_physical_matches' => $missing,
            'missing_base_game_matches' => $missingBaseGame,
            'physical_identity_mismatches' => $physicalIdentityMismatches,
        ];
    }

    /** @return array{md5:string,sha1:string}|null */
    private function physicalIdentity(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return null;
        }

        $md5Context = hash_init('md5');
        $sha1Context = hash_init('sha1');
        $failed = false;
        try {
            while (!feof($handle)) {
                $buffer = fread($handle, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    $failed = true;
                    break;
                }
                if ($buffer === '') {
                    if (feof($handle)) {
                        break;
                    }
                    $failed = true;
                    break;
                }
                hash_update($md5Context, $buffer);
                hash_update($sha1Context, $buffer);
            }
        } finally {
            fclose($handle);
        }

        if ($failed) {
            return null;
        }
        return [
            'md5' => hash_final($md5Context),
            'sha1' => hash_final($sha1Context),
        ];
    }

    /**
     * Resolve a catalog identity row to controlled physical storage without
     * re-hashing it. Public batch preflight uses this only after an indexed
     * MD5/SHA-1/size match so stale database identities do not suppress a useful
     * contribution.
     *
     * @param array<string,mixed> $row
     */
    public function locatePhysicalPath(array $row): ?string
    {
        if ((string)($row['scan_status'] ?? '') === 'unverified'
            && (int)($row['unverified_queue_game_id'] ?? -1) === 0) {
            $queueName = basename((string)($row['unverified_queue_name'] ?? ''));
            if ($queueName === '') {
                return null;
            }
            $bucketRoot = CatalogUnverifiedQueueStorage::uploadBucketDirectory($this->config, false);
            $candidate = $bucketRoot . DIRECTORY_SEPARATOR . $queueName;
            return is_file($candidate)
                && CatalogUnverifiedQueueStorage::pathInside($candidate, $bucketRoot)
                    ? (realpath($candidate) ?: $candidate)
                    : null;
        }

        $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            return null;
        }

        $rawPath = trim((string)($row['relative_path'] ?? ''));
        $relative = '';
        if ($rawPath !== '' && !str_contains($rawPath, "\0")) {
            $relative = ltrim(str_replace('\\', '/', $rawPath), '/');
            if (str_contains($relative, '../')) {
                $relative = '';
            }
        }

        $storedName = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim((string)($row['stored_name'] ?? ''))));
        if ($storedName === '' || $storedName === '.' || $storedName === '..') {
            $storedName = '';
        }
        if ($storedName === '') {
            $rowMd5 = strtolower(trim((string)($row['md5'] ?? '')));
            $extension = strtolower(trim((string)pathinfo((string)($row['original_name'] ?? ''), PATHINFO_EXTENSION)));
            if (preg_match('/^[a-f0-9]{32}$/', $rowMd5) === 1) {
                $storedName = $extension !== '' ? $rowMd5 . '.' . $extension : $rowMd5;
            }
        }

        $gameSlug = trim(str_replace(["\0", '/', '\\'], '', (string)($row['game_slug'] ?? '')));
        $catalogRoot = realpath(dirname(__DIR__, 3));
        $candidates = [];

        if ($rawPath !== '' && $this->isAbsolutePath($rawPath)) {
            $candidates[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawPath);
        }
        if ($relative !== '') {
            if ($catalogRoot !== false) {
                $candidates[] = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            }
            if (str_starts_with(strtolower($relative), 'storage/')) {
                $candidates[] = $storageRoot . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('storage/')));
            }
            $candidates[] = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }

        // Canonical verified storage is content-addressed by stored_name beneath
        // storage/games/<slug>/verified. Older rows may have a stale or missing
        // relative_path while stored_name + game still identify the physical file.
        if ($storedName !== '' && $gameSlug !== '') {
            $candidates[] = $storageRoot . DIRECTORY_SEPARATOR . 'games'
                . DIRECTORY_SEPARATOR . $gameSlug
                . DIRECTORY_SEPARATOR . 'verified'
                . DIRECTORY_SEPARATOR . $storedName;
        }
        // Retain the historical flat-storage fallback used by package downloads.
        if ($storedName !== '') {
            $candidates[] = $storageRoot . DIRECTORY_SEPARATOR . $storedName;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate) || is_link($candidate)) {
                continue;
            }
            $resolved = realpath($candidate);
            if ($resolved === false) {
                continue;
            }
            $normalized = str_replace('\\', '/', $resolved);
            $insideStorage = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($normalized), strtolower($rootPrefix))
                : str_starts_with($normalized, $rootPrefix);
            if ($insideStorage) {
                return $resolved;
            }
        }
        return null;
    }

    public function confirmPhysicalIdentity(string $path, int $fileSize, string $md5, string $sha1): bool
    {
        if (!is_file($path) || is_link($path) || $fileSize < 1) {
            return false;
        }
        $physicalSize = filesize($path);
        if ($physicalSize === false || (int)$physicalSize !== $fileSize) {
            return false;
        }
        $identity = $this->physicalIdentity($path);
        if ($identity === null) {
            return false;
        }
        return hash_equals(strtolower($md5), $identity['md5'])
            && hash_equals(strtolower($sha1), $identity['sha1']);
    }

    private function isAbsolutePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));
        return str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || str_starts_with($normalized, '//');
    }
}
