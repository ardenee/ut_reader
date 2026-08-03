#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../lib/CatalogScanner.php';

/** @return int */
function scanner_compact_verify_file_id(array $arguments): int
{
    foreach ($arguments as $argument) {
        if (str_starts_with((string)$argument, '--file-id=')) {
            return max(0, (int)substr((string)$argument, strlen('--file-id=')));
        }
    }
    return 0;
}

/** @return array{row_count:int,row_digest:string} */
function scanner_compact_verify_legacy_digest(PDO $db, int $fileId): array
{
    $statement = $db->prepare(
        'SELECT id,import_id,required_package,required_object_path,resolved_file_id,resolved_export_id,'
        . 'status,resolution_source,resolution_confidence '
        . 'FROM ue_dependencies WHERE file_id=? ORDER BY id'
    );
    $statement->execute([$fileId]);
    $hash = hash_init('sha256');
    $count = 0;
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        hash_update($hash, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $count++;
    }
    return ['row_count' => $count, 'row_digest' => hash_final($hash)];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('Catalog storage_path is not configured.');
    }

    $requestedFileId = scanner_compact_verify_file_id(array_slice($argv, 1));
    if ($requestedFileId > 0) {
        $statement = $db->prepare(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.import_count,f.export_count '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $statement->execute([$requestedFileId]);
    } else {
        $statement = $db->query(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.import_count,f.export_count '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" AND f.import_count BETWEEN 1 AND 25 '
            . 'ORDER BY f.import_count,f.export_count,f.id LIMIT 1'
        );
    }
    $file = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file)) {
        throw new RuntimeException('No suitable format-2 file was found.');
    }

    $fileId = (int)$file['id'];
    $beforeLegacy = scanner_compact_verify_legacy_digest($db, $fileId);
    $beforeMetadata = $db->prepare('SELECT payload_sha256,updated_at FROM ue_file_metadata WHERE file_id=?');
    $beforeMetadata->execute([$fileId]);
    $beforeRow = $beforeMetadata->fetch(PDO::FETCH_ASSOC);
    if (!is_array($beforeRow)) {
        throw new RuntimeException('Selected file has no compact metadata row.');
    }

    scanner_rebuild_dependencies($db, $config, $fileId);

    $afterLegacy = scanner_compact_verify_legacy_digest($db, $fileId);
    if ($afterLegacy !== $beforeLegacy) {
        throw new RuntimeException('Legacy ue_dependencies rows changed during compact scanner rebuild.');
    }

    $verified = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($fileId);
    $dependencyCount = (int)($db->query(
        'SELECT COUNT(*) FROM ue_dependency_links WHERE file_id=' . $fileId
    )->fetchColumn() ?: 0);
    if ($dependencyCount !== (int)$file['import_count']) {
        throw new RuntimeException(
            'Compact dependency count mismatch after scanner rebuild: expected '
            . (int)$file['import_count'] . ', found ' . $dependencyCount . '.'
        );
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'file_id' => $fileId,
        'game_id' => (int)$file['game_id'],
        'package_name' => (string)$file['package_name'],
        'original_name' => (string)$file['original_name'],
        'imports_processed' => (int)$file['import_count'],
        'compact_dependency_rows' => $dependencyCount,
        'format_version' => (int)($verified['format_version'] ?? 0),
        'legacy_dependency_rows_unchanged' => true,
        'legacy_dependency_digest' => $afterLegacy['row_digest'],
        'scanner_compact_route_proven' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Scanner compact dependency verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
