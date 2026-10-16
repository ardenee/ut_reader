<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter as Writer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
$checks=[];$fail=[];
$check=static function(string $id,bool $ok)use(&$checks,&$fail):void{
 $checks[$id]=$ok;if(!$ok)$fail[]=$id;
};
$pkg='1122334455667788';$hash='AABBCCDDEEFF0011';
$sections=static fn(string $id,array $imports,array $cells,array $exports):array=>[
 'package_summary'=>[['package_id'=>$id]],'imports'=>$imports,'exports'=>$exports,
 'cell_imports'=>$cells,'cell_exports'=>[],
 'soft_package_references'=>[],'dependency_bundle_entries'=>[]];
$shape=static fn(array $data):array=>[
 'package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,'sections'=>$data];
$import=['type'=>'PackageImport','dependency_class'=>'cell_verse',
 'provider_package_id'=>$pkg,'provider_public_export_hash'=>$hash];
$consumer=$shape($sections('1234567890ABCDEF',[],[$import],[]));
$provider=$shape($sections($pkg,[],[],[
 ['public_export_hash'=>'1010101010101010','filter_flags'=>0]
]));
$provider['sections']['cell_exports']=[
 ['public_export_hash'=>$hash,'cpp_class_info'=>['text'=>'CellA']],
 ['public_export_hash'=>$hash,'cpp_class_info'=>['text'=>'CellB']]];
$ctx=['package_identity_context_authoritative'=>true,'package_identity_rewrites'=>[],
 'package_store_context_authoritative'=>true,'package_redirects'=>[],
 'localized_packages'=>[],'with_editor'=>false];
$rows=Resolver::resolve($consumer,[['provider_id'=>9901602,'snapshot'=>$provider]],$ctx);
$row=(array)($rows[0]??[]);
$check('b016_duplicate_cell_remains_unresolved',
 ($row['outcome']??null)==='unresolved'
 &&($row['reason_code']??null)==='public_cell_export_hash_runtime_collision_ambiguous');
$check('b016_combined_header_index_provenance',
 ($row['source_header_first_cell_export']['local_export_index']??null)===1
 &&($row['source_header_first_cell_export']['runtime_lookup_authoritative']??null)===false);
$dir=sys_get_temp_dir().'/uedb5-b016-'.bin2hex(random_bytes(6));
try{
 $snapshot=$consumer;
 $snapshot['file']=['id'=>9901601,'game_id'=>7,'package_name'=>'/Game/B016'];
 $snapshot['sections']['dependency_results']=$rows;
 $written=(new Writer($dir))->write($snapshot);
 $read=(new Reader($dir))->snapshot(7,9901601);
 $restored=(array)($read['sections']['dependency_results'][0]??[]);
 $check('b016_real_compressed_v5_written_verified',isset($written['payload_sha256']));
 $check('b016_v5_retains_first_cell_index_and_collision',
 ($restored['source_header_first_cell_export']??null)===$row['source_header_first_cell_export']
 &&($restored['candidate_cell_export_indices']??null)===[0,1]
 &&($restored['outcome']??null)==='unresolved');
 $check('b016_v5_retains_exact_cell_package_hash',
 ($restored['required_package_id']??null)===$pkg
 &&($restored['required_object_identity']??null)===$hash);
}finally{
 if(is_dir($dir)){
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($iterator as $item){if($item->isDir())@rmdir($item->getPathname());else @unlink($item->getPathname());}
  @rmdir($dir);
 }
}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
