<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ObjectCandidateSynchronizer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-object-sync-'.bin2hex(random_bytes(6));
mkdir($tmp,0777,true);
$writer=new Uedb5MetadataSnapshotWriter($tmp);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,package_key_kind INTEGER,package_key BLOB,package_name TEXT)');
$db->exec('CREATE TABLE ue_uedb5_search_keys(key_hash BLOB,key_length INTEGER,key_fingerprint BLOB PRIMARY KEY,normalized_text BLOB)');
$db->exec('CREATE TABLE ue_uedb5_object_candidates(file_id INTEGER,object_kind INTEGER,object_index INTEGER,object_name_hash BLOB,object_name_length INTEGER,public_export_hash BLOB,PRIMARY KEY(file_id,object_kind,object_index))');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,source_index INTEGER)');
$db->exec('INSERT INTO ue_uedb5_dependency_edges VALUES(42,1,7)');

$snapshot=[
    'file'=>['id'=>42,'game_id'=>12,'package_name'=>'SyncFixture','original_name'=>'SyncFixture.unr'],
    'package_family'=>'classic-linkerload','source_policy'=>'test-object-candidate-sync',
    'section_schemas'=>[
        'summary'=>'ue1.unreal.package-summary.v1','exports'=>'ue1.unreal.object-export.v1',
    ],
    'sections'=>[
        'summary'=>[['package_version'=>68]],
        'exports'=>[
            ['index'=>0,'class_index'=>0,'super_index'=>0,'outer_index'=>0,'object_name'=>'Panel','object_flags'=>4],
            ['index'=>1,'class_index'=>0,'super_index'=>0,'outer_index'=>0,'object_name'=>'Trim','object_flags'=>4],
        ],
    ],
];
$writer->write($snapshot);
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?,?,?)')->execute([
    42,12,Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,md5('syncfixture',true),'SyncFixture'
]);
$key=static function(string $value):array{
    $normalized=CatalogUnrealIdentityHash::nameKey($value);
    return ['hash'=>md5($normalized,true),'length'=>strlen($normalized)];
};
$wrong=$key('Wrong');$stale=$key('Stale');
$db->prepare('INSERT INTO ue_uedb5_object_candidates VALUES(?,?,?,?,?,NULL)')->execute([
    42,Uedb5SqlProjectionContract::OBJECT_KIND_EXPORT,0,$wrong['hash'],$wrong['length']
]);
$db->prepare('INSERT INTO ue_uedb5_object_candidates VALUES(?,?,?,?,?,NULL)')->execute([
    42,Uedb5SqlProjectionContract::OBJECT_KIND_EXPORT,2,$stale['hash'],$stale['length']
]);

$sync=new PdoUedb5ObjectCandidateSynchronizer($db,$tmp);
$before=$sync->reconcile(42,false);
$check('detects_missing_candidate',($before['missing_count']??-1)===1);
$check('detects_stale_candidate',($before['stale_count']??-1)===1);
$check('detects_changed_candidate',($before['changed_count']??-1)===1);
$check('dry_run_does_not_claim_repair',empty($before['repaired']));
$applied=$sync->reconcile(42,true);
$check('apply_repairs_projection',!empty($applied['repaired']));
$check('apply_reports_expected_candidate_count',($applied['expected_count']??-1)===2);
$check('apply_reports_actual_candidate_count',($applied['actual_count']??-1)===2);
$after=$sync->reconcile(42,false);
$check('rerun_is_idempotent',!empty($after['matches'])&&empty($after['repaired']));
$check('search_dictionary_has_package_and_exports',(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_search_keys')->fetchColumn()===3);
$check('dependency_projection_is_untouched',(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE file_id=42 AND source_index=7')->fetchColumn()===1);

$remove=static function(string $path)use(&$remove):void{
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry){if($entry==='.'||$entry==='..')continue;$child=$path.DIRECTORY_SEPARATOR.$entry;if(is_dir($child))$remove($child);else @unlink($child);}
    @rmdir($path);
};
$remove($tmp);
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);