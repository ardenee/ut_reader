<?php
declare(strict_types=1);
/** Use resolved external provider identities from current V5 edges to test real resolver on isolated files. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyRebuilder;
$app=catalog_bootstrap(false);$db=$app->db;
$prod=new Uedb5MetadataReader((string)$app->config['storage_path']);
$storage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-external-provider-'.bin2hex(random_bytes(8));
$writer=new Uedb5MetadataSnapshotWriter($storage);
$reader=new Uedb5MetadataReader($storage);
$rebuilder=new Uedb5DependencyRebuilder($reader,$writer);
$candidates=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$probe=$db->prepare('SELECT DISTINCT d.resolved_file_id FROM ue_uedb5_dependency_edges d JOIN ue_uedb5_files v ON v.file_id=d.resolved_file_id AND v.game_id=? WHERE d.file_id=? AND d.resolved_file_id IS NOT NULL AND d.resolved_file_id<>? LIMIT 1');
$report=[];$errors=[];
try{
 foreach($candidates as $game=>$consumerId){
  $probe->execute([$game,$consumerId,$consumerId]);
  $providerId=(int)$probe->fetchColumn();
  if($providerId<1){$report[]=['game_id'=>$game,'consumer_id'=>$consumerId,'status'=>'no_external_resolved_provider_for_sample'];continue;}
  try{
   $consumer=$prod->snapshot($game,$consumerId);
   $provider=$prod->snapshot($game,$providerId);
   $writer->write($consumer);$writer->write($provider);
   $selection=[['game_id'=>$game,'file_id'=>$providerId,'package_name'=>(string)($provider['file']['package_name']??'')]];
   $rebuilder->rebuild($game,$consumerId,$selection);
   $rebuilt=$reader->snapshot($game,$consumerId);
   $matches=array_values(array_filter((array)($rebuilt['sections']['dependency_results']??[]),
     static fn($r):bool=>(int)($r['selected_provider_file_id']??0)===$providerId
      && (string)($r['outcome']??'')==='resolved'));
   $allProviderRows=array_values(array_filter((array)($rebuilt['sections']['dependency_results']??[]),
     static fn($r):bool=>(int)($r['selected_provider_file_id']??0)===$providerId));
   $report[]=['game_id'=>$game,'consumer_id'=>$consumerId,'provider_id'=>$providerId,
    'resolved_using_provider'=>count($matches),
    'provider_outcomes'=>array_count_values(array_map(static fn($r):string=>(string)($r['outcome']??''),$allProviderRows)),
    'status'=>count($allProviderRows)>0?'provider_selected':'no_matching_provider'];
  }catch(Throwable $e){$errors[]=['game_id'=>$game,'error'=>$e->getMessage()];}
  finally{$prod->clearCache($game,$consumerId);$prod->clearCache($game,$providerId);$reader->clearCache($game,$consumerId);$reader->clearCache($game,$providerId);}
 }
}catch(Throwable $e){$errors[]=['setup'=>$e->getMessage()];}
finally{if(is_dir($storage)){$walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($walk as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}@rmdir($storage);}}
$selectedCount=count(array_filter($report,static fn($r):bool=>($r['status']??'')==='provider_selected'));
$ok=$errors===[]&&$selectedCount>0;
echo json_encode(['ok'=>$ok,'isolated_v5_only'=>true,'provider_selected_games'=>$selectedCount,
 'complete_external_resolution_proven'=>false,'results'=>$report,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
