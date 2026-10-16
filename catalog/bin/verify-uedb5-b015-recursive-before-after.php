<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Current;
$root=dirname(__DIR__,2);
$cmd='git -c '.escapeshellarg('safe.directory='.$root).' -C '.escapeshellarg($root)
 .' show '.escapeshellarg('f6d59d11:catalog/src/Infrastructure/Metadata/Uedb5Ue5ZenDependencyResolver.php');
$proc=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc))throw new RuntimeException('No Git historical process');
$source=stream_get_contents($pipes[1]);fclose($pipes[1]);
$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
$exit=proc_close($proc);
$hash=hash('sha256',$source);
if($exit!==0||$hash!=='3e9131079450ab3c6da4a0f216c6b2d9d1938b1778e916dc9ce7a9e64b94f289'){
 echo json_encode(['ok'=>false,'error'=>'Pinned baseline source absent or modified','source_hash'=>$hash,'git_error'=>$error]),PHP_EOL;exit(1);
}
$source=str_replace('final class Uedb5Ue5ZenDependencyResolver','final class Uedb5Ue5ZenDependencyResolverB0152Before',$source);
$path=sys_get_temp_dir().'/b0152-before-'.bin2hex(random_bytes(6)).'.php';
file_put_contents($path,$source);
try{require $path;}finally{@unlink($path);}
$before='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5Ue5ZenDependencyResolverB0152Before';
$sections=['package_summary'=>[['package_id'=>'AAAAAAAAAAAAAAAA']],
 'imports'=>[],'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
 'soft_package_references'=>[],'dependency_bundle_entries'=>[],
 'imported_package_ids'=>[['index'=>0,'package_id'=>'1111111111111111']],
 'optional_segment'=>[['imports'=>[],'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
 'soft_package_references'=>[],'dependency_bundle_entries'=>[],
 'imported_package_ids'=>[['index'=>0,'package_id'=>'2222222222222222']]]]];
$consumer=['package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,'sections'=>$sections];
$old=$before::resolve($consumer,[]);
$new=Current::resolve($consumer,[]);
$kindNames=array_values(array_map(static fn(array $r):string=>(string)($r['source_section']??''),$new));
$checks=[
 'baseline_drops_ordinary_imported_package_ids'=>$old===[],
 'corrected_emits_main_package_without_object_imports'=>in_array('imported_package_ids',$kindNames,true),
 'corrected_emits_optional_package_without_object_imports'=>in_array('optional_segment_imported_package_ids',$kindNames,true),
 'corrected_never_invents_runtime_schedule'=>count(array_filter($new,
  static fn(array $r):bool=>($r['runtime_schedule_authoritative']??true)!==false))===0
];
echo json_encode(['ok'=>!in_array(false,$checks,true),'baseline_revision'=>'f6d59d11',
 'checks'=>$checks,'baseline_rows'=>count($old),'fixed_rows'=>count($new)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
