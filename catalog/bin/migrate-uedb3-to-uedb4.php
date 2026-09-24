#!/usr/bin/env php
<?php
/**
 * Offline .uedb3 -> .uedb4 migration.
 *
 * Production runtime is format-4 only. This command is the only supported
 * bridge from the immediately previous format. Future v5+ cutovers should
 * copy this pattern into a new version-specific migration directory.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';
require_once __DIR__ . '/v4-migration/MetadataReaderV3.php';
require_once __DIR__ . '/v4-migration/SnapshotLoaderV3.php';

use UnrealDb\Catalog\MigrationV4\MetadataReaderV3;
use UnrealDb\Catalog\MigrationV4\SnapshotLoaderV3;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;

$options = getopt('', [
    'apply',
    'game-id::',
    'engine::',
    'after-id::',
    'limit::',
    'keep-v3',
    'storage-root::',
]);

$apply = array_key_exists('apply', $options);
$keepV3 = array_key_exists('keep-v3', $options);
$gameId = isset($options['game-id']) ? max(0, (int)$options['game-id']) : 0;
$engine = strtoupper(trim((string)($options['engine'] ?? '')));
$afterId = isset($options['after-id']) ? max(0, (int)$options['after-id']) : 0;
$limit = isset($options['limit']) ? max(1, min(1000000, (int)$options['limit'])) : 1000;

$config = catalog_config();
$db = catalog_db($config);
$configuredStorageRoot = trim((string)($config['storage_path'] ?? ''));
$storageRoot = trim((string)($options['storage-root'] ?? $configuredStorageRoot));
$storageRoot = rtrim($storageRoot, "\\/");
if ($storageRoot === '') {
    throw new RuntimeException('Metadata storage root is required via catalog.storage_path or --storage-root.');
}

$sql = 'SELECT f.id,f.game_id,f.package_name,UPPER(TRIM(COALESCE(p.engine_key,""))) engine_key'
    . ' FROM ue_files f'
    . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
    . ' JOIN ue_games g ON g.id=f.game_id'
    . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
    . ' WHERE f.scan_status="verified" AND f.id>?';
$args = [$afterId];

if ($gameId > 0) {
    $sql .= ' AND f.game_id=?';
    $args[] = $gameId;
}
if ($engine !== '') {
    $sql .= ' AND UPPER(TRIM(COALESCE(p.engine_key,"")))=?';
    $args[] = $engine;
}
$sql .= ' ORDER BY f.id LIMIT ' . $limit;

$statement = $db->prepare($sql);
$statement->execute($args);
$files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$loader = new SnapshotLoaderV3($db, $storageRoot);
$writer = new BlockedCompressedMetadataSnapshotWriter($db, $storageRoot);

$converted = 0;
$failed = 0;
$lastId = $afterId;
$results = [];

foreach ($files as $position => $file) {
    $fileId = (int)$file['id'];
    $rowGameId = (int)$file['game_id'];
    $engineKey = strtoupper(trim((string)$file['engine_key']));
    $lastId = max($lastId, $fileId);

    $v3Path = MetadataReaderV3::path($storageRoot, $rowGameId, $fileId);
    $v4Path = BlockedCompressedMetadataContainer::path($storageRoot, $rowGameId, $fileId);

    if (!$apply) {
        $results[] = [
            'file_id' => $fileId,
            'status' => 'would_convert',
            'engine' => $engineKey,
            'v3_path' => $v3Path,
            'v4_path' => $v4Path,
        ];
        continue;
    }

    try {
        if (!is_file($v3Path)) {
            throw new RuntimeException('Registered format-3 metadata file is missing: ' . $v3Path);
        }

        $snapshot = $loader->load($fileId);
        $snapshot = CatalogCompactIdentityEnricher::enrich($snapshot, $engineKey);

        $write = $writer->write($snapshot);
        if ((int)($write['format_version'] ?? 0) !== 4 || !is_file($v4Path)) {
            throw new RuntimeException('Format-4 publication did not complete for file #' . $fileId . '.');
        }

        $v3Deleted = false;
        $cleanupPending = false;
        if (!$keepV3) {
            clearstatcache(true, $v3Path);
            if (!is_file($v3Path)) {
                $v3Deleted = true;
            } elseif (@unlink($v3Path)) {
                $v3Deleted = true;
            } else {
                $cleanupPending = true;
            }
        }

        $converted++;
        $results[] = [
            'file_id' => $fileId,
            'status' => $cleanupPending ? 'converted_cleanup_pending' : 'converted',
            'engine' => $engineKey,
            'v3_deleted' => $v3Deleted,
            'cleanup_pending' => $cleanupPending,
            'compressed_size' => (int)($write['compressed_size'] ?? 0),
            'sql_batches' => (int)($write['sql_batches'] ?? 0),
        ];
    } catch (Throwable $error) {
        $failed++;
        $results[] = [
            'file_id' => $fileId,
            'status' => 'failed',
            'error' => get_class($error) . ': ' . $error->getMessage(),
        ];
    }

    if ((($position + 1) % 25) === 0) {
        fwrite(
            STDERR,
            'Processed ' . ($position + 1) . '/' . count($files)
            . ' | converted=' . $converted
            . ' | failed=' . $failed
            . ' | last_id=' . $lastId . PHP_EOL
        );
    }
}

echo json_encode([
    'ok' => $failed === 0,
    'apply' => $apply,
    'storage_root' => $storageRoot,
    'keep_v3' => $keepV3,
    'selected' => count($files),
    'converted' => $converted,
    'failed' => $failed,
    'after_id' => $afterId,
    'last_id' => $lastId,
    'limit' => $limit,
    'game_id' => $gameId > 0 ? $gameId : null,
    'engine' => $engine !== '' ? $engine : null,
    'results' => array_slice($results, 0, 100),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === 0 ? 0 : 2);
