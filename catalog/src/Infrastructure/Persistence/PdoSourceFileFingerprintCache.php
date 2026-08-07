<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `PdoSourceFileFingerprintCache` for PDO source file fingerprint cache.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/**
 * Persists cheap source-file probes and their last fully verified catalogue match.
 *
 * The quick fingerprint samples five 64 KiB windows. It is only used together
 * with source path, size and modification time. New or changed files still pass
 * through the existing full MD5/SHA import verification before a new catalogue
 * identity is accepted.
 */
final class PdoSourceFileFingerprintCache
{
    private ?bool $available = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_source_file_fingerprints"'
        );
        $statement->execute();
        return $this->available = (int)$statement->fetchColumn() === 1;
    }

    /** @return array{file_size:int,modified_at:int,quick_fingerprint:string} */
    public function probe(string $path): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $size < 0 || $mtime === false || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Source file cannot be fingerprinted: ' . $path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Source file could not be opened for fingerprinting: ' . $path);
        }

        $sampleSize = 65536;
        $sizeInt = (int)$size;
        $offsets = [
            0,
            max(0, intdiv($sizeInt, 4) - intdiv($sampleSize, 2)),
            max(0, intdiv($sizeInt, 2) - intdiv($sampleSize, 2)),
            max(0, intdiv($sizeInt * 3, 4) - intdiv($sampleSize, 2)),
            max(0, $sizeInt - $sampleSize),
        ];
        $offsets = array_values(array_unique($offsets));
        sort($offsets, SORT_NUMERIC);

        $context = hash_init('sha256');
        hash_update($context, "ue-source-fingerprint-v1\0" . $sizeInt . "\0");
        try {
            foreach ($offsets as $offset) {
                if (fseek($handle, $offset, SEEK_SET) !== 0) {
                    throw new \RuntimeException('Could not seek while fingerprinting source file: ' . $path);
                }
                $length = min($sampleSize, max(0, $sizeInt - $offset));
                $chunk = $length > 0 ? fread($handle, $length) : '';
                if ($chunk === false) {
                    throw new \RuntimeException('Could not read while fingerprinting source file: ' . $path);
                }
                hash_update($context, $offset . "\0" . strlen($chunk) . "\0" . $chunk);
            }
        } finally {
            fclose($handle);
        }

        return [
            'file_size' => $sizeInt,
            'modified_at' => (int)$mtime,
            'quick_fingerprint' => hash_final($context),
        ];
    }

    /** @return array<string,mixed>|null */
    public function lookup(int $sourceId, string $relativePath, array $probe): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $normalizedPath = $this->normalizeRelativePath($relativePath);
        $statement = $this->db->prepare(
            'SELECT * FROM ue_source_file_fingerprints '
            . 'WHERE source_id=? AND path_hash=? LIMIT 1'
        );
        $statement->execute([$sourceId, $this->pathHash($normalizedPath)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || $this->normalizeRelativePath((string)$row['source_relative_path']) !== $normalizedPath
            || (int)$row['file_size'] !== (int)$probe['file_size']
            || (int)$row['modified_at'] !== (int)$probe['modified_at']
            || !hash_equals((string)$row['quick_fingerprint'], (string)$probe['quick_fingerprint'])) {
            return null;
        }
        return $row;
    }

    /**
     * Resolve a cache row to a currently verified catalogue file. The stored
     * identity must still agree with the authoritative ue_files row.
     *
     * @return array<string,mixed>|null
     */
    public function resolveVerifiedFile(array $cached, int $gameId): ?array
    {
        $matchedFileId = (int)($cached['matched_file_id'] ?? 0);
        if ($matchedFileId > 0) {
            $statement = $this->db->prepare(
                'SELECT id,md5,sha1,package_guid FROM ue_files '
                . 'WHERE id=? AND game_id=? AND scan_status="verified" LIMIT 1'
            );
            $statement->execute([$matchedFileId, $gameId]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($file) && $this->identityAgrees($cached, $file)) {
                $file['_cache_match_method'] = (string)($cached['match_method'] ?: 'md5');
                $file['_cache_exact_match'] = true;
                return $file;
            }
        }

        $md5 = strtolower(trim((string)($cached['content_md5'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) === 1) {
            $statement = $this->db->prepare(
                'SELECT id,md5,sha1,package_guid FROM ue_files '
                . 'WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1'
            );
            $statement->execute([$gameId, $md5]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($file)) {
                $file['_cache_match_method'] = 'md5';
                $file['_cache_exact_match'] = false;
                return $file;
            }
        }

        $guid = trim((string)($cached['package_guid'] ?? ''));
        if ($guid !== '') {
            $statement = $this->db->prepare(
                'SELECT id,md5,sha1,package_guid FROM ue_files '
                . 'WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id LIMIT 2'
            );
            $statement->execute([$gameId, $guid]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) {
                $rows[0]['_cache_match_method'] = 'guid';
                $rows[0]['_cache_exact_match'] = false;
                return $rows[0];
            }
        }

        return null;
    }

    public function touch(int $sourceId, string $relativePath): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $normalizedPath = $this->normalizeRelativePath($relativePath);
        $statement = $this->db->prepare(
            'UPDATE ue_source_file_fingerprints SET last_seen_at=NOW() '
            . 'WHERE source_id=? AND path_hash=?'
        );
        $statement->execute([$sourceId, $this->pathHash($normalizedPath)]);
    }

    /**
     * @param array{file_size:int,modified_at:int,quick_fingerprint:string} $probe
     * @param array<string,mixed>|null $file
     */
    public function remember(
        int $sourceId,
        string $relativePath,
        array $probe,
        string $workName,
        bool $isRedirect,
        ?string $contentMd5,
        ?string $contentSha1,
        ?string $packageGuid,
        ?array $file,
        ?string $matchMethod
    ): void {
        if (!$this->isAvailable()) {
            return;
        }
        if (is_array($file) && ($file['_cache_exact_match'] ?? false) === true) {
            $this->touch($sourceId, $relativePath);
            return;
        }

        $normalizedPath = $this->normalizeRelativePath($relativePath);
        $contentMd5 = $this->nullableHash($contentMd5, 32);
        $contentSha1 = $matchMethod === 'guid' ? null : $this->nullableHash($contentSha1, 40);
        $packageGuid = trim((string)$packageGuid);
        $packageGuid = $packageGuid !== '' ? $packageGuid : null;
        $fileId = is_array($file) && (int)($file['id'] ?? 0) > 0 ? (int)$file['id'] : null;
        $verified = $fileId !== null ? date('Y-m-d H:i:s') : null;

        $statement = $this->db->prepare(
            'INSERT INTO ue_source_file_fingerprints('
            . 'source_id,source_relative_path,path_hash,file_size,modified_at,quick_fingerprint,'
            . 'work_name,is_redirect,content_md5,content_sha1,package_guid,matched_file_id,match_method,last_seen_at,verified_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'source_relative_path=VALUES(source_relative_path),file_size=VALUES(file_size),modified_at=VALUES(modified_at),'
            . 'quick_fingerprint=VALUES(quick_fingerprint),work_name=VALUES(work_name),is_redirect=VALUES(is_redirect),'
            . 'content_md5=VALUES(content_md5),content_sha1=VALUES(content_sha1),package_guid=VALUES(package_guid),'
            . 'matched_file_id=VALUES(matched_file_id),match_method=VALUES(match_method),last_seen_at=NOW(),verified_at=VALUES(verified_at)'
        );
        $statement->execute([
            $sourceId,
            $normalizedPath,
            $this->pathHash($normalizedPath),
            (int)$probe['file_size'],
            (int)$probe['modified_at'],
            (string)$probe['quick_fingerprint'],
            substr(trim($workName), 0, 255),
            $isRedirect ? 1 : 0,
            $contentMd5,
            $contentSha1,
            $packageGuid,
            $fileId,
            $matchMethod !== null && $matchMethod !== '' ? substr($matchMethod, 0, 16) : null,
            $verified,
        ]);
    }

    /** @param array<string,mixed> $cached @param array<string,mixed> $file */
    private function identityAgrees(array $cached, array $file): bool
    {
        $method = (string)($cached['match_method'] ?? '');
        if ($method === 'guid') {
            $cachedGuid = trim((string)($cached['package_guid'] ?? ''));
            return $cachedGuid !== '' && hash_equals($cachedGuid, trim((string)($file['package_guid'] ?? '')));
        }
        $cachedMd5 = strtolower(trim((string)($cached['content_md5'] ?? '')));
        if ($cachedMd5 === '' || !hash_equals($cachedMd5, strtolower((string)$file['md5']))) {
            return false;
        }
        $cachedSha1 = strtolower(trim((string)($cached['content_sha1'] ?? '')));
        return $cachedSha1 === '' || hash_equals($cachedSha1, strtolower((string)$file['sha1']));
    }

    private function nullableHash(?string $value, int $length): ?string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{' . $length . '}$/', $value) === 1 ? $value : null;
    }

    private function pathHash(string $relativePath): string
    {
        return hash('sha256', mb_strtolower($relativePath, 'UTF-8'));
    }

    private function normalizeRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace(["\0", '\\'], ['', '/'], $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
