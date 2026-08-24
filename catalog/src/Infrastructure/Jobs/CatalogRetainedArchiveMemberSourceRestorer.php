<?php
/**
 * Reconstructs an archive-member job source from its retained parent archive.
 *
 * Archive child staging is intentionally shorter-lived than the parent archive's
 * job-owned prepared source. Older retained jobs can therefore outlive their
 * jobs/incoming member copy. This service restores the exact recorded member into
 * controlled incoming staging so current handlers can retry without re-uploading
 * or weakening source ownership.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

final class CatalogRetainedArchiveMemberSourceRestorer
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{relative_path:string,original_name:string,size:int,sha256:string}
     */
    public function restore(
        ClaimedJob $job,
        CatalogIncomingFileStore $incoming,
        string $originalName,
        string $entryPath
    ): array {
        $source = (new CatalogJobSourceContextResolver($this->db, $this->config))->forClaimedJob($job);
        $archivePath = trim((string)($source['archive_prepared_path'] ?? $source['archive_full_path'] ?? ''));
        $archiveName = trim((string)($source['archive_source_name'] ?? $source['parent_original_name'] ?? ''));
        $recordedEntry = $this->normalizedEntryPath(
            (string)($source['archive_entry_path'] ?? $entryPath)
        );

        if ($archivePath === '' || !is_file($archivePath) || !is_readable($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Retained parent archive source is unavailable for member reconstruction.');
        }
        if ($archiveName === '' || $recordedEntry === '') {
            throw new \RuntimeException('Retained parent archive member identity is incomplete.');
        }

        $extractor = new CatalogArchiveExtractor($this->config);
        $matched = null;
        foreach ($extractor->entries($archivePath, $archiveName) as $candidate) {
            $candidatePath = $this->normalizedEntryPath((string)($candidate['path'] ?? ''));
            if ($candidatePath === '' || !hash_equals($recordedEntry, $candidatePath)) {
                continue;
            }
            $matched = $candidate;
            break;
        }

        if (!is_array($matched)) {
            throw new \RuntimeException(
                'Retained parent archive no longer contains the exact recorded member "' . $recordedEntry . '".'
            );
        }
        if (empty($matched['safe'])) {
            throw new \RuntimeException(
                'Retained parent archive member is unsafe: ' . (string)($matched['reason'] ?? 'invalid path')
            );
        }
        if (!empty($matched['encrypted'])) {
            throw new \RuntimeException('Retained parent archive member is encrypted and cannot be reconstructed.');
        }

        $entryBytes = max(0, (int)($matched['size'] ?? 0));
        if ($entryBytes < 1) {
            throw new \RuntimeException('Retained parent archive member has no extractable data.');
        }

        $temporary = $extractor->extractToTemp($archivePath, $archiveName, $matched, $entryBytes);
        try {
            $staged = $incoming->stageLocalFile($temporary, $originalName);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        if ((int)($staged['size'] ?? 0) !== $entryBytes) {
            $incoming->delete((string)($staged['relative_path'] ?? ''));
            throw new \RuntimeException('Reconstructed archive member byte count does not match its retained archive entry.');
        }
        return $staged;
    }

    private function normalizedEntryPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
