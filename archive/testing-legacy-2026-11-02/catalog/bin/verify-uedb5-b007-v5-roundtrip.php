<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter as Writer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
$path=sys_get_temp_dir().'/uedb5-b007-'.bin2hex(random_bytes(4));
$checks=[];$fail=[];
$check=static function(string $id,bool $ok)use(&$checks,&$fail){$checks[$id]=$ok;if(!$ok)$fail[]=$id;};
try{
 $row=['source_section'=>'imports','source_index'=>0,'dependency_kind'=>'PackageImport',
  'dependency_class'=>'hard','hard'=>true,'outcome'=>'unresolved',
  'required_package_id'=>'1122334455667788','required_object_identity'=>'AABBCCDDEEFF0011',
  'selected_provider_file_id'=>9900702,
  'reason_code'=>'public_export_hash_runtime_collision_ambiguous',
  'candidate_export_indices'=>[0,1],
  'source_header_first_export'=>['segment'=>'main','index'=>0,'runtime_lookup_authoritative'=>false]];
 $snapshot=['file'=>['id'=>9900701,'game_id'=>7,'package_name'=>'/Game/B007'],
  'package_family'=>'ue5-zen-iostore','source_policy'=>'ue5-5.8.3-zen-iostore-package-v1',
  'sections'=>['dependency_results'=>[$row]]];
 $written=(new Writer($path))->write($snapshot);
 $read=(new Reader($path))->snapshot(7,9900701);
 $restored=(array)($read['sections']['dependency_results'][0]??[]);
 $check('b007_writes_real_verified_v5_container',($written['format_version']??null)!==null);
 $check('b007_v5_preserves_collision_provenance',
  ($restored['source_header_first_export']??null)===$row['source_header_first_export']
  &&($restored['candidate_export_indices']??null)===[0,1]);
 $check('b007_v5_preserves_exact_key_and_unresolved',
  ($restored['required_package_id']??null)===$row['required_package_id']
  &&($restored['required_object_identity']??null)===$row['required_object_identity']
  &&($restored['outcome']??null)==='unresolved');
}finally{
 if(is_dir($path)){
  $all=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($all as $item){if($item->isDir())@rmdir($item->getPathname());else @unlink($item->getPathname());}
  @rmdir($path);
 }
}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,'temporary_v5_only'=>true],JSON_PRETTY_PRINT),PHP_EOL;
exit($fail===[]?0:1);
