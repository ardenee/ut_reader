#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5DependencyProjectionPublisher;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_uedb5_dependency_edges('
    .'file_id INTEGER,source_kind INTEGER,source_index INTEGER,classification INTEGER,outcome INTEGER,'
    .'required_package_key_kind INTEGER,required_package_key BLOB,required_object_key_kind INTEGER,'
    .'required_object_key BLOB,resolved_file_id INTEGER,resolved_object_kind INTEGER,resolved_object_index INTEGER,'
    .'PRIMARY KEY(file_id,source_kind,source_index))');
$db->exec('CREATE TABLE ue_uedb5_dependency_packages('
    .'game_id INTEGER,file_id INTEGER,package_key_kind INTEGER,package_key BLOB,required_package_name TEXT,'
    .'dependency_count INTEGER,resolved_count INTEGER,missing_count INTEGER,package_only_count INTEGER,'
    .'common_count INTEGER,unresolved_count INTEGER,hard_missing_count INTEGER,nonhard_missing_count INTEGER,'
    .'summary_outcome INTEGER,provider_file_id INTEGER,updated_at TEXT,'
    .'PRIMARY KEY(file_id,package_key_kind,package_key))');
$snapshot=[
    'file'=>['id'=>10,'game_id'=>3,'package_name'=>'Consumer'],
    'sections'=>['dependency_results'=>[
        [
            'source_section'=>'imports','source_index'=>0,'dependency_class'=>'hard','hard'=>true,
            'outcome'=>'resolved','required_package_identity'=>['kind'=>'package_name','value'=>'Core'],
            'required_object_identity'=>['kind'=>'classic_import','object_name'=>'ObjectA'],
            'selected_provider_file_id'=>20,'selected_provider_object'=>['export_index'=>5],
        ],
        [
            'source_section'=>'imports','source_index'=>1,'dependency_class'=>'hard','hard'=>true,
            'outcome'=>'missing','required_package_identity'=>['kind'=>'package_name','value'=>'MissingPkg'],
            'required_object_identity'=>['kind'=>'classic_import','object_name'=>'ObjectB'],
            'selected_provider_file_id'=>null,'selected_provider_object'=>null,
        ],
        [
            'source_section'=>'imports','source_index'=>2,'dependency_class'=>'hard','hard'=>true,
            'outcome'=>'common','required_package_identity'=>['kind'=>'package_name','value'=>'Engine'],
            'required_object_identity'=>['kind'=>'classic_import','object_name'=>'ObjectC'],
            'selected_provider_file_id'=>null,'selected_provider_object'=>null,
        ],
    ]],
];
$db->exec("INSERT INTO ue_uedb5_dependency_edges VALUES(10,1,99,1,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL)");
$db->exec("INSERT INTO ue_uedb5_dependency_packages VALUES(3,10,1,X'00112233445566778899AABBCCDDEEFF','Stale',1,0,1,0,0,0,1,0,0,NULL,'2000-01-01 00:00:00')");
$publisher=new PdoUedb5DependencyProjectionPublisher($db);
$result=$publisher->publish($snapshot);
$check('publisher_returns_exact_edge_count',($result['dependency_edges']??-1)===3);
$check('publisher_returns_exact_package_count',($result['dependency_packages']??-1)===3);
$check('publisher_replaces_stale_edges',(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id=10')->fetchColumn()===3);
$check('publisher_replaces_stale_package_summaries',(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages WHERE file_id=10')->fetchColumn()===3);
$check('resolved_edge_preserves_provider_and_object',
    (string)$db->query('SELECT resolved_file_id||":"||resolved_object_index FROM ue_uedb5_dependency_edges WHERE file_id=10 AND source_index=0')->fetchColumn()==='20:5');
$check('missing_package_summary_counts_hard_missing',
    (int)$db->query("SELECT hard_missing_count FROM ue_uedb5_dependency_packages WHERE file_id=10 AND required_package_name='MissingPkg'")->fetchColumn()===1);
$check('stale_edge_is_gone',(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id=10 AND source_index=99')->fetchColumn()===0);

$db->beginTransaction();
$publisher->publish($snapshot);
$check('publisher_can_join_outer_transaction',$db->inTransaction());
$db->rollBack();

$source=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5DependencyProjectionPublisher.php');
$check('publisher_calls_staging_write_guard',str_contains($source,'Uedb5StagingIsolationContract::assertWriteTable'));
$check('publisher_batches_dependency_rows',str_contains($source,'array_chunk($rows, 250)'));
$check('publisher_has_deadlock_retry',str_contains($source,'PdoContention::retryable'));
$check('publisher_never_writes_live_v4_registration',!str_contains($source,'ue_file_metadata'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
