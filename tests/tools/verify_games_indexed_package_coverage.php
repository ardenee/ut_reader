<?php
/** Read-only original-index differential: catalogue availability is NOT native VerifyImport. */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogListQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameDependencyFileCountQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameIndexedPackageCoverageQuery;

$config = catalog_config();
if (($host = getenv('UE_COVERAGE_DB_HOST')) !== false && trim($host) !== '') {
    $config['db']['host'] = trim($host);
}
$config['cache']['path'] = rtrim(sys_get_temp_dir(), '/\\') . '/unrealdb-game-coverage-acceptance';
$db = catalog_db($config);
$db->exec('SET SESSION MAX_EXECUTION_TIME=65000');
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $games = (new PdoGameCatalogListQuery($db))->all();
    $counter = new PdoGameDependencyFileCountQuery($db, $config);
    $availability = new PdoGameIndexedPackageCoverageQuery($db);
    $out = [];
    foreach ($games as $game) {
        $id = (int)$game['id'];
        $engine = (string)($game['profile_engine'] ?? '');
        $epoch = (string)($game['dependency_stats_updated_at'] ?? '');
        if (!in_array($engine, ['UE1','UE2','UE3','UE4','UE5'], true) || $epoch === '') {
            continue;
        }
        $first = $counter->forCurrentGame($id, $engine, $epoch);
        $cached = $counter->forCurrentGame($id, $engine, $epoch);
        if ($first === null || $first != $cached) {
            throw new RuntimeException('Cache parity failed for game ' . $id);
        }
        foreach (['total_references','indexed_references','native_script_references',
            'unindexed_references','no_gaps_files','unindexed_files'] as $key) {
            if (!isset($first[$key]) || !is_int($first[$key])) {
                throw new RuntimeException('Missing coverage field ' . $key . ' game ' . $id);
            }
        }
        if ($first['total_references'] !== $first['indexed_references']
            + $first['native_script_references'] + $first['unindexed_references']) {
            throw new RuntimeException('Reference partition failed for game ' . $id);
        }
        if ($engine !== 'UE5'
            && $first['total_references'] !== (int)$game['dependency_count']) {
            throw new RuntimeException('Source reference count drift for game ' . $id);
        }
        if ($engine === 'UE4') {
            $q = $db->prepare("SELECT COALESCE(SUM(dependency_count),0) FROM ue_uedb5_dependency_packages WHERE game_id=? AND required_package_name LIKE '/Script/%'");
            $q->execute([$id]);
            if ($first['native_script_references'] !== (int)$q->fetchColumn()) {
                throw new RuntimeException('UE4 native module references misclassified.');
            }
        } elseif ($first['native_script_references'] !== 0) {
            throw new RuntimeException('Unexpected native Script classification in ' . $engine);
        }
        $out[] = [
            'game_id'=>$id,'game'=>(string)$game['name'],'engine'=>$engine,
            'total'=>$first['total_references'],'indexed'=>$first['indexed_references'],
            'native_script'=>$first['native_script_references'],
            'not_indexed'=>$first['unindexed_references'],
            'files_with_gaps'=>$first['unindexed_files'],
            'files_without_gaps'=>$first['no_gaps_files'],
        ];
    }
    if (count($out) !== 8) throw new RuntimeException('Expected eight current games.');
    $filters=[];
    foreach ([12,7] as $gameId) {
        $isUe4 = $gameId === 7;
        $stats = $availability->forGame($gameId, $isUe4);
        foreach (['indexed','unindexed','native_script','no_gaps'] as $kind) {
            if (!$isUe4 && $kind === 'native_script') continue;
            $predicate = PdoGameIndexedPackageCoverageQuery::fileFilterPredicate($kind, $isUe4);
            $query = $db->prepare('SELECT COUNT(*) FROM ue_files f WHERE f.game_id=? AND f.scan_status="verified" AND ' . $predicate);
            $query->execute([$gameId]);$actual=(int)$query->fetchColumn();
            $field = match($kind) {
                'indexed'=>'indexed_files','unindexed'=>'unindexed_files',
                'native_script'=>'native_script_files','no_gaps'=>'no_gaps_files',
            };
            if ($actual !== $stats[$field]) {
                throw new RuntimeException('Game-file filter mismatch: ' . $gameId . ' / ' . $kind);
            }
            $filters[]=['game_id'=>$gameId,'kind'=>$kind,'files'=>$actual];
        }
    }
    $originals = [[12,110902],[3,113253],[7,113526],[5,111460]];
    $perFile = [];
    foreach ($originals as [$gameId,$fileId]) {
        $row=$availability->forFiles($gameId,[$fileId],$gameId===7)[$fileId]??null;
        if (!is_array($row) || $row['total'] !== $row['indexed'] + $row['native_script'] + $row['unindexed']) {
            throw new RuntimeException('Original catalogue file lost its source entries: ' . $fileId);
        }
        $perFile[]=['game_id'=>$gameId,'file_id'=>$fileId,'counts'=>$row];
    }
    $db->rollBack();
    echo json_encode([
        'pass'=>true,'database_read_only'=>true,'no_runtime_mount_assumed'=>true,
        'game_counts'=>$out,'filter_counts'=>$filters,'original_file_cases'=>$perFile
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(2);
}
