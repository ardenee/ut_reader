#!/usr/bin/env php
<?php
/**
 * Re-resolve current compact dependency metadata for every verified file in one game.
 * Designed for source-semantics cutovers where existing dependency rows need to be
 * rewritten without reparsing the Unreal package itself.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactDependencyRebuilder;

$options = getopt('', ['game-id:', 'start-id::', 'limit::', 'progress-every::', 'all::']);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$startId = max(0, (int)($options['start-id'] ?? 0));
$limit = max(0, (int)($options['limit'] ?? 0));
$progressEvery = max(1, (int)($options['progress-every'] ?? 250));
$onlyMissing = !array_key_exists('all', $options);
if ($gameId < 1) {
    throw new InvalidArgumentException(
        'Usage: php catalog/bin/rebuild-game-dependencies.php --game-id=ID [--start-id=N] [--limit=N] [--progress-every=N] [--all]'
    );
}

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') {
    throw new RuntimeException('catalog storage_path is required.');
}

$game = catalog_one(
    $db,
    'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name FROM ue_games g '
    . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? LIMIT 1',
    [$gameId]
);
if (!$game) {
    throw new RuntimeException('Game not found: ' . $gameId);
}

$rebuilder = new CompactDependencyRebuilder($db, $storageRoot);
$cursor = $startId;
$processed = 0;
$changedFiles = 0;
$changedDependencies = 0;
$rewritten = 0;
$unchanged = 0;
$failed = 0;
$failures = [];
$batchSize = 250;
$started = microtime(true);

fwrite(STDERR, sprintf(
    "Rebuilding dependencies | game=%d %s | engine=%s | start_id=%d | scope=%s\n",
    $gameId,
    (string)$game['name'],
    (string)($game['engine_key'] ?? ''),
    $startId,
    $onlyMissing ? 'files-with-current-missing-rows' : 'all-current-files'
));

while (true) {
    $remaining = $limit > 0 ? $limit - $processed : $batchSize;
    if ($limit > 0 && $remaining <= 0) {
        break;
    }
    $take = min($batchSize, $limit > 0 ? $remaining : $batchSize);

    $sql = 'SELECT f.id FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.id>? ';
    if ($onlyMissing) {
        $sql .= 'AND EXISTS (SELECT 1 FROM ue_dependency_links l WHERE l.file_id=f.id AND l.status=0) ';
    }
    $sql .= 'ORDER BY f.id LIMIT ' . (int)$take;
    $statement = $db->prepare($sql);
    $statement->execute([BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId, $cursor]);
    $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids === []) {
        break;
    }

    foreach ($ids as $fileId) {
        $cursor = max($cursor, $fileId);
        try {
            $result = $rebuilder->rebuild($fileId);
            $changed = max(0, (int)($result['dependencies_changed'] ?? 0));
            $didRewrite = (bool)($result['container_rewritten'] ?? false);
            if ($changed > 0) {
                $changedFiles++;
                $changedDependencies += $changed;
            } else {
                $unchanged++;
            }
            if ($didRewrite) {
                $rewritten++;
            }
        } catch (Throwable $error) {
            $failed++;
            if (count($failures) < 50) {
                $failures[] = [
                    'file_id' => $fileId,
                    'error' => get_class($error) . ': ' . $error->getMessage(),
                ];
            }
        }
        $processed++;

        if (($processed % $progressEvery) === 0) {
            $elapsed = max(0.001, microtime(true) - $started);
            fwrite(STDERR, sprintf(
                "processed=%d changed_files=%d changed_dependencies=%d rewritten=%d unchanged=%d failed=%d last_id=%d rate=%.1f files/s\n",
                $processed,
                $changedFiles,
                $changedDependencies,
                $rewritten,
                $unchanged,
                $failed,
                $cursor,
                $processed / $elapsed
            ));
        }

        if ($limit > 0 && $processed >= $limit) {
            break 2;
        }
    }
}

$elapsed = microtime(true) - $started;
echo json_encode([
    'ok' => $failed === 0,
    'game' => $game,
    'metadata_format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
    'scope' => $onlyMissing ? 'files_with_current_missing_rows' : 'all_current_files',
    'start_id' => $startId,
    'last_id' => $cursor,
    'processed' => $processed,
    'changed_files' => $changedFiles,
    'changed_dependencies' => $changedDependencies,
    'containers_rewritten' => $rewritten,
    'unchanged_files' => $unchanged,
    'failed' => $failed,
    'elapsed_seconds' => round($elapsed, 3),
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === 0 ? 0 : 2);
