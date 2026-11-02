#!/usr/bin/env php
<?php
/** Live V5 read-only; all candidate lifecycle writes target session TEMPORARY tables. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CompactTemporaryCandidateStore as Store;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder as Builder;
$db=catalog_bootstrap(false)->db;
$reader=new Reader(catalog_config()['storage_path']);
$store=new Store($db);
$ids=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$select=$db->prepare('SELECT * FROM ue_uedb5_files WHERE file_id=? AND game_id=?');
$results=[];$allPassed=true;
try{
 $store->create();
 foreach($ids as $gameId=>$fileId){
    try{
      $select->execute([$fileId,$gameId]);$registration=$select->fetch(PDO::FETCH_ASSOC);
      if(!is_array($registration))throw new RuntimeException('Missing registration.');
      $snapshot=$reader->snapshot($gameId,$fileId);
      $raw=Builder::build($snapshot,$registration);
      $first=$store->publish($snapshot,$registration);
      $repeat=$store->publish($snapshot,$registration);
      $counts=$store->counts($fileId);
      if($counts['names']!==count($raw['name_candidates']) ||
          $counts['objects']!==count($raw['object_candidates']) ||
          $repeat['names']!==$first['names'])throw new RuntimeException('Non-idempotent row counts.');
      $nameRows=(array)$raw['name_candidates'];
      $searchKeys=(array)$raw['search_keys'];
      $checked=0;
      if($nameRows){
        $finger=bin2hex((string)$nameRows[0]['name_key_fingerprint']);
        foreach($searchKeys as $key) if(bin2hex((string)$key['fingerprint'])===$finger){
           $matches=$store->exactNames((string)$key['normalized_text']);
           if(!in_array($fileId,array_map('intval',array_column($matches,'file_id')),true))
             throw new RuntimeException('Exact-name lookup missed published file.');
           $checked++;break;
        }
      }
      if($checked!==1)throw new RuntimeException('Could not exercise exact FName lookup.');
      $store->deleteFile($fileId);
      if($store->counts($fileId)!==['names'=>0,'objects'=>0])
         throw new RuntimeException('File deletion left compact rows.');
      $store->publish($snapshot,$registration);
      if($store->counts($fileId)!==$counts)
         throw new RuntimeException('Republish-after-delete mismatch.');
      $results[]=['game_id'=>$gameId,'file_id'=>$fileId,'ok'=>true,
          'names'=>$counts['names'],'objects'=>$counts['objects']];
    }catch(Throwable $e){$allPassed=false;$results[]=['game_id'=>$gameId,'file_id'=>$fileId,'ok'=>false,'error'=>$e->getMessage()];}
    finally{$reader->clearCache($gameId,$fileId);}
 }
}catch(Throwable $e){$allPassed=false;$results[]=['ok'=>false,'error'=>$e->getMessage()];}
finally{try{$store->drop();}catch(Throwable $e){$allPassed=false;$results[]=['ok'=>false,'cleanup_error'=>$e->getMessage()];}}
echo json_encode(['ok'=>$allPassed,'session_temporary_tables_only'=>true,
 'games_tested'=>count($ids),'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($allPassed?0:2);
