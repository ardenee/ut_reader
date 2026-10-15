<?php
declare(strict_types=1);
/** Read-only source evidence; isolate recorded V5 provider files and compare rebuilt dependency outcomes. */
if (PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5PhysicalProviderSelector;
$app=catalog_bootstrap(false);
$db=$app->db;$game=2;$file=111139;
$storage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'u5-provider-parity-'.bin2hex(random_bytes(8));
$prod=new Uedb5MetadataReader((string)$app->config['storage_path']);
$reader=new Uedb5MetadataReader($storage);$writer=new Uedb5MetadataSnapshotWriter($storage);
$report=['ok'=>false,'consumer_file_id'=>$file,'game_id'=>$game,'isolated_write_only'=>true];
try{
 $baseline=$prod->snapshot($game,$file);
 $reference=(array)($baseline['sections']['dependency_results']??[]);
 $options=['common_packages'=>(array)($app->config['common_packages']??[])];
 $selectedRows=(new PdoUedb5PhysicalProviderSelector($db,(string)$app->config['storage_path']))->select($game,$file,$options);
 $ids=array_values(array_unique(array_map('intval',array_column(array_filter($selectedRows,static fn($r):bool=>(int)($r['file_id']??0)>0),'file_id'))));
 $writer->write($baseline);
 $selected=$selectedRows;
 foreach($ids as $id){
  $snapshot=$prod->snapshot($game,$id);
  $writer->write($snapshot);
  $prod->clearCache($game,$id);
 }
 $rebuilt=(new Uedb5DependencyRebuilder($reader,$writer))->rebuild($game,$file,$selected,$options);
 $actual=(array)($reader->snapshot($game,$file)['sections']['dependency_results']??[]);
 $refMap=[];$actMap=[];
 foreach($reference as $r){$key=(string)($r['source_section']??'').':'.(int)($r['source_index']??-1);$refMap[$key]=['outcome'=>(string)($r['outcome']??''),'provider'=>(int)($r['selected_provider_file_id']??0),'reason'=>(string)($r['reason_code']??'')];}
 foreach($actual as $r){$key=(string)($r['source_section']??'').':'.(int)($r['source_index']??-1);$actMap[$key]=['outcome'=>(string)($r['outcome']??''),'provider'=>(int)($r['selected_provider_file_id']??0),'reason'=>(string)($r['reason_code']??'')];}
 $different=[];
 foreach(array_unique(array_merge(array_keys($refMap),array_keys($actMap))) as $key){
  if(($refMap[$key]??null)!==($actMap[$key]??null))$different[]=['key'=>$key,'before'=>$refMap[$key]??null,'after'=>$actMap[$key]??null];
 }
 $report+=['selected_provider_ids'=>$ids,'ambiguous_selections'=>count(array_filter($selectedRows,static fn($r):bool=>($r['selection_status']??'')==='ambiguous')),'recorded_dependencies'=>count($reference),
  'rebuilt_dependencies'=>count($actual),'rebuild_outcomes'=>$rebuilt['dependency_outcomes']??null,
  'difference_count'=>count($different),'differences'=>array_slice($different,0,40)];
 $report['ok']=count($reference)===count($actual)&&count($different)===0;
}catch(Throwable $e){$report['error']=$e->getMessage();}
finally{
 if(is_dir($storage)){
  $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($walk as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}
  @rmdir($storage);
 }
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($report['ok']?0:2);
