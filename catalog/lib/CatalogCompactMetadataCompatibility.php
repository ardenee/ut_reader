<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;

/**
 * Read one file's metadata in the legacy row shape. Format-2 files are loaded
 * from the blocked container; unconverted/unverified files retain SQL fallback.
 *
 * @return array{names:list<array<string,mixed>>,imports:list<array<string,mixed>>,exports:list<array<string,mixed>>,dependencies:list<array<string,mixed>>,source:string}
 */
function catalog_metadata_compat_snapshot(PDO $db, array $config, int $fileId): array
{
    if ($fileId < 1) {
        throw new InvalidArgumentException('A positive file ID is required.');
    }

    $registration = catalog_one(
        $db,
        'SELECT format_version FROM ue_file_metadata WHERE file_id=?',
        [$fileId]
    );
    if ((int)($registration['format_version'] ?? 0) >= 2) {
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata reading.');
        }
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($fileId);

        $names = [];
        foreach ((array)$snapshot['names'] as $row) {
            $index = (int)$row['name_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $names[] = $row;
        }

        $imports = [];
        foreach ((array)$snapshot['imports'] as $row) {
            $index = (int)$row['import_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $imports[] = $row;
        }

        $exports = [];
        foreach ((array)$snapshot['exports'] as $row) {
            $index = (int)$row['export_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $exports[] = $row;
        }

        $dependencies = [];
        foreach ((array)$snapshot['dependencies'] as $row) {
            $index = (int)$row['import_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $row['import_id'] = catalog_metadata_compat_id($fileId, $index);
            $row['resolved_export_id'] = null;
            $dependencies[] = $row;
        }

        return [
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'dependencies' => $dependencies,
            'source' => 'compact',
        ];
    }

    return [
        'names' => catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$fileId]),
        'imports' => catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$fileId]),
        'exports' => catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$fileId]),
        'dependencies' => catalog_all($db, 'SELECT * FROM ue_dependencies WHERE file_id=? ORDER BY id', [$fileId]),
        'source' => 'legacy',
    ];
}

function catalog_metadata_compat_id(int $fileId, int $index): int
{
    return ($fileId * 4294967296) + $index + 1;
}
