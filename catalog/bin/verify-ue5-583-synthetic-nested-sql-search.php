<?php
declare(strict_types=1);
/** Disposable SQL candidate-discovery plus actual V5 snapshot descendant search. */
$fixtureRoot='C:/Temp/ue583-nested-sql-'.bin2hex(random_bytes(5));
function catalog_config():array{global $fixtureRoot;return ['storage_path'=>$fixtureRoot];}
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Search\Uedb5CatalogMetadataSearch;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$v=$db->query('SELECT @@port p,@@datadir d')->fetch();
if((int)$v['p']!==3339||stripos((string)$v['d'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Refusing non-isolated MySQL');
$gameId=2;$fileId=990001;$package='/Game/Test/Building';
$names=['Building','Door','Handle','Window','DoorX','BuildingExtra'];
$exports=[
 ['index'=>0,'object_name'=>'Building','outer_index'=>0],
 ['index'=>1,'object_name'=>'Door','outer_index'=>1],
 ['index'=>2,'object_name'=>'Handle','outer_index'=>2],
 ['index'=>3,'object_name'=>'Window','outer_index'=>1],
 ['index'=>4,'object_name'=>'BuildingExtra','outer_index'=>0],
 ['index'=>5,'object_name'=>'DoorX','outer_index'=>5]
];
$snap=['file'=>['id'=>$fileId,'game_id'=>$gameId,'package_name'=>$package,'original_name'=>'synthetic-nested.uasset'],
 'package_family'=>'ue5-classic','source_policy'=>'synthetic-ue5-nested-search-sql-v1',
 'section_schemas'=>['names'=>'ue5.synthetic.names.v1','imports'=>'ue5.synthetic.imports.v1','exports'=>'ue5.synthetic.exports.v1'],
 'sections'=>['names'=>array_map(static fn($n,$i)=>['index'=>$i,'text'=>$n],$names,array_keys($names)),
 'imports'=>[],'exports'=>array_map(static fn($r)=>$r+['class_index'=>0,'object_flags'=>0],$exports)]];
try{
 (new Uedb5MetadataSnapshotWriter($fixtureRoot))->write($snap);
 foreach(['ue_files','ue_uedb5_compact_name_dictionary','ue_uedb5_compact_name_candidates','ue_uedb5_compact_object_candidates','ue_file_package_aliases','ue_uedb5_dependency_edges'] as $table){
  $create=$db->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
  $create=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$create,1);
  $db->exec($create);
 }
 $stmt=$db->prepare('INSERT INTO ue_files(id,game_id,package_name,original_name,stored_name,relative_path,extension,md5,sha1,scan_status) VALUES (?,?,?,?,?,?,?,?,?,?)');
 $stmt->execute([$fileId,$gameId,$package,'synthetic-nested.uasset','synthetic-nested.uasset','storage/synthetic-nested.uasset','uasset',md5('synthetic-sql'),sha1('synthetic-sql'),'verified']);
 $dict=$db->prepare('INSERT INTO ue_uedb5_compact_name_dictionary(name_key_hash,name_key_length,name_key_fingerprint) VALUES (?,?,?)');
 $cand=$db->prepare('INSERT INTO ue_uedb5_compact_name_candidates(file_id,name_id,first_name_index) VALUES (?,?,?)');
 foreach($names as $i=>$name){$key=CatalogUnrealIdentityHash::nameKey($name);$dict->execute([md5($key,true),strlen($key),hash('sha256',$key,true)]);$cand->execute([$fileId,(int)$db->lastInsertId(),$i]);}
 $search=new Uedb5CatalogMetadataSearch($db);
 $tests=[
  ['Building.Door',['Building.Door','Building.Door.Handle']],
  ['Building.Door.Handle',['Building.Door.Handle']],
  ['Building.Window',['Building.Window']],
  ['Building.DoorX',[]],
  ['BuildingExtra.DoorX',['BuildingExtra.DoorX']],
  [$package.'.Building.Door',[$package.'.Building.Door',$package.'.Building.Door.Handle']]
 ];
 $checks=[];$success=true;
 foreach($tests as [$query,$expected]){
  $matches=$search->findMatches($gameId,$query,30,['fields'=>['exports'],'extensions'=>['uasset']]);
  $values=[];
  foreach($matches[$fileId]??[] as $m){if(in_array($m['field'],['Export local path','Export path'],true))$values[]=$m['value'];}
  $values=array_values(array_unique($values));sort($values);sort($expected);
  $ok=$values===$expected&&count($matches)===($expected===[]?0:1);
  $checks[]=['query'=>$query,'ok'=>$ok,'expected'=>$expected,'actual'=>$values];
  $success=$success&&$ok;
 }
 echo json_encode(['ok'=>$success,'isolated'=>true,'temporary_sql_tables'=>true,'genuine_v5_writer_and_search'=>true,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
 exit($success?0:2);
}finally{
 if(is_dir($fixtureRoot)){
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $f){if($f->isDir())@rmdir($f->getPathname());else @unlink($f->getPathname());}
  @rmdir($fixtureRoot);
 }
}
