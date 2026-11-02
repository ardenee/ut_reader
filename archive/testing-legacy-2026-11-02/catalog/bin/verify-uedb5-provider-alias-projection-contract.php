#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ProviderKeyPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ProviderKeyBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,package_key_kind INTEGER,package_key BLOB)');
$db->exec('CREATE TABLE ue_file_package_aliases(id INTEGER PRIMARY KEY,file_id INTEGER,game_id INTEGER,package_name TEXT)');
$db->exec('CREATE TABLE ue_uedb5_provider_keys(source_kind INTEGER,source_id INTEGER,game_id INTEGER,package_key_kind INTEGER,package_key BLOB,file_id INTEGER,PRIMARY KEY(source_kind,source_id))');
$classic=Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME;
$primaryKey=md5('detail',true);
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?,?)')->execute([10,3,$classic,$primaryKey]);
$db->prepare('INSERT INTO ue_file_package_aliases VALUES(?,?,?,?)')->execute([501,10,3,'Detail']);
$db->prepare('INSERT INTO ue_file_package_aliases VALUES(?,?,?,?)')->execute([502,10,3,'DetailOld']);
$publisher=new PdoUedb5ProviderKeyPublisher($db);
$expected=$publisher->expectedRows(10);
$check('primary_plus_every_alias_is_expected',count($expected)===3);
$check('primary_source_identity_is_stable',
    ($expected[0]['source_kind']??0)===Uedb5ProviderKeyBuilder::SOURCE_PRIMARY
    && ($expected[0]['source_id']??0)===10);
$aliases=array_values(array_filter($expected,static fn(array $r):bool=>(int)$r['source_kind']===Uedb5ProviderKeyBuilder::SOURCE_ALIAS));
$check('alias_source_ids_are_catalog_alias_ids',array_column($aliases,'source_id')===[501,502]);
$check('alias_key_is_case_insensitive_name_key',
    isset($aliases[1]) && hash_equals(md5('detailold',true),(string)$aliases[1]['package_key']));
$count=$publisher->publish(10);
$check('publisher_writes_primary_and_alias_rows',$count===3);
$actual=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_provider_keys WHERE file_id=10')->fetchColumn();
$check('published_row_count_matches_expected',$actual===3);
$zenKind=Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID;
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?,?)')->execute([20,8,$zenKind,hex2bin('0011223344556677')]);
$db->prepare('INSERT INTO ue_file_package_aliases VALUES(?,?,?,?)')->execute([601,20,8,'/Game/Alias']);
$zenRows=$publisher->expectedRows(20);
$check('zen_package_id_does_not_invent_name_alias_identity',count($zenRows)===1);

$source=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5BaseProjectionPublisher.php');
$check('base_publisher_delegates_provider_alias_projection',str_contains($source,'PdoUedb5ProviderKeyPublisher'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
