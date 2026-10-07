#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

$o=getopt('',['manifest:','apply','rebuild-v4','reparse-pass1','progress-every::']);
$manifestPath=trim((string)($o['manifest']??''));
if($manifestPath===''||!is_file($manifestPath)){fwrite(STDERR,"Usage: php catalog/bin/apply-uedb5-v13-impact-manifest.php --manifest=PATH --apply [--rebuild-v4] [--reparse-pass1] [--progress-every=N]\n");exit(1);}
if(!isset($o['apply'])){fwrite(STDERR,"--apply is required. This tool never performs discovery or a dry-run scan.\n");exit(1);}
$rebuildV4=isset($o['rebuild-v4']);$reparsePass1=isset($o['reparse-pass1']);$progressEvery=max(1,(int)($o['progress-every']??1000));
$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
if(!is_array($manifest)||($manifest['schema']??'')!=='uedb5-v13-impact-manifest-v1')throw new RuntimeException('Unsupported v13 impact manifest.');
if(($manifest['new_policy']??'')!=='uedb5-dependency-pass-v13')throw new RuntimeException('Manifest target policy is not v13.');
$oldPolicies=array_values(array_map('strval',(array)($manifest['old_policies']??[])));
if($oldPolicies===[])throw new RuntimeException('Manifest has no old policies.');
$impacted=array_values((array)($manifest['impacted']??[]));
$v4Impacted=array_values((array)($manifest['v4_impacted']??[]));
$pass1Required=array_values((array)($manifest['pass1_required']??[]));
$policyRefresh=array_values((array)($manifest['source_policy_refresh_required']??[]));
$profileReview=array_values((array)($manifest['source_profile_review_required']??[]));
if($policyRefresh!==[]||$profileReview!==[])throw new RuntimeException('Manifest still contains unresolved source-policy/profile prerequisites.');
if($pass1Required!==[]&&!$reparsePass1)throw new RuntimeException('Manifest contains Pass-1 prerequisites; rerun with --reparse-pass1.');
$app=catalog_bootstrap();$db=$app->db;$new=Uedb5GameDependencyPassService::DEPENDENCY_POLICY;
if($new!=='uedb5-dependency-pass-v13')throw new RuntimeException('Runtime dependency policy is not v13.');

$in=implode(',',array_fill(0,count($oldPolicies),'?'));
$st=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_migration_status s JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id WHERE s.dependency_policy IN ('.$in.') AND (s.dependency_payload_sha256 IS NULL OR s.dependency_payload_sha256<>v.payload_sha256)');
$st->execute($oldPolicies);$stale=(int)$st->fetchColumn();
if($stale!==0)throw new RuntimeException('Old-policy rows with stale payload checkpoints remain: '.$stale);
$cnt=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE dependency_policy IN ('.$in.')');$cnt->execute($oldPolicies);$oldCount=(int)$cnt->fetchColumn();
$expectedOld=(int)($manifest['current_old_policy_count']??-1);
if($expectedOld>=0&&$oldCount!==$expectedOld)throw new RuntimeException("Manifest old-policy count mismatch: expected $expectedOld, current $oldCount.");

$db->exec('DROP TEMPORARY TABLE IF EXISTS tmp_uedb5_v13_impacted');
$db->exec('CREATE TEMPORARY TABLE tmp_uedb5_v13_impacted(file_id BIGINT UNSIGNED PRIMARY KEY,game_id BIGINT UNSIGNED NOT NULL) ENGINE=MEMORY');
$ins=$db->prepare('INSERT INTO tmp_uedb5_v13_impacted(file_id,game_id) VALUES(?,?)');
$impactById=[];
foreach($impacted as$r){
 $fid=(int)($r['file_id']??0);$gid=(int)($r['game_id']??0);if($fid<1||$gid<1)throw new RuntimeException('Manifest contains invalid impacted identity.');
 if(isset($impactById[$fid]))throw new RuntimeException('Manifest contains duplicate impacted file #'.$fid);
 $impactById[$fid]=$r;$ins->execute([$fid,$gid]);
}
$chk=$db->prepare('SELECT s.file_id,s.game_id FROM ue_uedb5_migration_status s JOIN tmp_uedb5_v13_impacted i ON i.file_id=s.file_id AND i.game_id=s.game_id WHERE s.dependency_policy NOT IN ('.$in.')');
$chk->execute($oldPolicies);$bad=$chk->fetchAll(PDO::FETCH_ASSOC)?:[];
if($bad!==[])throw new RuntimeException('Manifest impacted rows are no longer in old-policy scope.');

