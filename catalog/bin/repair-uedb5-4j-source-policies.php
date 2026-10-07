#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5MigrationStatusRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2003SnapshotBuilder;

$o=getopt('',['game:','apply','summary','after-id::','limit::','storage-root::']);
$slug=strtolower(trim((string)($o['game']??'')));
if(!in_array($slug,['unrealgold','ut99','unreal2','ut2003'],true)){
 fwrite(STDERR,"Usage: php catalog/bin/repair-uedb5-4j-source-policies.php --game=unrealgold|ut99|unreal2|ut2003 [--after-id=N] [--limit=1000] [--apply] [--summary]\n");exit(1);
}
$apply=isset($o['apply']);$after=max(0,(int)($o['after-id']??0));$limit=max(1,min(5000,(int)($o['limit']??1000)));
$app=catalog_bootstrap();$db=$app->db;$cfg=catalog_config();
$storageOverride=trim((string)($o['storage-root']??''));
$storage=rtrim($storageOverride!==''?$storageOverride:(string)($cfg['storage_path']??''),"\\/");
if($storage==='')throw new RuntimeException('Catalog storage_path is required.');
$g=$db->prepare('SELECT id FROM ue_games WHERE slug=? LIMIT 1');$g->execute([$slug]);$gid=(int)($g->fetchColumn()?:0);
if($gid<1)throw new RuntimeException("Game not found: $slug");

$expected=static function(string$slug,int$v,int$lic):string{
 return match($slug){
  'unrealgold'=>$v>69?Uedb5UnrealSnapshotBuilder::POLICY_POST_V69_UNRESOLVED:($v<60?Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY:($v<=68?Uedb5UnrealSnapshotBuilder::POLICY_V224_PARTIAL:Uedb5UnrealSnapshotBuilder::POLICY_V227_PARTIAL)),
  'ut99'=>$v>69?Uedb5Ut99SnapshotBuilder::POLICY_FORWARD_COMPAT:(($v<=68&&$lic===0)?Uedb5Ut99SnapshotBuilder::POLICY_RETAIL:Uedb5Ut99SnapshotBuilder::POLICY_SUPPLEMENTAL),
  'unreal2'=>($v>=60&&$v<=69)?Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000:($v>126?Uedb5Unreal2SnapshotBuilder::POLICY_POST_V126_UNRESOLVED:Uedb5Unreal2SnapshotBuilder::POLICY_V126_GENERIC),
  'ut2003'=>$v>120?Uedb5Ut2003SnapshotBuilder::POLICY_POST_V120_UNRESOLVED:Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY,
 };
};

