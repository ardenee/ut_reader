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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

$options = getopt('', [
    'apply', 'game-id:', 'after-id::', 'limit::',
    'batch-size::', 'progress-every::',
]);
$apply = array_key_exists('apply', $options);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(0, (int)($options['limit'] ?? 0));
$batchSize = max(25, min(1000, (int)($options['batch-size'] ?? 250)));
$progressEvery = max(1, (int)($options['progress-every'] ?? 100));
if ($gameId < 1) throw new InvalidArgumentException('Specify --game-id.');
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
$rebuilder = new PdoCatalogDependencyRebuilder($db, $config);

$cursor = $afterId;
$lastCompletedId = $afterId;
$selected = 0;
$rebuilt = 0;
$failed = 0;
$failures = [];
$samples = [];
$started = microtime(true);

fwrite(STDERR, sprintf(
    "UE4 RF_Public repair | apply=%s | game=%d | after_id=%d | limit=%s\n",
    $apply ? 'yes' : 'no', $gameId, $afterId,
    $limit > 0 ? (string)$limit : 'unlimited'
));
$pathColumns = [];
foreach ($db->query('SHOW COLUMNS FROM ue_export_path_lookup')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
    $pathColumns[(string)$column['Field']] = true;
}
$hasExactIdentityColumns = isset(
    $pathColumns['object_flags'],
    $pathColumns['class_package_term_id'],
    $pathColumns['class_name_term_id']
);

$identityJoin = $hasExactIdentityColumns
    ? 'JOIN ue_export_path_lookup ep ON ep.file_id=e.file_id AND ep.export_index=e.export_index'
    : '';
$identityWhere = $hasExactIdentityColumns
    ? ' AND (ep.object_flags & 1)<>0 AND (ep.object_flags & 4)=0'
      . ' AND (l.import_class_package_term_id IS NULL OR ep.class_package_term_id=l.import_class_package_term_id)'
      . ' AND (l.import_class_name_term_id IS NULL OR ep.class_name_term_id=l.import_class_name_term_id)'
    : '';

$selectSql = 'SELECT DISTINCT f.id,f.package_name,f.package_version '
    . 'FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'JOIN ue_package_providers p ON p.game_id=f.game_id '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci '
    . 'JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id AND pf.scan_status="verified" '
    . 'JOIN ue_export_lookup e ON e.file_id=p.file_id AND e.path_hash=l.required_path_hash '
    . $identityJoin . ' '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 AND f.id>? '
    . $identityWhere . ' ORDER BY f.id LIMIT %d';


$stop = false;
while (!$stop) {
    $remaining = $limit > 0 ? $limit - $selected : $batchSize;
    if ($limit > 0 && $remaining <= 0) break;
    $take = min($batchSize, $limit > 0 ? $remaining : $batchSize);
    $statement = $db->prepare(sprintf($selectSql, $take));
    $statement->execute([
        BlockedCompressedMetadataContainer::FORMAT_VERSION,
        $gameId,
        $cursor,
    ]);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($files === []) break;
    foreach ($files as $file) {
        $fileId = (int)$file['id'];
        $selected++;
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
                    'Repairing UE4 RF_Public dependency state',
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

        if (($selected % $progressEvery) === 0) {
            $elapsed = max(0.001, microtime(true) - $started);
            fwrite(STDERR, sprintf(
                "selected=%d rebuilt=%d failed=%d last_id=%d rate=%.1f files/s\n",
                $selected, $rebuilt, $failed, $lastCompletedId, $selected / $elapsed
            ));
        }
        if ($limit > 0 && $selected >= $limit) {
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
        if ($statsRebuilt === 0) $statsFailed = 1;
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
    'selected_files'=>$selected,
    'rebuilt_files'=>$rebuilt,
    'failed'=>$failed,
    'game_stats_rebuilt'=>$statsRebuilt,
    'game_stats_failed'=>$statsFailed,
    'elapsed_seconds'=>round($elapsed, 3),
    'failures'=>$failures,
    'sample_selected_files'=>$samples,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
