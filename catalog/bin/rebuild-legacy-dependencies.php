#!/usr/bin/env php
<?php
/**
 * Canonical dependency rebuild command.
 *
 * Re-resolves dependency sections for verified UE1/UE2/UE3/UE4/UE5 catalog
 * files through the single production dependency-rebuild path. Engine-specific
 * behaviour branches below that shared path only where the engine contract
 * requires it.
 *
 * Historical filename retained for compatibility with existing admin commands.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;

$options = getopt('', [
    'apply',
    'game-id::',
    'file-id::',
    'engine::',
    'after-id::',
    'start-id::',
    'limit::',
    'batch-size::',
    'progress-every::',
    'missing-only',
    'all',
]);

$apply = array_key_exists('apply', $options);
if ($apply) {
    fwrite(STDERR, "UEDB4 dependency writes are retired. Use migrate-uedb5-dependencies.php --game-id=<id> --file-id=<id> --apply --force for an explicitly targeted V5 dependency refresh.\n");
    exit(2);
}
$gameId = isset($options['game-id']) ? max(0, (int)$options['game-id']) : 0;
$fileId = isset($options['file-id']) ? max(0, (int)$options['file-id']) : 0;
$engine = strtoupper(trim((string)($options['engine'] ?? '')));
$afterId = isset($options['after-id'])
    ? max(0, (int)$options['after-id'])
    : max(0, (int)($options['start-id'] ?? 0));
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 1000;
$batchSize = max(25, min(1000, (int)($options['batch-size'] ?? 250)));
$progressEvery = max(1, (int)($options['progress-every'] ?? 100));
$missingOnly = array_key_exists('missing-only', $options);
$explicitAll = array_key_exists('all', $options);

if ($missingOnly && $explicitAll) {
    throw new InvalidArgumentException('Choose either --missing-only or --all, not both.');
}

$allowedEngines = ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'];
if ($engine !== '' && !in_array($engine, $allowedEngines, true)) {
    throw new InvalidArgumentException('--engine must be UE1, UE2, UE3, UE4, or UE5.');
}
if ($gameId < 1 && $engine === '' && $fileId < 1) {
    throw new InvalidArgumentException('Specify --file-id, --game-id, --engine, or a compatible combination.');
}
if ($fileId > 0 && ($afterId > 0 || array_key_exists('start-id', $options) || array_key_exists('all', $options)
    || array_key_exists('missing-only', $options))) {
    throw new InvalidArgumentException('--file-id cannot be combined with cursor/all/missing-only selection options.');
}

$config = catalog_config();
$db = catalog_db($config);
$rebuilder = new PdoCatalogDependencyRebuilder($db, $config);

if ($fileId > 0) {
    $sql = 'SELECT f.id,f.game_id,f.package_name,UPPER(TRIM(p.engine_key)) engine_key'
        . ' FROM ue_files f'
        . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=?'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' WHERE f.id=? AND f.scan_status="verified"';
    $args = [BlockedCompressedMetadataContainer::FORMAT_VERSION, $fileId];
    if ($gameId > 0) {
        $sql .= ' AND f.game_id=?';
        $args[] = $gameId;
    }
    if ($engine !== '') {
        $sql .= ' AND UPPER(TRIM(p.engine_key))=?';
        $args[] = $engine;
    }
    $sql .= ' LIMIT 1';
    $statement = $db->prepare($sql);
    $statement->execute($args);
    $file = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file)) {
        throw new RuntimeException('Target file is not a verified current-format file matching the requested game/engine: ' . $fileId);
    }
    $targetGameId = (int)$file['game_id'];
    $targetEngine = strtoupper(trim((string)$file['engine_key']));
    if (!$apply) {
        echo json_encode([
            'ok'=>true,'apply'=>false,'targeted'=>true,'file_id'=>$fileId,
            'game_id'=>$targetGameId,'engine'=>$targetEngine,
            'package_name'=>(string)$file['package_name'],'status'=>'would_rebuild',
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if ($targetEngine === 'UE3' && !class_exists(PdoUe3VerifyImportProjectionResolver::class)) {
        throw new RuntimeException('UE3 dependency rebuild requires PdoUe3VerifyImportProjectionResolver.');
    }
    $rebuilder->rebuild(
        $fileId,
        null,
        0,
        100,
        'Rebuilding indexed dependencies',
        true
    );
    $statsRebuilt = (new PdoGameCatalogStats($db))->rebuildGame($targetGameId, 15) !== null;
    echo json_encode([
        'ok'=>$statsRebuilt,'apply'=>true,'targeted'=>true,'file_id'=>$fileId,
        'game_id'=>$targetGameId,'engine'=>$targetEngine,
        'package_name'=>(string)$file['package_name'],'status'=>'rebuilt',
        'dependency_summary_refreshed'=>true,'game_stats_rebuilt'=>$statsRebuilt,
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($statsRebuilt ? 0 : 2);
}

$cursor = $afterId;
$processed = 0;
$rebuilt = 0;
$failed = 0;
$failures = [];
$sampleResults = [];
$affectedGameIds = [];
$engineCounts = [];
$started = microtime(true);

fwrite(STDERR, sprintf(
    "Dependency rebuild | apply=%s | game=%s | engine=%s | after_id=%d | scope=%s | limit=%s\n",
    $apply ? 'yes' : 'no',
    $gameId > 0 ? (string)$gameId : 'any',
    $engine !== '' ? $engine : 'any',
    $afterId,
    $missingOnly ? 'files-with-current-missing-rows' : 'all-current-files',
    $limit > 0 ? (string)$limit : 'unlimited'
));

while (true) {
    $remaining = $limit > 0 ? $limit - $processed : $batchSize;
    if ($limit > 0 && $remaining <= 0) {
        break;
    }
    $take = min($batchSize, $limit > 0 ? $remaining : $batchSize);

    $sql = 'SELECT f.id,f.game_id,f.package_name,UPPER(TRIM(p.engine_key)) engine_key'
        . ' FROM ue_files f'
        . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=?'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' WHERE f.scan_status="verified" AND f.id>?';
    $args = [BlockedCompressedMetadataContainer::FORMAT_VERSION, $cursor];

    if ($engine !== '') {
        $sql .= ' AND UPPER(TRIM(p.engine_key))=?';
        $args[] = $engine;
    }
    if ($gameId > 0) {
        $sql .= ' AND f.game_id=?';
        $args[] = $gameId;
    }
    if ($missingOnly) {
        $sql .= ' AND EXISTS (SELECT 1 FROM ue_dependency_links l WHERE l.file_id=f.id AND l.status=0)';
    }
    $sql .= ' ORDER BY f.id LIMIT ' . (int)$take;

    $statement = $db->prepare($sql);
    $statement->execute($args);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($files === []) {
        break;
    }

    foreach ($files as $file) {
        $fileId = (int)$file['id'];
        $fileGameId = (int)$file['game_id'];
        $fileEngine = strtoupper(trim((string)($file['engine_key'] ?? '')));
        $cursor = max($cursor, $fileId);
        $engineCounts[$fileEngine] = ($engineCounts[$fileEngine] ?? 0) + 1;

        if (!$apply) {
            if (count($sampleResults) < 100) {
                $sampleResults[] = [
                    'file_id' => $fileId,
                    'game_id' => $fileGameId,
                    'engine' => $fileEngine,
                    'package_name' => (string)$file['package_name'],
                    'status' => 'would_rebuild',
                ];
            }
            $processed++;
            continue;
        }

        try {
            // Keep UE3 on this canonical rebuild entry point. The shared resolver
            // must dispatch UE3 files to the source-backed VerifyImport matcher;
            // fail early if that matcher is unavailable rather than falling
            // through to generic object coverage.
            if ($fileEngine === 'UE3' && !class_exists(PdoUe3VerifyImportProjectionResolver::class)) {
                throw new RuntimeException('UE3 dependency rebuild requires PdoUe3VerifyImportProjectionResolver.');
            }

            // This is the single production parent path. CompactDependencyRebuilder
            // and PdoDependencyResolver select UE1/UE2/UE3/UE4/UE5 semantics only
            // where their source contracts differ.
            $rebuilder->rebuild(
                $fileId,
                null,
                0,
                100,
                'Rebuilding indexed dependencies',
                true
            );
            $rebuilt++;
            $affectedGameIds[$fileGameId] = true;
            if (count($sampleResults) < 100) {
                $sampleResults[] = [
                    'file_id' => $fileId,
                    'game_id' => $fileGameId,
                    'engine' => $fileEngine,
                    'package_name' => (string)$file['package_name'],
                    'status' => 'rebuilt',
                ];
            }
        } catch (Throwable $error) {
            $failed++;
            if (count($failures) < 50) {
                $failures[] = [
                    'file_id' => $fileId,
                    'game_id' => $fileGameId,
                    'engine' => $fileEngine,
                    'error' => get_class($error) . ': ' . $error->getMessage(),
                ];
            }
        }

        $processed++;
        if (($processed % $progressEvery) === 0) {
            $elapsed = max(0.001, microtime(true) - $started);
            fwrite(STDERR, sprintf(
                "processed=%d rebuilt=%d failed=%d last_id=%d rate=%.1f files/s\n",
                $processed,
                $rebuilt,
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
            if (count($failures) < 50) {
                $failures[] = [
                    'game_id' => (int)$affectedGameId,
                    'error' => get_class($error) . ': ' . $error->getMessage(),
                ];
            }
        }
    }
}

ksort($engineCounts);
$elapsed = microtime(true) - $started;
echo json_encode([
    'ok' => $failed === 0 && $statsFailed === 0,
    'apply' => $apply,
    'engine' => $engine !== '' ? $engine : null,
    'game_id' => $gameId > 0 ? $gameId : null,
    'scope' => $missingOnly ? 'files_with_current_missing_rows' : 'all_current_files',
    'processed' => $processed,
    'selected_by_engine' => $engineCounts,
    'rebuilt' => $rebuilt,
    'failed' => $failed,
    'game_stats_rebuilt' => $statsRebuilt,
    'game_stats_failed' => $statsFailed,
    'after_id' => $afterId,
    'last_id' => $cursor,
    'limit' => $limit,
    'batch_size' => $batchSize,
    'elapsed_seconds' => round($elapsed, 3),
    'failures' => $failures,
    'sample_results' => $sampleResults,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === 0 && $statsFailed === 0 ? 0 : 2);
