<?php
/**
 * Original UT4 source-module declaration names versus live /Script/ references.
 * This is source module *name* coverage, not native runtime class registration.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\CatalogUt4NativeScriptSourceIndex as SourceModules;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameIndexedPackageCoverageQuery as Coverage;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameDependencyFileCountQuery as Counts;

$sourceRoot = getenv('UT4_SOURCE_ROOT') ?: 'L:/Source/Games/UT4/UnrealTournament';
$manifest = json_decode((string)file_get_contents(SourceModules::manifestPath()), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest) || count($manifest['modules'] ?? []) !== 38) {
    throw new RuntimeException('UT4 source inventory missing or incomplete.');
}
$missing = [];
$known = [];
foreach ($manifest['modules'] as $item) {
    $name = (string)$item['module'];
    if ($item['source_match']) {
        $known[] = $name;
        foreach ($item['source_matches'] as $entry) {
            $filename = rtrim($sourceRoot, '/\\') . '/' . $entry['path'];
            if (!is_file($filename) || hash_file('sha256', $filename) !== $entry['sha256']) {
                throw new RuntimeException('Original UT4 Build.cs changed: ' . $filename);
            }
        }
    } else {
        $missing[] = $name;
    }
}
sort($missing);
if (count($known) !== 35
    || $missing !== ['BlueprintHttp', 'CookedLog', 'McpProfileSys']
    || count(SourceModules::sourcePackages()) !== 35) {
    throw new RuntimeException('Wrong UT4 native source-module cohort.');
}

$config = catalog_config();
$config['db']['host'] = getenv('UT4_COVERAGE_DB_HOST') ?: '192.168.0.55';
$config['cache']['path'] = 'D:/Temp/UnrealDB/ut4-source-module-acceptance-cache';
$db = catalog_db($config);
$db->exec('SET SESSION MAX_EXECUTION_TIME=60000');
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $coverage = new Coverage($db);
    $ut4 = $coverage->forGame(7, true);
    if ($ut4['source_matched_script_references'] !== 413651
        || $ut4['source_unmatched_script_references'] !== 32
        || $ut4['native_script_references'] !== 413683
        || $ut4['source_unmatched_script_files'] !== 8) {
        throw new RuntimeException('UT4 original database source-name mismatch.');
    }
    $filters = [];
    foreach (['script_source_matched', 'script_source_unmatched', 'unindexed'] as $filter) {
        $pred = Coverage::fileFilterPredicate($filter, true);
        $actual = (int)$db->query('SELECT COUNT(*) FROM ue_files f WHERE f.game_id=7 '
            . 'AND f.scan_status="verified" AND ' . $pred)->fetchColumn();
        $expect = match ($filter) {
            'script_source_matched' => $ut4['source_matched_script_files'],
            'script_source_unmatched' => $ut4['source_unmatched_script_files'],
            'unindexed' => $ut4['unindexed_files'],
        };
        if ($actual !== $expect) {
            throw new RuntimeException('UT4 game-files filter mismatch: ' . $filter);
        }
        $filters[$filter] = $actual;
    }

    $unknownSql = 'SELECT s.required_package_name,SUM(s.dependency_count) reference_count '
        . 'FROM ue_uedb5_dependency_packages s WHERE s.game_id=7 '
        . 'AND s.required_package_name LIKE "/Script/%" AND NOT ('
        . SourceModules::sourceMatchSql('s') . ') '
        . 'GROUP BY s.required_package_name ORDER BY s.required_package_name';
    $unknownRows = $db->query($unknownSql)->fetchAll(PDO::FETCH_ASSOC);
    if (count($unknownRows) !== 3
        || array_sum(array_map(static fn(array $r): int => (int)$r['reference_count'],
            $unknownRows)) !== 32) {
        throw new RuntimeException('Native module source-unmatched references drift.');
    }

    $originalsSql = 'SELECT DISTINCT s.file_id FROM ue_uedb5_dependency_packages s '
        . 'WHERE s.game_id=7 AND s.required_package_name LIKE "/Script/%" '
        . 'AND NOT (' . SourceModules::sourceMatchSql('s') . ') '
        . 'ORDER BY s.file_id';
    $originalIds = array_map('intval',
        $db->query($originalsSql)->fetchAll(PDO::FETCH_COLUMN));
    if (count($originalIds) !== 8) {
        throw new RuntimeException('Expected eight original UT4 files with modules absent from source.');
    }
    $perFile = $coverage->forFiles(7, $originalIds, true);
    foreach ($originalIds as $fileId) {
        $detail = $perFile[$fileId] ?? null;
        if (!is_array($detail)
            || (int)$detail['source_unmatched_script'] <= 0
            || (int)$detail['source_matched_script']
                + (int)$detail['source_unmatched_script'] !== (int)$detail['native_script']) {
            throw new RuntimeException('UT4 per-file source module breakdown drift: ' . $fileId);
        }
    }
    $gamesUi = (string)file_get_contents(dirname(__DIR__, 2) . '/catalog/games.php');
    $filesUi = (string)file_get_contents(dirname(__DIR__, 2) . '/catalog/game-files.php');
    if (!str_contains($gamesUi, '>Missing</th>')
        || !str_contains($gamesUi, 'Native Script (Source)')
        || !str_contains($filesUi, "'missing: '")
        || !str_contains($filesUi, "'script not in source: '")
        || str_contains($gamesUi, '>Not Indexed</th>')) {
        throw new RuntimeException('User-visible Missing/Native Script labels regressed.');
    }

    $unreal2 = $coverage->forGame(2, false);
    $unreal2Filter = Coverage::fileFilterPredicate('unindexed', false);
    $unreal2Files = (int)$db->query('SELECT COUNT(*) FROM ue_files f '
        . 'WHERE f.game_id=2 AND f.scan_status="verified" AND ' . $unreal2Filter)
        ->fetchColumn();
    if ($unreal2Files !== 2 || $unreal2['unindexed_references'] !== 10) {
        throw new RuntimeException('Unreal II missing package filter changed unexpectedly.');
    }
    $db->rollBack();
    echo json_encode([
        'pass'=>true,'read_only'=>true,'ut4_source_module_names'=>38,
        'matched_declared_module_names'=>35,'not_declared_module_names'=>$missing,
        'original_build_cs_sha256_verified'=>true,
        'ut4_counts'=>$ut4,'filter_file_counts'=>$filters,
        'source_unmatched_module_references'=>$unknownRows,
        'source_unmatched_original_file_ids'=>$originalIds,
        'source_unmatched_original_file_counts'=>$perFile,
        'public_labels_verified'=>true,
        'unreal2_missing_package_files'=>$unreal2Files,
        'unreal2_missing_package_references'=>$unreal2['unindexed_references'],
        'meaning'=>'source-name match only; NOT loaded module/class or VerifyImport',
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
