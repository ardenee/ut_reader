<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotWriter;

/**
 * Rewrite a file's package identity and every Export full path in format-2
 * metadata. Unverified/unconverted rows retain the legacy staging update.
 */
function catalog_compact_metadata_rewrite_package_identity(
    PDO $db,
    array $config,
    int $fileId,
    string $packageName
): int {
    $packageName = trim($packageName);
    if ($fileId < 1 || $packageName === '') {
        throw new InvalidArgumentException('A valid file ID and package name are required.');
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Compact package identity rewriting must run outside an existing transaction.');
    }

    $registration = $db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
    $registration->execute([$fileId]);
    $formatVersion = (int)($registration->fetchColumn() ?: 0);

    if ($formatVersion < 2) {
        $statement = $db->prepare(
            'UPDATE ue_exports SET full_path=CASE WHEN local_path<>"" '
            . 'THEN CONCAT(?, ".", local_path) ELSE ? END WHERE file_id=?'
        );
        $statement->execute([$packageName, $packageName, $fileId]);
        return $statement->rowCount();
    }

    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('Catalog storage_path is required for compact metadata rewriting.');
    }

    $fileStatement = $db->prepare('SELECT * FROM ue_files WHERE id=?');
    $fileStatement->execute([$fileId]);
    $currentFile = $fileStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($currentFile)) {
        throw new RuntimeException('The catalog file disappeared before compact identity rewriting.');
    }

    $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($fileId);
    $snapshot['file'] = $currentFile;
    $snapshot['file']['package_name'] = $packageName;
    $exports = array_values((array)$snapshot['exports']);
    $paths = (array)($snapshot['paths'] ?? []);
    $exportPaths = array_values((array)($paths['exports'] ?? []));
    $changed = 0;

    foreach ($exports as $index => &$export) {
        $localPath = (string)($export['local_path'] ?? '');
        $fullPath = catalog_compact_metadata_join_package_path($packageName, $localPath);
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

    (new BlockedCompressedMetadataSnapshotWriter($db, $storageRoot))->write($snapshot);
    return $changed;
}

function catalog_compact_metadata_join_package_path(string $packageName, string $localPath): string
{
    $packageName = trim($packageName);
    $localPath = trim($localPath);
    if ($localPath === '') {
        return $packageName;
    }
    return rtrim($packageName, '.') . '.' . ltrim($localPath, '.');
}
