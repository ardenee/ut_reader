#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactFileMaintenanceSnapshot;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return int */
function compact_restore_requested_file_id(array $arguments): int
{
    foreach ($arguments as $argument) {
        if (str_starts_with((string)$argument, '--file-id=')) {
            return max(0, (int)substr((string)$argument, strlen('--file-id=')));
        }
    }
    return 0;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    $sourceFileId = compact_restore_requested_file_id(array_slice($argv, 1));

    if ($sourceFileId < 1) {
        $statement = $db->query(
            'SELECT f.id FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" '
            . 'ORDER BY (f.name_count+f.import_count+f.export_count),f.id LIMIT 1'
        );
        $sourceFileId = (int)($statement->fetchColumn() ?: 0);
    }
    if ($sourceFileId < 1) {
        throw new RuntimeException('No format-2 verified file was found.');
    }

    $service = new CompactFileMaintenanceSnapshot($db, $storageRoot);
    $snapshot = $service->capture($sourceFileId);
    $sourceFile = (array)$snapshot['file'];

    $statement = $db->query('SELECT COALESCE(MAX(id),0)+1000000 FROM ue_files');
    $probeFileId = (int)($statement->fetchColumn() ?: 0);
    if ($probeFileId < 1000000) {
        throw new RuntimeException('Could not allocate a disposable probe file ID.');
    }
    while (true) {
        $check = $db->prepare('SELECT 1 FROM ue_files WHERE id=?');
        $check->execute([$probeFileId]);
        if ($check->fetchColumn() === false) {
            break;
        }
        $probeFileId++;
    }

    $token = bin2hex(random_bytes(10));
    $probeFile = $sourceFile;
    $probeFile['id'] = $probeFileId;
    $probeFile['package_name'] = '__CompactRestoreProbe_' . $probeFileId;
    $probeFile['original_name'] = '__compact_restore_probe_' . $probeFileId . '.u';
    if (array_key_exists('source_relative_path', $probeFile)) {
        $probeFile['source_relative_path'] = '_maintenance-probe/' . $probeFile['original_name'];
    }
    if (array_key_exists('stored_name', $probeFile)) {
        $probeFile['stored_name'] = $token . '.probe';
    }
    if (array_key_exists('relative_path', $probeFile)) {
        $probeFile['relative_path'] = 'storage/maintenance-probe/' . $token . '.probe';
    }
    if (array_key_exists('md5', $probeFile)) {
        $probeFile['md5'] = md5('compact-maintenance-probe:' . $token);
    }
    if (array_key_exists('sha1', $probeFile)) {
        $probeFile['sha1'] = sha1('compact-maintenance-probe:' . $token);
    }
    if (array_key_exists('package_guid', $probeFile)) {
        $probeFile['package_guid'] = '';
    }
    if (array_key_exists('uploaded_by', $probeFile)) {
        $probeFile['uploaded_by'] = null;
    }
    if (array_key_exists('scan_notes', $probeFile)) {
        $probeFile['scan_notes'] = trim((string)$probeFile['scan_notes'] . "\nDisposable compact maintenance restore probe.");
    }

    $metadata = (array)$snapshot['metadata'];
    $metadata['file']['id'] = $probeFileId;
    $metadata['file']['package_name'] = (string)$probeFile['package_name'];
    $metadata['file']['original_name'] = (string)$probeFile['original_name'];
    foreach ((array)$metadata['dependencies'] as $index => $dependency) {
        if (is_array($dependency)) {
            $dependency['file_id'] = $probeFileId;
            $metadata['dependencies'][$index] = $dependency;
        }
    }

    $probeSnapshot = $snapshot;
    $probeSnapshot['file'] = $probeFile;
    $probeSnapshot['metadata'] = $metadata;
    $probeSnapshot['locations'] = [];
    $probeSnapshot['aliases'] = [];

    $probePath = BlockedCompressedMetadataContainer::path(
        $storageRoot,
        (int)$probeFile['game_id'],
        $probeFileId
    );
    $restored = false;
    try {
        $result = $service->restore($probeSnapshot);
        $restored = true;
        $verified = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($probeFileId);

        $legacyCounts = [];
        foreach (['ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies'] as $table) {
            $count = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE file_id=?');
            $count->execute([$probeFileId]);
            $legacyCounts[$table] = (int)($count->fetchColumn() ?: 0);
        }
        if (array_sum($legacyCounts) !== 0) {
            throw new RuntimeException('Disposable restore unexpectedly created legacy metadata rows.');
        }

        $output = [
            'verified' => true,
            'source_file_id' => $sourceFileId,
            'probe_file_id' => $probeFileId,
            'game_id' => (int)$probeFile['game_id'],
            'format_version' => (int)$verified['format_version'],
            'name_count' => (int)$verified['name_count'],
            'import_count' => (int)$verified['import_count'],
            'export_count' => (int)$verified['export_count'],
            'dependency_count' => (int)($result['dependency_count'] ?? 0),
            'legacy_rows_created' => $legacyCounts,
            'compact_restore_proven' => true,
            'probe_cleanup_required' => true,
        ];
    } finally {
        if ($restored) {
            $delete = $db->prepare('DELETE FROM ue_files WHERE id=?');
            $delete->execute([$probeFileId]);
        }
        if (is_file($probePath)) {
            @unlink($probePath);
        }
    }

    $check = $db->prepare('SELECT 1 FROM ue_files WHERE id=?');
    $check->execute([$probeFileId]);
    if ($check->fetchColumn() !== false || is_file($probePath)) {
        throw new RuntimeException('Disposable compact maintenance probe cleanup was incomplete.');
    }
    $output['probe_database_row_deleted'] = true;
    $output['probe_metadata_file_deleted'] = true;
    $output['database_left_unchanged_except_auto_increment'] = true;

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact maintenance restore verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