$classicCond='(UPPER(COALESCE(gp.engine_key,"")) IN ("UE1","UE2","UE3","UE4") OR (UPPER(COALESCE(gp.engine_key,""))="UE5" AND v.package_family="classic-linkerload"))';
$rekeySel=$db->query('SELECT v.file_id,v.package_name FROM ue_uedb5_files v JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles gp ON gp.id=g.profile_id AND gp.is_active=1 JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id AND s.game_id=v.game_id WHERE v.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME.' AND '.$classicCond.' AND s.dependency_policy IN ("'.implode('","',array_map(static fn(string$x):string=>str_replace('"','',$x),$oldPolicies)).'")');
$rekeyRows=$rekeySel->fetchAll(PDO::FETCH_ASSOC)?:[];
$u=$db->prepare('UPDATE ue_uedb5_files SET package_key_kind=?,package_key=? WHERE file_id=? AND package_key_kind=?');
foreach($rekeyRows as$r)$u->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$r['package_name'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME),(int)$r['file_id'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME]);
$db->exec('UPDATE ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id AND s.game_id=v.game_id SET p.package_key_kind=v.package_key_kind,p.package_key=v.package_key WHERE p.source_kind=1 AND v.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME.' AND s.dependency_policy IN ("'.implode('","',$oldPolicies).'")');
$aliasSel=$db->query('SELECT p.source_id,a.package_name FROM ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id AND s.game_id=v.game_id JOIN ue_file_package_aliases a ON a.id=p.source_id AND a.file_id=p.file_id AND a.game_id=p.game_id WHERE p.source_kind=2 AND v.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME.' AND s.dependency_policy IN ("'.implode('","',$oldPolicies).'")');
$ua=$db->prepare('UPDATE ue_uedb5_provider_keys SET package_key_kind=?,package_key=? WHERE source_kind=2 AND source_id=?');
foreach($aliasSel as$r)$ua->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$r['package_name'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME),(int)$r['source_id']]);

$pkgSel=$db->query('SELECT p.file_id,p.package_key,p.required_package_name FROM ue_uedb5_dependency_packages p JOIN ue_uedb5_migration_status s ON s.file_id=p.file_id LEFT JOIN tmp_uedb5_v13_impacted i ON i.file_id=p.file_id WHERE p.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME.' AND s.dependency_policy IN ("'.implode('","',$oldPolicies).'") AND i.file_id IS NULL ORDER BY p.file_id');
$updEdge=$db->prepare('UPDATE ue_uedb5_dependency_edges SET required_package_key_kind=?,required_package_key=? WHERE file_id=? AND required_package_key_kind=? AND required_package_key=?');
$updPkg=$db->prepare('UPDATE ue_uedb5_dependency_packages SET package_key_kind=?,package_key=? WHERE file_id=? AND package_key_kind=? AND package_key=?');
$converted=0;
while(($r=$pkgSel->fetch(PDO::FETCH_ASSOC))!==false){
 $old=(string)$r['package_key'];$fid=(int)$r['file_id'];$newKey=Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$r['required_package_name'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME);
 $updEdge->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,$newKey,$fid,Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,$old]);
 $updPkg->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,$newKey,$fid,Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,$old]);
 $converted++;if($converted%$progressEvery===0){echo json_encode(['status'=>'legacy_dependency_keys_converted','count'=>$converted]),PHP_EOL;fflush(STDOUT);}
}

