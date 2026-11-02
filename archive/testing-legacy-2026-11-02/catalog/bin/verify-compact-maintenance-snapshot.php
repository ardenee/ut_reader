#!/usr/bin/env php
<?php
/** Read-only, file-scoped UEDB5 maintenance capture verification. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CompactFileMaintenanceSnapshot;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

$fileId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--file-id=([1-9][0-9]*)$/', $arg, $match)) {
        $fileId = (int)$match[1];
    } else {
        fwrite(STDERR, "Usage: php verify-compact-maintenance-snapshot.php --file-id=ID\n");
        exit(1);
    }
}
if ($fileId < 1) {
    fwrite(STDERR, "A file ID is required; no catalogue-wide selection is performed.\n");
    exit(1);
}
try {
    $config = catalog_config();
    $db = catalog_db($config);
    $snapshot = (new CompactFileMaintenanceSnapshot(
        $db, (string)($config['storage_path'] ?? '')
    ))->capture($fileId);
    $file = (array)$snapshot['file'];
    $metadata = (array)$snapshot['metadata'];
    $registration = (array)$snapshot['registration'];
    if ((int)($registration['format_version'] ?? 0) !== Uedb5MetadataContainer::FORMAT_VERSION) {
        throw new RuntimeException('Maintenance capture has no format-5 registration.');
    }
    $sections = (array)($metadata['sections'] ?? []);
    $counts = [];
    foreach ($sections as $section => $rows) {
        $counts[(string)$section] = count((array)$rows);
    }
    $registeredCounts = json_decode((string)$registration['section_counts_json'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($registeredCounts as $section => $count) {
        if (($counts[$section] ?? -1) !== (int)$count) {
            throw new RuntimeException('UEDB5 section count mismatch: ' . $section);
        }
    }
    foreach (['names' => 'name_count', 'imports' => 'import_count', 'exports' => 'export_count'] as $section => $column) {
        if (($counts[$section] ?? 0) !== (int)$file[$column]) {
            throw new RuntimeException('Catalogue section count mismatch: ' . $section);
        }
    }
    $digest = hash('sha256', json_encode(
        ['sections' => $sections, 'section_schemas' => (array)($metadata['section_schemas'] ?? [])],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    ));
    echo json_encode([
        'verified' => true,
        'read_only' => true,
        'file_id' => $fileId,
        'game_id' => (int)$file['game_id'],
        'format_version' => (int)$registration['format_version'],
        'counts' => $counts,
        'locations' => count((array)$snapshot['locations']),
        'aliases' => count((array)$snapshot['aliases']),
        'metadata_digest' => $digest,
        'database_changed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'UEDB5 maintenance snapshot verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
