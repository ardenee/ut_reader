<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rewrites package identity and Export paths in compact format-2 metadata.
 * Why: Compact snapshot mutation/publication is a metadata persistence concern and should not live in a procedural
 *      catalog/lib helper.
 * Role: Infrastructure metadata mutation service preserving legacy fallback and blocked-container publication.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class CatalogCompactMetadataMutationService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * Rewrite a file's package identity and every Export full path in format-2
     * metadata. Unverified/unconverted rows retain the legacy staging update.
     */
    public function rewritePackageIdentity(int $fileId, string $packageName): int
    {
        $packageName = trim($packageName);
        if ($fileId < 1 || $packageName === '') {
            throw new InvalidArgumentException('A valid file ID and package name are required.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact package identity rewriting must run outside an existing transaction.');
        }

        $registration = $this->db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
        $registration->execute([$fileId]);
        $formatVersion = (int)($registration->fetchColumn() ?: 0);

        if ($formatVersion < 2) {
            $statement = $this->db->prepare(
                'UPDATE ue_exports SET full_path=CASE WHEN local_path<>"" '
                . 'THEN CONCAT(?, ".", local_path) ELSE ? END WHERE file_id=?'
            );
            $statement->execute([$packageName, $packageName, $fileId]);
            return $statement->rowCount();
        }

        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata rewriting.');
        }

        $fileStatement = $this->db->prepare('SELECT * FROM ue_files WHERE id=?');
        $fileStatement->execute([$fileId]);
        $currentFile = $fileStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($currentFile)) {
            throw new RuntimeException('The catalog file disappeared before compact identity rewriting.');
        }

        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($this->db, $storageRoot))->load($fileId);
        $snapshot['file'] = $currentFile;
        $snapshot['file']['package_name'] = $packageName;
        $exports = array_values((array)$snapshot['exports']);
        $paths = (array)($snapshot['paths'] ?? []);
        $exportPaths = array_values((array)($paths['exports'] ?? []));
        $changed = 0;

        foreach ($exports as $index => &$export) {
            $localPath = (string)($export['local_path'] ?? '');
            $fullPath = self::joinPackagePath($packageName, $localPath);
            if ((string)($export['full_path'] ?? '') !== $fullPath) {
                $changed++;
            }
            $export['full_path'] = $fullPath;
            if (!isset($exportPaths[$index]) || !is_array($exportPaths[$index])) {
                $exportPaths[$index] = [];
            }
            $exportPaths[$index]['local'] = $localPath;
            $exportPaths[$index]['full'] = $fullPath;
        }
        unset($export);

        $snapshot['exports'] = $exports;
        $paths['exports'] = $exportPaths;
        $snapshot['paths'] = $paths;

        (new BlockedCompressedMetadataSnapshotWriter($this->db, $storageRoot))->write($snapshot);
        return $changed;
    }

    public static function joinPackagePath(string $packageName, string $localPath): string
    {
        $packageName = trim($packageName);
        $localPath = trim($localPath);
        if ($localPath === '') {
            return $packageName;
        }
        return rtrim($packageName, '.') . '.' . ltrim($localPath, '.');
    }
}
