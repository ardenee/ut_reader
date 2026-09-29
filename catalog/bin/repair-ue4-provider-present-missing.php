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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
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
$summaryWriter = new PdoDependencyPackageSummary($db);

$cursor = $afterId;
$lastCompletedId = $afterId;
$selected = 0;
$processed = 0;
$rebuilt = 0;
$dependenciesChanged = 0;
$summaryFiles = 0;
$summaryBuffer = [];
$failed = 0;
$failures = [];
$samples = [];
$started = microtime(true);

fwrite(STDERR, sprintf(
    "UE4 provider-present missing repair | apply=%s | game=%d | after_id=%d | limit=%s\n",
    $apply ? 'yes' : 'no', $gameId, $afterId,
    $limit > 0 ? (string)$limit : 'unlimited'
));
$selectSql = 'SELECT DISTINCT f.id,f.package_name,f.package_version '
    . 'FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'JOIN ue_package_providers p ON p.game_id=f.game_id '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci '
    . 'JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id AND pf.scan_status="verified" '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 AND f.id>? '
    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
    . 'WHERE bad.file_size=pf.file_size AND bad.md5=LOWER(pf.md5) AND bad.sha1=LOWER(pf.sha1)) '
    . 'ORDER BY f.id LIMIT %d';

$packageSqlPrefix = 'SELECT DISTINCT l.file_id,CONVERT(pkg.value_prefix USING utf8mb4) required_package '
    . 'FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'JOIN ue_package_providers p ON p.game_id=f.game_id '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci '
    . 'JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id AND pf.scan_status="verified" '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 AND l.file_id IN (%s) '
    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
    . 'WHERE bad.file_size=pf.file_size AND bad.md5=LOWER(pf.md5) AND bad.sha1=LOWER(pf.sha1)) '
    . 'ORDER BY l.file_id,required_package';

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

    $fileIds = array_map(static fn(array $row): int => (int)$row['id'], $files);
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $packageStatement = $db->prepare(sprintf($packageSqlPrefix, $placeholders));
    $packageStatement->execute(array_merge([$gameId], $fileIds));
    $packagesByFile = [];
    foreach ($packageStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $packageRow) {
        $owner = (int)($packageRow['file_id'] ?? 0);
        $package = trim((string)($packageRow['required_package'] ?? ''));
        if ($owner > 0 && $package !== '') {
            $packagesByFile[$owner][$package] = true;
        }
    }

    $changedThisBatch = [];
    foreach ($files as $file) {
        $fileId = (int)$file['id'];
        $packages = array_keys($packagesByFile[$fileId] ?? []);
        $selected++;
        $sampleIndex = null;
        if (count($samples) < 100) {
            $sampleIndex = count($samples);
            $samples[] = [
                'file_id'=>$fileId,
                'package_name'=>(string)$file['package_name'],
                'package_version'=>(int)$file['package_version'],
                'affected_package_count'=>count($packages),
                'affected_packages'=>array_slice($packages, 0, 10),
                'status'=>$apply ? 'targeted' : 'would_rebuild_packages',
            ];
        }
        if ($packages === []) {
            $failed++;
            $failures[] = [
                'file_id'=>$fileId,
                'phase'=>'select_packages',
                'error'=>'Selected provider-present missing file has no affected package names.',
            ];
            $stop = true;
            break;
        }
        if ($apply) {
            try {
                $result = $rebuilder->rebuildForPackages($fileId, $packages, false);
                $processed++;
                $changed = (int)($result['dependencies_changed'] ?? 0);
                $dependenciesChanged += $changed;
                if ($changed > 0) {
                    $rebuilt++;
                    $changedThisBatch[$fileId] = true;
                }
                if ($sampleIndex !== null) {
                    $samples[$sampleIndex]['status'] = $changed > 0 ? 'changed' : 'no_change';
                    $samples[$sampleIndex]['dependencies_changed'] = $changed;
                    $samples[$sampleIndex]['imports_processed'] = (int)($result['imports_processed'] ?? 0);
                    $samples[$sampleIndex]['imports_total'] = (int)($result['imports_total'] ?? 0);
                }
            } catch (Throwable $error) {
                $failed++;
                $failures[] = [
                    'file_id'=>$fileId,
                    'phase'=>'rebuild_packages',
                    'packages'=>$packages,
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
                "selected=%d processed=%d rebuilt=%d changed=%d failed=%d last_id=%d rate=%.1f files/s\n",
                $selected, $processed, $rebuilt, $dependenciesChanged,
                $failed, $lastCompletedId, $selected / $elapsed
            ));
        }
        if ($limit > 0 && $selected >= $limit) {
            $stop = true;
            break;
        }
    }

    if ($apply && $changedThisBatch !== []) {
        try {
            $summaryResult = $summaryWriter->rebuildFiles(array_map('intval', array_keys($changedThisBatch)));
            if (empty($summaryResult['available'])) {
                throw new RuntimeException('Dependency package summary projection is unavailable.');
            }
            $summaryFiles += (int)($summaryResult['files'] ?? 0);
        } catch (Throwable $error) {
            $failed++;
            $failures[] = [
                'phase'=>'package_summaries',
                'file_ids'=>array_map('intval', array_keys($changedThisBatch)),
                'error'=>get_class($error).': '.$error->getMessage(),
            ];
            $stop = true;
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
    'processed_files'=>$processed,
    'rebuilt_files'=>$rebuilt,
    'dependencies_changed'=>$dependenciesChanged,
    'summary_files_rebuilt'=>$summaryFiles,
    'failed'=>$failed,
    'game_stats_rebuilt'=>$statsRebuilt,
    'game_stats_failed'=>$statsFailed,
    'elapsed_seconds'=>round($elapsed, 3),
    'failures'=>$failures,
    'sample_selected_files'=>$samples,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
