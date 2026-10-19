<?php
declare(strict_types=1);
/**
 * Controlled nested export fixture for the real UE5 V5 descendant matcher.
 * Does not modify real Satisfactory files or application/isolated SQL.
 */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Search\Uedb5CatalogMetadataSearch;

$root='C:/Temp/ue583-synthetic-nested-search-'.bin2hex(random_bytes(5));
$gameId=990001;$fileId=990001;
$package='/Game/Test/Building';
$names=['Building','Door','Handle','Window','DoorX','BuildingExtra'];
$exports=[
 ['index'=>0,'object_name'=>'Building','outer_index'=>0,'class_index'=>0,'object_flags'=>0],
 ['index'=>1,'object_name'=>'Door','outer_index'=>1,'class_index'=>0,'object_flags'=>0],
 ['index'=>2,'object_name'=>'Handle','outer_index'=>2,'class_index'=>0,'object_flags'=>0],
 ['index'=>3,'object_name'=>'Window','outer_index'=>1,'class_index'=>0,'object_flags'=>0],
 ['index'=>4,'object_name'=>'BuildingExtra','outer_index'=>0,'class_index'=>0,'object_flags'=>0],
 ['index'=>5,'object_name'=>'DoorX','outer_index'=>5,'class_index'=>0,'object_flags'=>0]
];
$snapshot=[
 'file'=>['id'=>$fileId,'game_id'=>$gameId,'package_name'=>$package,'original_name'=>'synthetic-nested.uasset'],
 'package_family'=>'ue5-classic','source_policy'=>'synthetic-ue5-nested-path-acceptance',
 'section_schemas'=>['names'=>'ue5.synthetic.names.v1','imports'=>'ue5.synthetic.imports.v1','exports'=>'ue5.synthetic.exports.v1'],
 'sections'=>['names'=>array_map(static fn($name,$i)=>['index'=>$i,'text'=>$name],$names,array_keys($names)),
   'imports'=>[],'exports'=>$exports]
];
$passed=[];$fail=[];
try{
 $built=(new Uedb5MetadataSnapshotWriter($root))->write($snapshot);
 $loaded=(new Uedb5MetadataReader($root))->snapshot($gameId,$fileId);
 $paths=array_column(Uedb5ClassicDependencyResolver::exportCoverageRows($loaded),'local_path');
 $expected=['Building','Building.Door','Building.Door.Handle','Building.Window','BuildingExtra','BuildingExtra.DoorX'];
 $passed['v5_roundtrip_paths']=$paths===$expected;
 $class=new ReflectionClass(Uedb5CatalogMetadataSearch::class);
 $search=$class->newInstanceWithoutConstructor();
 $method=$class->getMethod('matchSnapshot');
 $cases=[
  ['query'=>'Building.Door','expected'=>['Building.Door','Building.Door.Handle'],'excluded'=>['Building.Window','BuildingExtra.DoorX']],
  ['query'=>'Building.Door.Handle','expected'=>['Building.Door.Handle'],'excluded'=>['Building.Door','Building.Window']],
  ['query'=>'Building.Window','expected'=>['Building.Window'],'excluded'=>['Building.Door']],
  ['query'=>'Building.DoorX','expected'=>[],'excluded'=>['Building.Door','BuildingExtra.DoorX']],
  ['query'=>'BuildingExtra','expected'=>['BuildingExtra','BuildingExtra.DoorX'],'excluded'=>['Building.Door']],
  ['query'=>'/Game/Test/Building.Building.Door','expected'=>[$package.'.Building.Door',$package.'.Building.Door.Handle'],'excluded'=>[$package.'.Building.Window']]
 ];
 $results=[];
 foreach($cases as $test){
  $matches=[];$args=[&$matches,$loaded,$gameId,$fileId,$test['query'],['exports'],[]];
  $method->invokeArgs($search,$args);
  $actual=[];
  foreach($matches[$fileId]??[] as $match)if(in_array($match['field'],['Export local path','Export path'],true))$actual[]=$match['value'];
  $actual=array_values(array_unique($actual));
  $ok=true;
  foreach($test['expected'] as $e)if(!in_array($e,$actual,true))$ok=false;
  foreach($test['excluded'] as $e)if(in_array($e,$actual,true))$ok=false;
  if(count($actual)!==count($test['expected']))$ok=false;
  $passed[$test['query']]=$ok;
  $results[]=['query'=>$test['query'],'ok'=>$ok,'matched'=>$actual];
 }
 $ok=!in_array(false,$passed,true);
 echo json_encode(['ok'=>$ok,'synthetic'=>true,'isolated'=>true,'database_writes'=>false,'v5_payload_sha256'=>$built['payload_sha256_hex']??null,'normalized_paths'=>$paths,'checks'=>$passed,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
 exit($ok?0:2);
}finally{
 if(is_dir($root)){
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $f){if($f->isDir())@rmdir($f->getPathname());else @unlink($f->getPathname());}
  @rmdir($root);
 }
}