$s=$db->prepare('SELECT v.file_id,v.source_policy,v.payload_sha256,f.package_version,f.licensee_version,s.dependency_policy,s.dependency_payload_sha256 FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id AND s.game_id=v.game_id WHERE v.game_id=? AND v.file_id>? ORDER BY v.file_id LIMIT '.$limit);
$s->execute([$gid,$after]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
$r=new Uedb5MetadataReader($storage);$eligible=[];$canonical=[];$blocked=[];
foreach($rows as$row){
 $fid=(int)$row['file_id'];$want=$expected($slug,(int)$row['package_version'],(int)$row['licensee_version']);$have=(string)$row['source_policy'];
 if($have===$want){$canonical[]=$fid;continue;}
 try{
  $payload=(string)$row['payload_sha256'];$depPayload=(string)($row['dependency_payload_sha256']??'');$depPolicy=trim((string)($row['dependency_policy']??''));
  if(strlen($payload)!==32||strlen($depPayload)!==32||!hash_equals($payload,$depPayload)||$depPolicy==='')throw new RuntimeException('dependency_checkpoint_not_current_payload');
  $snap=$r->snapshot($gid,$fid);
  if((string)($snap['source_policy']??'')!==$have)throw new RuntimeException('snapshot_registration_policy_mismatch');
  if($slug==='unrealgold'){
   $sum=(array)($snap['sections']['summary'][0]??[]);$v=(int)($sum['package_version']??-1);
   if($v>=0&&$v<50)throw new RuntimeException('pre50_requires_pass1_reparse');
   if($v>=68){
    if(!array_key_exists('generation_count',$sum)||$sum['generation_count']===null)throw new RuntimeException('generation_count_missing_requires_pass1_reparse');
    $gc=(int)$sum['generation_count'];if($gc<0||$gc>64)throw new RuntimeException('generation_count_out_of_range_requires_pass1_reparse');
   }
  }
  $eligible[$fid]=['old_policy'=>$have,'new_policy'=>$want,'dependency_policy'=>$depPolicy];
 }catch(Throwable$e){$blocked[$fid]=$e->getMessage();}
}
$last=$rows!==[]?(int)$rows[array_key_last($rows)]['file_id']:$after;
$out=['ok'=>$blocked===[],'apply'=>$apply,'read_only'=>!$apply,'game'=>$slug,'game_id'=>$gid,'candidate_count'=>count($rows),'eligible_refresh_count'=>count($eligible),'already_canonical_count'=>count($canonical),'blocked_count'=>count($blocked),'after_id'=>$after,'limit'=>$limit,'next_after_id'=>$last];
if(!$apply){
 if(!isset($o['summary'])){$out['eligible']=$eligible;$out['blocked']=$blocked;}
 echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($out['ok']?0:2);
}
$writer=new Uedb5MetadataSnapshotWriter($storage);$reg=new PdoUedb5StagingRegistrationRepository($db,$storage);$status=new PdoUedb5MigrationStatusRepository($db);
$depFingerprint=static function(array$snap):string{
 $data=['schema'=>(string)($snap['section_schemas']['dependency_results']??''),'rows'=>array_values((array)($snap['sections']['dependency_results']??[]))];
 return hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
};
$done=[];$failed=[];
foreach($eligible as$fid=>$meta){
 try{
  $beforeReg=$reg->inspect($gid,$fid);$snap=$r->snapshot($gid,$fid);$beforeDep=$depFingerprint($snap);
  $snap['source_policy']=$meta['new_policy'];$writer->write($snap);$r->clearCache($gid,$fid);
  $written=$r->snapshot($gid,$fid);if((string)($written['source_policy']??'')!==$meta['new_policy'])throw new RuntimeException('source_policy_write_verification_failed');
  if(!hash_equals($beforeDep,$depFingerprint($written)))throw new RuntimeException('dependency_results_changed_during_policy_refresh');
  $db->beginTransaction();
  try{
   $afterReg=$reg->refreshExisting($gid,$fid);
   foreach(['package_key_kind','package_key','package_name','package_family']as$key){
    if((string)($beforeReg[$key]??'')!==(string)($afterReg[$key]??''))throw new RuntimeException('provider_identity_changed_during_policy_refresh:'.$key);
   }
   $newPayload=(string)($afterReg['payload_sha256']??'');if(strlen($newPayload)!==32)throw new RuntimeException('refreshed_payload_hash_invalid');
   $status->markDependencySucceeded($fid,$gid,$newPayload,(string)$meta['dependency_policy']);
   $db->commit();
  }catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}
  $done[]=['file_id'=>$fid,'old_policy'=>$meta['old_policy'],'new_policy'=>$meta['new_policy'],'dependency_policy_preserved'=>$meta['dependency_policy'],'payload_sha256'=>bin2hex($newPayload)];
 }catch(Throwable$e){$failed[]=['file_id'=>$fid,'error'=>$e->getMessage()];}
}
$out['ok']=$failed===[]&&$blocked===[];$out['refreshed_count']=count($done);$out['failed_count']=count($failed);$out['resume_after_id']=$last;
if(!isset($o['summary'])){$out['refreshed']=$done;$out['failed']=$failed;$out['blocked']=$blocked;}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($out['ok']?0:2);
