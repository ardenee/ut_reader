#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CompactFileMaintenanceSnapshot;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return int */
function compact_maintenance_requested_file_id(array $arguments): int
{
    foreach ($arguments as $argument) {
        if (str_starts_with((string)$argument, '--file-id=')) {
            return max(0, (int)substr((string)$argument, strlen('--file-id=')));
        }
    }
    return 0;
}

/** @param mixed $value */
function compact_maintenance_digest(mixed $value): string
{
    return hash('sha256', json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    ));
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = compact_maintenance_requested_file_id(array_slice($argv, 1));

    if ($fileId < 1) {
        $statement = $db->query(
            'SELECT f.id FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" '
            . 'ORDER BY (f.name_count+f.import_count+f.export_count),f.id LIMIT 1'
        );
        $fileId = (int)($statement->fetchColumn() ?: 0);
    }
    if ($fileId < 1) {
        throw new RuntimeException('No format-2 verified file was found.');
    }

    $service = new CompactFileMaintenanceSnapshot(
        $db,
        trim((string)($config['storage_path'] ?? ''))
    );
    $snapshot = $service->capture($fileId);
    $file = (array)$snapshot['file'];
    $metadata = (array)$snapshot['metadata'];
    $registration = (array)$snapshot['registration'];

    $counts = [
        'names' => count((array)$metadata['names']),
        'imports' => count((array)$metadata['imports']),
        'exports' => count((array)$metadata['exports']),
        'dependencies' => count((array)$metadata['dependencies']),
    ];
    if ($counts['names'] !== (int)$file['name_count']) {
        throw new RuntimeException('Captured Name count mismatch.');
    }
    if ($counts['imports'] !== (int)$file['import_count']) {
        throw new RuntimeException('Captured Import count mismatch.');
    }
    if ($counts['exports'] !== (int)$file['export_count']) {
        throw new RuntimeException('Captured Export count mismatch.');
    }
    if ($counts['dependencies'] !== (int)$file['import_count']) {
        throw new RuntimeException('Captured dependency count mismatch.');
    }
    if ((int)$registration['format_version'] !== 2) {
        throw new RuntimeException('Captured registration is not format version 2.');
    }

    $output = [
        'verified' => true,
        'file_id' => $fileId,
        'game_id' => (int)$file['game_id'],
        'package_name' => (string)$file['package_name'],
        'original_name' => (string)$file['original_name'],
        'format_version' => (int)$registration['format_version'],
        'counts' => $counts,
        'locations' => count((array)$snapshot['locations']),
        'aliases' => count((array)$snapshot['aliases']),
        'metadata_digest' => compact_maintenance_digest([
            'names' => $metadata['names'],
            'imports' => $metadata['imports'],
            'exports' => $metadata['exports'],
            'dependencies' => $metadata['dependencies'],
            'paths' => $metadata['paths'],
        ]),
        'legacy_names_read' => false,
        'legacy_imports_read' => false,
        'legacy_exports_read' => false,
        'legacy_dependencies_read' => false,
        'compact_maintenance_capture_ready' => true,
        'database_changed' => false,
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact maintenance snapshot verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
