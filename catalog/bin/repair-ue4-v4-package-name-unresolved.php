#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

$options = getopt('', [
    'apply',
    'game-id:',
    'after-id::',
    'limit::',
    'batch-size::',
    'progress-every::',
]);
$apply = array_key_exists('apply', $options);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(0, (int)($options['limit'] ?? 0));
$batchSize = max(25, min(1000, (int)($options['batch-size'] ?? 250)));
$progressEvery = max(1, (int)($options['progress-every'] ?? 250));
if ($gameId < 1) {
    throw new InvalidArgumentException('Specify --game-id.');
}

$config = catalog_config();
$db = catalog_db($config);
$game = catalog_one(
    $db,
    'SELECT g.id,g.name,g.slug,UPPER(TRIM(p.engine_key)) engine_key FROM ue_games g '
    . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
    . 'WHERE g.id=? LIMIT 1',
    [$gameId]
);
if (!is_array($game) || strtoupper((string)($game['engine_key'] ?? '')) !== 'UE4') {
    throw new RuntimeException('Selected game is not configured as UE4.');
}

$storageRoot = trim((string)($config['storage_path'] ?? ''));
$reader = new BlockedCompressedMetadataReader($db, $storageRoot);
$rebuilder = new PdoCatalogDependencyRebuilder($db, $config);

$cursor = $afterId;
$lastCompletedId = $afterId;
$scanned = 0;
$affected = 0;
$rebuilt = 0;
$skipped = 0;
$failed = 0;
$failures = [];
$samples = [];
$started = microtime(true);

fwrite(STDERR, sprintf(
    "UE4 UEDB4 PackageName repair | apply=%s | game=%d | after_id=%d | limit=%s\n",
    $apply ? 'yes' : 'no',
    $gameId,
    $afterId,
    $limit > 0 ? (string)$limit : 'unlimited'
));

/** Return true when a currently-missing Import reaches an Export outer. */
function ue4_v4_missing_reaches_export_outer(
    PDO $db,
    BlockedCompressedMetadataReader $reader,
    int $fileId
): bool {
    $statement = $db->prepare(
        'SELECT import_index FROM ue_dependency_links '
        . 'WHERE file_id=? AND status=0 ORDER BY import_index'
    );
    $statement->execute([$fileId]);
    $frontier = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $index) {
        $frontier[(int)$index] = true;
    }
    $seen = [];
    while ($frontier !== []) {
        $indexes = array_keys($frontier);
        $frontier = [];
        $rows = $reader->rowsByIndexes($fileId, 'imports', $indexes);
        foreach ($indexes as $index) {
            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;
            $row = $rows[$index] ?? null;
            if (!is_array($row)) {
                throw new RuntimeException(
                    'Missing UEDB4 Import #' . $index . ' for file #' . $fileId . '.'
                );
            }
            $outer = (int)($row['outer_index'] ?? 0);
            if ($outer > 0) {
                return true;
            }
            if ($outer < 0) {
                $parent = -$outer - 1;
                if (!isset($seen[$parent])) {
                    $frontier[$parent] = true;
                }
            }
        }
    }
    return false;
}
$stop = false;
while (!$stop) {
    $remaining = $limit > 0 ? $limit - $scanned : $batchSize;
    if ($limit > 0 && $remaining <= 0) {
        break;
    }
    $take = min($batchSize, $limit > 0 ? $remaining : $batchSize);
    $sql = 'SELECT f.id,f.package_name,f.package_version FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE f.game_id=? AND f.scan_status="verified" '
        . 'AND f.package_version>=519 AND f.id>? '
        . 'AND EXISTS (SELECT 1 FROM ue_dependency_links l '
        . 'WHERE l.file_id=f.id AND l.status=0) '
        . 'ORDER BY f.id LIMIT ' . (int)$take;
    $statement = $db->prepare($sql);
    $statement->execute([
        BlockedCompressedMetadataContainer::FORMAT_VERSION,
        $gameId,
        $cursor,
    ]);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($files === []) {
        break;
    }

    foreach ($files as $file) {
        $fileId = (int)$file['id'];
        try {
            $isAffected = ue4_v4_missing_reaches_export_outer(
                $db,
                $reader,
                $fileId
            );
        } catch (Throwable $error) {
            $failed++;
            $failures[] = [
                'file_id'=>$fileId,
                'phase'=>'detect',
                'error'=>get_class($error).': '.$error->getMessage(),
            ];
            $stop = true;
            break;
        }

        $scanned++;
        if (!$isAffected) {
            $skipped++;
            $cursor = $fileId;
            $lastCompletedId = $fileId;
        } else {
            $affected++;
            if (count($samples) < 100) {
                $samples[] = [
                    'file_id'=>$fileId,
                    'package_name'=>(string)$file['package_name'],
                    'package_version'=>(int)$file['package_version'],
                    'status'=>$apply ? 'rebuilt' : 'would_rebuild',
                ];
            }
            if ($apply) {
                try {
                    $rebuilder->rebuild(
                        $fileId,
                        null,
                        0,
                        100,
                        'Repairing UE4 v4 PackageName dependency state',
                        true
                    );
                    $rebuilt++;
                } catch (Throwable $error) {
                    $failed++;
                    $failures[] = [
                        'file_id'=>$fileId,
                        'phase'=>'rebuild',
                        'error'=>get_class($error).': '.$error->getMessage(),
                    ];
                    $stop = true;
                    break;
                }
            }
            $cursor = $fileId;
            $lastCompletedId = $fileId;
        }

        if (($scanned % $progressEvery) === 0 || $stop) {
            $elapsed = max(0.001, microtime(true) - $started);
            fwrite(STDERR, sprintf(
                "scanned=%d affected=%d rebuilt=%d skipped=%d failed=%d last_id=%d rate=%.1f files/s\n",
                $scanned,
                $affected,
                $rebuilt,
                $skipped,
                $failed,
                $lastCompletedId,
                $scanned / $elapsed
            ));
        }

        if ($limit > 0 && $scanned >= $limit) {
            $stop = true;
            break;
        }
    }
}

$statsRebuilt = 0;
$statsFailed = 0;
if ($apply && $rebuilt > 0 && $failed === 0) {
    try {
        $statsRebuilt = (new PdoGameCatalogStats($db))->rebuildGame($gameId, 15) !== null ? 1 : 0;
        if ($statsRebuilt === 0) {
            $statsFailed = 1;
        }
    } catch (Throwable $error) {
        $statsFailed = 1;
        $failures[] = [
            'game_id'=>$gameId,
            'phase'=>'game_stats',
            'error'=>get_class($error).': '.$error->getMessage(),
        ];
    }
}
$elapsed = microtime(true) - $started;
$result = [
    'ok'=>$failed === 0 && $statsFailed === 0,
    'apply'=>$apply,
    'game_id'=>$gameId,
    'engine'=>'UE4',
    'after_id'=>$afterId,
    'resume_after_id'=>$lastCompletedId,
    'limit'=>$limit,
    'scanned_candidates'=>$scanned,
    'affected_files'=>$affected,
    'rebuilt_files'=>$rebuilt,
    'skipped_without_export_outer'=>$skipped,
    'failed'=>$failed,
    'game_stats_rebuilt'=>$statsRebuilt,
    'game_stats_failed'=>$statsFailed,
    'elapsed_seconds'=>round($elapsed, 3),
    'failures'=>$failures,
    'sample_affected_files'=>$samples,
];
echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
