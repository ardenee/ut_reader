#!/usr/bin/env php
<?php
/** Read-only production V5; writes confined to session TEMPORARY tables.
 * Tests exact object identity, NULL-safe public hashes, and transaction rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CompactTemporaryCandidateStore as Store;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder as Builder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as K;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash as H;
$db=catalog_bootstrap(false)->db;
$reader=new Reader(catalog_config()['storage_path']);
$store=new Store($db);
$ids=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$select=$db->prepare('SELECT * FROM ue_uedb5_files WHERE file_id=? AND game_id=?');
$results=[];$allPassed=true;
$checkedObjects=0;$rollbackChecks=0;
try{
 $store->create();
 foreach($ids as $gameId=>$fileId){
  try{
   $select->execute([$fileId,$gameId]);$registration=$select->fetch(PDO::FETCH_ASSOC);
   if(!is_array($registration))throw new RuntimeException('V5 registration unavailable.');
   $snapshot=$reader->snapshot($gameId,$fileId);
   $projection=Builder::build($snapshot,$registration);
   $store->publish($snapshot,$registration);
   $before=$store->counts($fileId);
   $objectRows=(array)$projection['object_candidates'];
   if(!$objectRows)throw new RuntimeException('No object candidate to test.');
   $tested=0;
   foreach(array_slice($objectRows,0,15) as $o){
     $kind=(int)$o['object_kind'];$idx=(int)$o['object_index'];
     $section=$kind===K::OBJECT_KIND_CELL_EXPORT?'cell_exports':'exports';
     $found=null;
     foreach((array)($snapshot['sections'][$section]??[]) as $ordinal=>$row)
       if((int)($row['index']??$ordinal)===$idx){$found=(array)$row;break;}
     if($found===null)throw new RuntimeException('Object index absent from V5 snapshot.');
     $value=$found['object_name']??$found['objectName']??null;
     $text=is_array($value)?trim((string)($value['text']??$value['base_text']??'')):
         trim((string)($found['objectNameText']??$value??''));
     if(H::nameKey($text)==='')throw new RuntimeException('Empty object identity.');
     $matches=$store->exactObjects($text);
     $matched=false;
     foreach($matches as $m)if((int)$m['file_id']===$fileId &&
       (int)$m['object_kind']===$kind && (int)$m['object_index']===$idx &&
       $m['public_export_hash']===$o['public_export_hash']){$matched=true;break;}
     if(!$matched)throw new RuntimeException('Exact-object candidate mismatch.');
     $tested++;
   }
   // Nested operations must join the caller's transaction, not commit it.
   $db->beginTransaction();
   try{
     $store->deleteFile($fileId);
     if($store->counts($fileId)!==['names'=>0,'objects'=>0])
       throw new RuntimeException('Delete under transaction failed.');
   }finally{if($db->inTransaction())$db->rollBack();}
   if($store->counts($fileId)!==$before)
      throw new RuntimeException('Rollback failed to recover compact rows.');
   $rollbackChecks++;
   $db->beginTransaction();
   try{
     $store->deleteFile($fileId);
     $store->publish($snapshot,$registration);
     if($store->counts($fileId)!==$before)
       throw new RuntimeException('Republish under caller transaction mismatch.');
   }finally{if($db->inTransaction())$db->rollBack();}
   if($store->counts($fileId)!==$before)
      throw new RuntimeException('Republish rollback mismatch.');
   $rollbackChecks++;
   $checkedObjects+=$tested;
   $results[]=['game_id'=>$gameId,'file_id'=>$fileId,'ok'=>true,
     'object_candidates_checked'=>$tested,'rollback_checks'=>2];
  }catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    $allPassed=false;$results[]=['game_id'=>$gameId,'file_id'=>$fileId,
      'ok'=>false,'error'=>$e->getMessage()];
  }finally{$reader->clearCache($gameId,$fileId);}
 }
}catch(Throwable $e){$allPassed=false;$results[]=['ok'=>false,'error'=>$e->getMessage()];}
finally{try{$store->drop();}catch(Throwable $e){$allPassed=false;$results[]=['ok'=>false,'cleanup_error'=>$e->getMessage()];}}
echo json_encode(['ok'=>$allPassed,'session_temporary_tables_only'=>true,
 'games_tested'=>count($ids),'objects_checked'=>$checkedObjects,
 'transaction_rollback_checks'=>$rollbackChecks,'results'=>$results],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($allPassed?0:2);
