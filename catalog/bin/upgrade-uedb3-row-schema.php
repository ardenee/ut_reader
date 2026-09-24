#!/usr/bin/env php
<?php
/**
 * Upgrades existing .uedb3 containers to row schema 2 without reparsing Unreal packages.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;

$options = getopt('', [
    'apply',
    'game-id::',
    'engine::',
    'after-id::',
    'limit::',
]);

$apply = array_key_exists('apply', $options);
$gameId = isset($options['game-id']) ? max(0, (int)$options['game-id']) : 0;
$engineFilter = strtoupper(trim((string)($options['engine'] ?? '')));
$afterId = isset($options['after-id']) ? max(0, (int)$options['after-id']) : 0;
$limit = isset($options['limit']) ? max(1, min(1000000, (int)$options['limit'])) : 1000;

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') {
    throw new RuntimeException('catalog.storage_path is required.');
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
if ($engineFilter !== '') {
    $sql .= ' AND UPPER(TRIM(COALESCE(p.engine_key,"")))=?';
    $args[] = $engineFilter;
}
$sql .= ' ORDER BY f.id LIMIT ' . $limit;

$statement = $db->prepare($sql);
$statement->execute($args);
$files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$loader = new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot);
$writer = new BlockedCompressedMetadataSnapshotWriter($db, $storageRoot);

$selected = count($files);
$upgraded = 0;
$wouldUpgrade = 0;
$failed = 0;
$lastId = $afterId;
$results = [];

foreach ($files as $position => $file) {
    $fileId = (int)$file['id'];
    $lastId = max($lastId, $fileId);
    $engineKey = strtoupper(trim((string)$file['engine_key']));

    try {
        $snapshot = $loader->load($fileId);
        $currentSchema = (int)($snapshot['identity_schema']['row_schema_version'] ?? 1);
        $needsUpgrade = $currentSchema < 2;

        if (!$needsUpgrade) {
            $results[] = [
                'file_id' => $fileId,
                'status' => 'already_schema_2',
            ];
            continue;
        }

        $wouldUpgrade++;
        if (!$apply) {
            $results[] = [
                'file_id' => $fileId,
                'status' => 'would_upgrade',
                'engine' => $engineKey,
            ];
            continue;
        }

        $snapshot = CatalogCompactIdentityEnricher::enrich($snapshot, $engineKey);
        $write = $writer->write($snapshot);
        $upgraded++;
        $results[] = [
            'file_id' => $fileId,
            'status' => 'upgraded',
            'engine' => $engineKey,
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

    if ($apply && (($position + 1) % 25) === 0) {
        fwrite(
            STDERR,
            'Processed ' . ($position + 1) . '/' . $selected
            . ' | upgraded=' . $upgraded
            . ' | failed=' . $failed
            . ' | last_id=' . $lastId . PHP_EOL
        );
    }
}

echo json_encode([
    'ok' => $failed === 0,
    'apply' => $apply,
    'selected' => $selected,
    'would_upgrade' => $wouldUpgrade,
    'upgraded' => $upgraded,
    'failed' => $failed,
    'after_id' => $afterId,
    'last_id' => $lastId,
    'limit' => $limit,
    'game_id' => $gameId,
    'engine' => $engineFilter !== '' ? $engineFilter : null,
    'results' => array_slice($results, 0, 100),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === 0 ? 0 : 2);