$roll=$db->prepare('UPDATE ue_uedb5_migration_status s JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id LEFT JOIN tmp_uedb5_v13_impacted i ON i.file_id=s.file_id SET s.dependency_policy=?,s.updated_at=UTC_TIMESTAMP() WHERE s.dependency_policy IN ('.$in.') AND s.dependency_payload_sha256=v.payload_sha256 AND i.file_id IS NULL');
$roll->execute(array_merge([$new],$oldPolicies));$rolled=$roll->rowCount();

$pass1Done=[];$pass1Reparsed=[];$pass1Failed=[];
if($pass1Required!==[]){
 $svc1=new Uedb5GameSourceMigrationService($db,catalog_config());
 foreach($pass1Required as$r){$fid=(int)$r['file_id'];try{$out=$svc1->runFile((int)$r['game_id'],$fid,true);$pass1Done[$fid]=true;$pass1Reparsed[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'source_policy'=>(string)($out['result']['source_policy']??'')];}catch(Throwable$e){$pass1Failed[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'error'=>$e->getMessage()];}}
}

$svc=new Uedb5GameDependencyPassService($db,catalog_config());$rebuilt=[];$failed=[];$blocked=[];
foreach($impacted as$r){
 $fid=(int)$r['file_id'];$reasons=(array)($r['reasons']??[]);
 $needsPass1=array_intersect($reasons,['ue1_pre50_pass1_reparse','ue2_unreal2_v69_pass1_reparse','ue4_ut4_bad_v510_pass1_repair_required'])!==[];
 if($needsPass1&&!isset($pass1Done[$fid])){$blocked[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'reason'=>'pass1_reparse_required'];continue;}
 try{$out=$svc->runFile((int)$r['game_id'],$fid,true,true);$rebuilt[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'reasons'=>$reasons,'dependency_count'=>(int)($out['result']['dependency_count']??0)];}
 catch(Throwable$e){$failed[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'error'=>$e->getMessage()];}
}

$v4Rebuilt=[];$v4Failed=[];
if($rebuildV4){
 $v4=new PdoCatalogDependencyRebuilder($db,catalog_config());$stats=[];
 foreach($v4Impacted as$r){try{$v4->rebuild((int)$r['file_id'],null,0,100,'Rebuilding source-exact V4 dependencies',true);$v4Rebuilt[]=['game_id'=>(int)$r['game_id'],'file_id'=>(int)$r['file_id'],'reasons'=>(array)$r['reasons']];$stats[(int)$r['game_id']]=true;}catch(Throwable$e){$v4Failed[]=['game_id'=>(int)$r['game_id'],'file_id'=>(int)$r['file_id'],'error'=>$e->getMessage()];}}
 foreach(array_keys($stats)as$gameId)(new PdoGameCatalogStats($db))->rebuildGame((int)$gameId,15);
}

$remain=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE dependency_policy IN ('.$in.')');$remain->execute($oldPolicies);$remainingOld=(int)$remain->fetchColumn();
$allFailed=array_merge($pass1Failed,$failed,$v4Failed);
$result=[
 'ok'=>$allFailed===[]&&$blocked===[]&&$remainingOld===0,
 'manifest'=>$manifestPath,'manifest_sha256'=>hash_file('sha256',$manifestPath),
 'old_policy_count_at_start'=>$oldCount,'rekeyed_exact_classic_registrations'=>count($rekeyRows),
 'converted_legacy_dependency_package_rows'=>$converted,'rolled_forward_unaffected'=>$rolled,
 'impacted_count'=>count($impacted),'rebuilt_impacted_count'=>count($rebuilt),'failed_impacted'=>$failed,
 'blocked_prerequisites'=>$blocked,'pass1_reparsed'=>$pass1Reparsed,'pass1_failed'=>$pass1Failed,
 'v4_rebuild_requested'=>$rebuildV4,'v4_impacted_count'=>count($v4Impacted),'rebuilt_v4_count'=>count($v4Rebuilt),'failed_v4_impacted'=>$v4Failed,
 'remaining_old_policy_count'=>$remainingOld
];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($result['ok']?0:2);
