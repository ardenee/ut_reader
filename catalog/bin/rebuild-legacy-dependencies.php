#!/usr/bin/env php
<?php
/**
 * Re-resolves UE1/UE2 dependency sections after VerifyImport projections are fully populated.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

$options = getopt('', [
    'apply',
    'game-id::',
    'engine::',
    'after-id::',
    'limit::',
]);

$apply = array_key_exists('apply', $options);
$gameId = isset($options['game-id']) ? max(0, (int)$options['game-id']) : 0;
$engine = strtoupper(trim((string)($options['engine'] ?? 'UE2')));
$afterId = isset($options['after-id']) ? max(0, (int)$options['after-id']) : 0;
$limit = isset($options['limit']) ? max(1, min(1000000, (int)$options['limit'])) : 1000;

if (!in_array($engine, ['UE1', 'UE2'], true)) {
    throw new InvalidArgumentException('--engine must be UE1 or UE2.');
}

$config = catalog_config();
$db = catalog_db($config);

$sql = 'SELECT f.id,f.game_id,f.package_name'
    . ' FROM ue_files f'
    . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=' . \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer::FORMAT_VERSION . ''
    . ' JOIN ue_games g ON g.id=f.game_id'
    . ' JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
    . ' WHERE f.scan_status="verified"'
    . ' AND UPPER(TRIM(p.engine_key))=?'
    . ' AND f.id>?';
$args = [$engine, $afterId];
if ($gameId > 0) {
    $sql .= ' AND f.game_id=?';
    $args[] = $gameId;
}
$sql .= ' ORDER BY f.id LIMIT ' . $limit;

$statement = $db->prepare($sql);
$statement->execute($args);
$files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rebuilder = new PdoCatalogDependencyRebuilder($db, $config);
$changedFiles = 0;
$failed = 0;
$lastId = $afterId;
$results = [];
$affectedGameIds = [];

foreach ($files as $position => $file) {
    $fileId = (int)$file['id'];
    $lastId = max($lastId, $fileId);

    if (!$apply) {
        $results[] = [
            'file_id' => $fileId,
            'package_name' => (string)$file['package_name'],
            'status' => 'would_rebuild',
        ];
        continue;
    }

    try {
        $before = $db->prepare(
            'SELECT COUNT(*) FROM ue_dependency_links WHERE file_id=?'
        );
        $before->execute([$fileId]);
        $beforeCount = (int)$before->fetchColumn();

        // game-missing.php reads ue_dependency_package_summaries for its totals
        // and aggregate lists. Keep that projection synchronized with the
        // authoritative links changed by this maintenance rebuild.
        $rebuilder->rebuild(
            $fileId,
            null,
            0,
            100,
            'Rebuilding indexed VerifyImport dependencies',
            true
        );

        $affectedGameIds[(int)$file['game_id']] = true;
        $changedFiles++;
        $results[] = [
            'file_id' => $fileId,
            'package_name' => (string)$file['package_name'],
            'status' => 'rebuilt',
            'dependency_rows' => $beforeCount,
        ];
    } catch (Throwable $error) {
        $failed++;
        $results[] = [
            'file_id' => $fileId,
            'package_name' => (string)$file['package_name'],
            'status' => 'failed',
            'error' => get_class($error) . ': ' . $error->getMessage(),
        ];
    }

    if ((($position + 1) % 25) === 0) {
        fwrite(
            STDERR,
            'Processed ' . ($position + 1) . '/' . count($files)
            . ' | rebuilt=' . $changedFiles
            . ' | failed=' . $failed
            . ' | last_id=' . $lastId . PHP_EOL
        );
    }
}

$statsRebuilt = 0;
$statsFailed = 0;
if ($apply && $affectedGameIds !== []) {
    $stats = new PdoGameCatalogStats($db);
    foreach (array_keys($affectedGameIds) as $affectedGameId) {
        try {
            if ($stats->rebuildGame((int)$affectedGameId, 15) !== null) {
                $statsRebuilt++;
            } else {
                $statsFailed++;
            }
        } catch (Throwable $error) {
            $statsFailed++;
            $results[] = [
                'game_id' => (int)$affectedGameId,
                'status' => 'stats_failed',
                'error' => get_class($error) . ': ' . $error->getMessage(),
            ];
        }
    }
}

echo json_encode([
    'ok' => $failed === 0 && $statsFailed === 0,
    'apply' => $apply,
    'engine' => $engine,
    'game_id' => $gameId,
    'selected' => count($files),
    'rebuilt' => $changedFiles,
    'failed' => $failed,
    'game_stats_rebuilt' => $statsRebuilt,
    'game_stats_failed' => $statsFailed,
    'after_id' => $afterId,
    'last_id' => $lastId,
    'limit' => $limit,
    'results' => array_slice($results, 0, 100),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === 0 && $statsFailed === 0 ? 0 : 2);
