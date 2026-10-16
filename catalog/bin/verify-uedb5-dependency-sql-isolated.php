<?php
declare(strict_types=1);
/** Real V5 dependency SQL publisher against session-temporary shadow tables only. */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5DependencyProjectionPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder;

$app = catalog_bootstrap(false);
$db = $app->db;
$reader = new Uedb5MetadataReader((string)$app->config['storage_path']);
$tables = ['ue_uedb5_dependency_edges','ue_uedb5_dependency_packages'];
$ddl = [
    'CREATE TEMPORARY TABLE ue_uedb5_dependency_edges (
        file_id BIGINT UNSIGNED NOT NULL,source_kind TINYINT UNSIGNED NOT NULL,
        source_index INT UNSIGNED NOT NULL,classification TINYINT UNSIGNED NOT NULL,
        outcome TINYINT UNSIGNED NOT NULL,required_package_key_kind TINYINT UNSIGNED NULL,
        required_package_key VARBINARY(16) NULL,required_object_key_kind TINYINT UNSIGNED NULL,
        required_object_key VARBINARY(16) NULL,resolved_file_id BIGINT UNSIGNED NULL,
        resolved_object_kind TINYINT UNSIGNED NULL,resolved_object_index INT UNSIGNED NULL,
        PRIMARY KEY(file_id,source_kind,source_index)) ENGINE=InnoDB',
    'CREATE TEMPORARY TABLE ue_uedb5_dependency_packages (
        game_id INT UNSIGNED NOT NULL,file_id BIGINT UNSIGNED NOT NULL,
        package_key_kind TINYINT UNSIGNED NOT NULL,package_key VARBINARY(16) NOT NULL,
        required_package_name VARCHAR(512) NOT NULL DEFAULT \'\',
        dependency_count INT UNSIGNED NOT NULL DEFAULT 0,
        resolved_count INT UNSIGNED NOT NULL DEFAULT 0,
        missing_count INT UNSIGNED NOT NULL DEFAULT 0,
        package_only_count INT UNSIGNED NOT NULL DEFAULT 0,
        common_count INT UNSIGNED NOT NULL DEFAULT 0,
        unresolved_count INT UNSIGNED NOT NULL DEFAULT 0,
        hard_missing_count INT UNSIGNED NOT NULL DEFAULT 0,
        nonhard_missing_count INT UNSIGNED NOT NULL DEFAULT 0,
        summary_outcome TINYINT UNSIGNED NOT NULL,provider_file_id BIGINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY(file_id,package_key_kind,package_key)) ENGINE=InnoDB'
];
$created = []; $results = []; $failures = []; $providerConsensusFixture = null;
$files = [2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
try {
    foreach ($ddl as $i=>$sql) { $db->exec($sql); $created[]=$tables[$i]; }
    $publisher = new PdoUedb5DependencyProjectionPublisher($db);
    foreach ($files as $gameId=>$fileId) {
        try {
            $snapshot = $reader->snapshot($gameId,$fileId);
            $expected = Uedb5DependencyProjectionBuilder::build($snapshot);
            $counts = $publisher->publish($snapshot);
            $e = count($expected['dependency_edges']);
            $p = count($expected['dependency_packages']);
            $actualE = (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id='.$fileId)->fetchColumn();
            $actualP = (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages WHERE file_id='.$fileId)->fetchColumn();
            if ($actualE!==$e || $actualP!==$p
                || $counts['dependency_edges']!==$e || $counts['dependency_packages']!==$p) {
                throw new RuntimeException('Initial dependency projection count mismatch');
            }
            $publisher->publish($snapshot);
            $db->beginTransaction();
            $db->prepare('DELETE FROM ue_uedb5_dependency_edges WHERE file_id=?')->execute([$fileId]);
            $db->prepare('DELETE FROM ue_uedb5_dependency_packages WHERE file_id=?')->execute([$fileId]);
            $db->rollBack();
            $afterE = (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id='.$fileId)->fetchColumn();
            $afterP = (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages WHERE file_id='.$fileId)->fetchColumn();
            if ($afterE!==$e || $afterP!==$p) {
                throw new RuntimeException('Dependency transaction rollback mismatch');
            }
            $results[]=['game_id'=>$gameId,'file_id'=>$fileId,'ok'=>true,'edges'=>$e,'packages'=>$p,'rollback'=>true];
        } catch (Throwable $error) {
            if ($db->inTransaction()) { $db->rollBack(); }
            $failures[]=['game_id'=>$gameId,'error'=>$error->getMessage()];
        } finally {
            $reader->clearCache($gameId,$fileId);
        }
    }
    // Bounded three-provider regression on the real MySQL writer, but exclusively
    // inside session-temporary tables (never persistent catalog projections).
    $fixtureId=900000001;
    $fixture=[
        'file'=>['id'=>$fixtureId,'game_id'=>7,'package_name'=>'/Game/ProviderConsensusAudit'],
        'section_schemas'=>['imports'=>'ue5.zen.package-object-index.v1'],
        'sections'=>['dependency_results'=>[]],
    ];
    foreach([31,32,33] as $index=>$providerId){
        $fixture['sections']['dependency_results'][]=[
            'source_section'=>'imports','source_index'=>$index,
            'dependency_kind'=>'PackageImport','dependency_class'=>'hard',
            'hard'=>true,'outcome'=>'resolved',
            'required_package_id'=>'1020304050607080',
            'required_object_identity'=>'1122334455667788',
            'selected_provider_file_id'=>$providerId,
            'selected_provider_object'=>['export_index'=>$index],
        ];
    }
    $publisher->publish($fixture);
    $raw=$db->query('SELECT dependency_count,provider_file_id FROM ue_uedb5_dependency_packages WHERE file_id='.$fixtureId)->fetch(PDO::FETCH_ASSOC);
    $firstOk=is_array($raw) && (int)$raw['dependency_count']===3 && $raw['provider_file_id']===null;
    $fixture['sections']['dependency_results'][2]['selected_provider_file_id']=31;
    $publisher->publish($fixture);
    $raw=$db->query('SELECT provider_file_id FROM ue_uedb5_dependency_packages WHERE file_id='.$fixtureId)->fetch(PDO::FETCH_ASSOC);
    $secondOk=is_array($raw) && $raw['provider_file_id']===null;
    $providerConsensusFixture=['ok'=>$firstOk && $secondOk,
        'three_distinct_conflicting_providers'=>$firstOk,
        'later_repeated_provider_does_not_restore_consensus'=>$secondOk];
    if(!$providerConsensusFixture['ok']){
        $failures[]=['provider_consensus_fixture'=>'A conflicted package summary regained a provider identity.'];
    }
} catch (Throwable $error) { $failures[]=['setup_error'=>$error->getMessage()]; }
finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    foreach (array_reverse($created) as $table) {
        try { $db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table); }
        catch (Throwable $error) { $failures[]=['cleanup_error'=>$error->getMessage()]; }
    }
}
$ok = $failures===[] && count($results)===count($files);
echo json_encode(['ok'=>$ok,'temporary_sql_only'=>true,
    'source'=>'existing V5 dependency snapshots, not fresh resolver execution',
    'results'=>$results,'provider_consensus_fixture'=>$providerConsensusFixture,'failures'=>$failures],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
