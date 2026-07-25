<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;

/**
 * Finds duplicate package identities only when UnrealDB can prove that the
 * matching bytes still exist in controlled storage. Database-only identities,
 * including official base-game records whose source file is absent, are not
 * treated as upload duplicates.
 *
 * Older code required LOWER(f.sha1)=? in the initial SQL query. The current
 * implementation deliberately defers SHA-1 comparison until physical existence
 * is confirmed, allowing MD5-only legacy/base-game rows to be verified safely.
 */
final class CatalogUploadDuplicateDetector
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/BaseGameProtection.php';
    }

    /**
     * @return array{
     *   duplicate:?array<string,mixed>,
     *   identity_matches:int,
     *   missing_physical_matches:int,
     *   missing_base_game_matches:int
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
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.relative_path,f.file_size,f.md5,f.sha1,'
                . 'f.package_guid,f.scan_status,f.unverified_queue_game_id,f.unverified_queue_name,'
                . 'EXISTS(SELECT 1 FROM ue_base_game_files bg '
                . 'WHERE bg.source_file_id=f.id '
                . 'OR (bg.game_id=f.game_id AND bg.package_guid=f.package_guid AND f.package_guid IS NOT NULL AND f.package_guid<>"")) AS is_base_game '
                . 'FROM ue_files f WHERE f.file_size=? AND LOWER(f.md5)=? '
                . 'ORDER BY (f.scan_status="unverified" AND f.unverified_queue_game_id=0) DESC,f.id LIMIT 200',
            [$fileSize, $md5]
        );

        $missing = 0;
        $missingBaseGame = 0;
        foreach ($rows as $row) {
            $physicalPath = $this->physicalPath($row);
            if ($physicalPath === null) {
                $missing++;
                if (!empty($row['is_base_game'])) {
                    $missingBaseGame++;
                }
                continue;
            }

            $physicalSize = filesize($physicalPath);
            if ($physicalSize === false || (int)$physicalSize !== $fileSize) {
                $missing++;
                if (!empty($row['is_base_game'])) {
                    $missingBaseGame++;
                }
                continue;
            }

            $storedSha1 = strtolower(trim((string)($row['sha1'] ?? '')));
            if (preg_match('/^[a-f0-9]{40}$/', $storedSha1) !== 1) {
                // Older/base-game identities can contain MD5 without SHA-1. Only
                // complete the comparison when their physical file is present.
                $physicalSha1 = hash_file('sha1', $physicalPath);
                if (!is_string($physicalSha1)) {
                    continue;
                }
                $storedSha1 = strtolower($physicalSha1);
            }
            if (!hash_equals($sha1, $storedSha1)) {
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
                    'md5' => $md5,
                    'sha1' => $sha1,
                ],
                'identity_matches' => count($rows),
                'missing_physical_matches' => $missing,
                'missing_base_game_matches' => $missingBaseGame,
            ];
        }

        return [
            'duplicate' => null,
            'identity_matches' => count($rows),
            'missing_physical_matches' => $missing,
            'missing_base_game_matches' => $missingBaseGame,
        ];
    }

    /** @param array<string,mixed> $row */
    private function physicalPath(array $row): ?string
    {
        if ((string)($row['scan_status'] ?? '') === 'unverified'
            && (int)($row['unverified_queue_game_id'] ?? -1) === 0) {
            $queueName = basename((string)($row['unverified_queue_name'] ?? ''));
            if ($queueName === '') {
                return null;
            }
            $bucketRoot = \uvf_upload_bucket_dir($this->config, false);
            $candidate = $bucketRoot . DIRECTORY_SEPARATOR . $queueName;
            return is_file($candidate) && \uvf_path_inside($candidate, $bucketRoot)
                ? (realpath($candidate) ?: $candidate)
                : null;
        }

        $storageRoot = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            return null;
        }
        $rawPath = trim((string)($row['relative_path'] ?? ''));
        if ($rawPath === '' || str_contains($rawPath, "\0")) {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', $rawPath), '/');
        if ($relative === '' || str_contains($relative, '../')) {
            return null;
        }

        $catalogRoot = realpath(dirname(__DIR__, 3));
        $candidates = [];
        if ($this->isAbsolutePath($rawPath)) {
            $candidates[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawPath);
        }
        if ($catalogRoot !== false) {
            $candidates[] = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }
        if (str_starts_with(strtolower($relative), 'storage/')) {
            $candidates[] = $storageRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('storage/')));
        }
        $candidates[] = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

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
            if (str_starts_with($normalized, $rootPrefix)) {
                return $resolved;
            }
        }
        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));
        return str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || str_starts_with($normalized, '//');
    }
}
