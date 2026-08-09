<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rewrites package identity and Export paths in current metadata.
 * Why: Compact snapshot mutation/publication is a metadata persistence concern; unverified staging rebases paths at read time.
 * Role: Infrastructure metadata mutation service for format-2 containers and compressed unverified staging.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataStore;

final class CatalogCompactMetadataMutationService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

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

        if ($formatVersion < BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            $status = $this->db->prepare('SELECT scan_status FROM ue_files WHERE id=?');
            $status->execute([$fileId]);
            $scanStatus = strtolower(trim((string)($status->fetchColumn() ?: '')));
            if ($scanStatus === 'unverified' && (new CatalogUnverifiedMetadataStore($this->db))->has($fileId)) {
                // The staging payload stores local Export paths. Its reader derives
                // full paths from the current ue_files.package_name, so no large
                // payload rewrite is required when only package identity changes.
                return 0;
            }
            throw new RuntimeException('File #' . $fileId . ' has no current metadata snapshot to rewrite.');
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
            if ((string)($export['full_path'] ?? '') !== $fullPath) $changed++;
            $export['full_path'] = $fullPath;
            if (!isset($exportPaths[$index]) || !is_array($exportPaths[$index])) $exportPaths[$index] = [];
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
        if ($localPath === '') return $packageName;
        return rtrim($packageName, '.') . '.' . ltrim($localPath, '.');
    }
}
