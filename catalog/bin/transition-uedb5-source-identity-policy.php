#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassicSourceIdentityImpactQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe5ClassicVerifyImportImpactQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe5ZenDependencyImpactQuery;

const OLD_POLICIES=['uedb5-dependency-pass-v1','uedb5-dependency-pass-v2','uedb5-dependency-pass-v3','uedb5-dependency-pass-v4','uedb5-dependency-pass-v5','uedb5-dependency-pass-v6','uedb5-dependency-pass-v7','uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10'];
$o=getopt('',['game-id::','apply','rebuild-impacted','rebuild-v4-impacted','reparse-pass1-impacted','limit::']);
$gid=max(0,(int)($o['game-id']??0));
$apply=isset($o['apply']);
$rebuild=isset($o['rebuild-impacted']);
$rebuildV4=isset($o['rebuild-v4-impacted']);
$reparsePass1=isset($o['reparse-pass1-impacted']);
$limit=max(1,min(100000,(int)($o['limit']??1000)));
if(($rebuild||$rebuildV4||$reparsePass1)&&!$apply){fwrite(STDERR,"Rebuild/reparse options require --apply.\n");exit(1);}

$app=catalog_bootstrap();$db=$app->db;
$new=Uedb5GameDependencyPassService::DEPENDENCY_POLICY;
if($new!=='uedb5-dependency-pass-v11')throw new RuntimeException('Combined source/UE1/UE2/UE3/UE4/UE5-classic/UE5-Zen transition requires dependency policy v11.');
$tables=['ue_uedb5_migration_status','ue_uedb5_files','ue_uedb5_provider_keys','ue_uedb5_dependency_edges','ue_uedb5_dependency_packages','ue_dependency_links','ue_terms','ue_name_lookup','ue_file_package_aliases','ue_games','ue_game_profiles','ue_file_metadata','ue_export_path_lookup'];
$check=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
foreach($tables as$t){$check->execute([$t]);if((int)$check->fetchColumn()!==1){echo json_encode(['ok'=>false,'missing_table'=>$t,'error'=>'Run catalog/bin/migrate.php migrate before the v11 dependency transition.']),PHP_EOL;exit(1);}}

$q=new PdoClassicSourceIdentityImpactQuery($db);
$current=$q->currentOldPolicyFiles($gid);
$currentIds=array_map(static fn(array$r):int=>(int)$r['file_id'],$current);
$identityCandidateIds=array_values(array_map(
    static fn(array$r):int=>(int)$r['file_id'],
    array_filter($current,static fn(array$r):bool=>(string)$r['dependency_policy']!=='uedb5-dependency-pass-v5')
));
$identity=$q->run($gid,$identityCandidateIds);
$ue1=(new PdoUe1VerifyImportImpactQuery($db))->run($currentIds);
$ue2=(new PdoUe2VerifyImportImpactQuery($db))->run($currentIds);
$ue3=(new PdoUe3VerifyImportImpactQuery($db))->run($currentIds);
$ue4=(new PdoUe4VerifyImportImpactQuery($db))->run($currentIds);
$ue5=(new PdoUe5ClassicVerifyImportImpactQuery($db))->run($currentIds);
$zen=(new PdoUe5ZenDependencyImpactQuery($db))->run($currentIds);
$identityReasons=(array)$identity['reasons_by_file'];
$ue1Reasons=(array)$ue1['reasons_by_file'];
$ue2Reasons=(array)$ue2['reasons_by_file'];
$ue3Reasons=(array)$ue3['reasons_by_file'];
$ue4Reasons=(array)$ue4['reasons_by_file'];
$ue5Reasons=(array)$ue5['reasons_by_file'];
$zenReasons=(array)$zen['reasons_by_file'];

$impacted=[];$unaffected=[];$v4Impacted=[];$pass1Required=[];
foreach($current as$r){
    $fid=(int)$r['file_id'];$policy=(string)$r['dependency_policy'];
    $identityWhy=array_keys((array)($identityReasons[$fid]??[]));
    $identityWithoutAmbiguity=array_values(array_filter($identityWhy,static fn(string$x):bool=>$x!=='provider_environment_ambiguity'));
    $modern=PdoClassicSourceIdentityImpactQuery::isModernClassic((string)$r['engine_key'],(string)$r['package_family']);
    $identityEffective=match($policy){
        'uedb5-dependency-pass-v1'=>$identityWhy,
        'uedb5-dependency-pass-v2'=>$identityWithoutAmbiguity,
        'uedb5-dependency-pass-v3'=>$modern?$identityWithoutAmbiguity:[],
        default=>[],
    };
    $ue1Why=array_keys((array)($ue1Reasons[$fid]??[]));
    $ue1Effective=match($policy){
        'uedb5-dependency-pass-v5'=>array_values(array_intersect($ue1Why,['ut99_mesh_rehack'])),
        'uedb5-dependency-pass-v6','uedb5-dependency-pass-v7','uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10'=>[],
        default=>$ue1Why,
    };
    $ue2Why=array_keys((array)($ue2Reasons[$fid]??[]));
    $ue2Effective=match($policy){
        'uedb5-dependency-pass-v6'=>array_values(array_filter($ue2Why,static fn(string$x):bool=>str_starts_with($x,'ue2_ut2004_'))),
        'uedb5-dependency-pass-v7','uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10'=>[],
        default=>$ue2Why,
    };
    $ue3Why=array_keys((array)($ue3Reasons[$fid]??[]));
    $ue3Effective=match($policy){
        'uedb5-dependency-pass-v7'=>array_values(array_filter($ue3Why,static fn(string$x):bool=>str_starts_with($x,'ue3_ut3_'))),
        'uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10'=>[],
        default=>$ue3Why,
    };
    $ue4Why=array_keys((array)($ue4Reasons[$fid]??[]));
    $ue4Effective=in_array($policy,['uedb5-dependency-pass-v9','uedb5-dependency-pass-v10'],true)?[]:$ue4Why;
    $ue5Why=array_keys((array)($ue5Reasons[$fid]??[]));
    $ue5Effective=$policy==='uedb5-dependency-pass-v10'?[]:$ue5Why;
    $zenWhy=array_keys((array)($zenReasons[$fid]??[]));
    $zenEffective=$zenWhy;
    $why=array_values(array_unique(array_merge($identityEffective,$ue1Effective,$ue2Effective,$ue3Effective,$ue4Effective,$ue5Effective,$zenEffective)));
    $needs=$why!==[];
    $row=['file_id'=>$fid,'game_id'=>(int)$r['game_id'],'engine_key'=>(string)$r['engine_key'],'package_family'=>(string)$r['package_family'],'old_policy'=>$policy,'reasons'=>$why];
    if(array_intersect($why,['ue1_pre50_pass1_reparse','ue2_unreal2_v69_pass1_reparse'])!==[])$pass1Required[]=$row;
    if($needs)$impacted[]=$row;else$unaffected[]=$row;
    $v4Why=array_values(array_diff($why,['ue1_pre50_pass1_reparse','ue2_unreal2_v69_pass1_reparse']));
    if($v4Why!==[]&&in_array((string)$r['engine_key'],['UE1','UE2','UE3','UE4'],true))$v4Impacted[]=$row;
}

$where=$gid>0?' AND s.game_id=?':'';$in=implode(',',array_fill(0,count(OLD_POLICIES),'?'));$args=array_merge(OLD_POLICIES,$gid>0?[$gid]:[]);
$staleSql='SELECT COUNT(*) FROM ue_uedb5_migration_status s JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id WHERE f.scan_status="verified" AND s.dependency_policy IN ('.$in.') AND (s.dependency_payload_sha256 IS NULL OR s.dependency_payload_sha256<>v.payload_sha256)'.$where;
$stale=$db->prepare($staleSql);$stale->execute($args);$staleCount=(int)$stale->fetchColumn();
$combinedCounts=(array)$identity['reason_counts'];foreach([(array)$ue1['reason_counts'],(array)$ue2['reason_counts'],(array)$ue3['reason_counts'],(array)$ue4['reason_counts'],(array)$ue5['reason_counts'],(array)$zen['reason_counts']]as$counts){foreach($counts as$k=>$v)$combinedCounts[$k]=($combinedCounts[$k]??0)+(int)$v;}ksort($combinedCounts,SORT_STRING);
$pre=['old_policies'=>OLD_POLICIES,'new_policy'=>$new,'game_id'=>$gid?:null,'current_old_policy_count'=>count($current),'unaffected_rollforward_count'=>count($unaffected),'impacted_rebuild_count'=>count($impacted),'pass1_reparse_required_count'=>count($pass1Required),'v4_impacted_count'=>count($v4Impacted),'stale_old_payload_count'=>$staleCount,'impact_reason_counts'=>$combinedCounts,'impacted_file_ids_by_game'=>[],'pass1_reparse_file_ids_by_game'=>[]];
foreach($impacted as$r)$pre['impacted_file_ids_by_game'][$r['game_id']][]=$r['file_id'];
foreach($pass1Required as$r)$pre['pass1_reparse_file_ids_by_game'][$r['game_id']][]=$r['file_id'];
if(!$apply){echo json_encode(['ok'=>true,'apply'=>false,'read_only'=>true,'preflight'=>$pre],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit(0);}

// Preserve the v1-v3 exact-FName transition. v4 rows are already exact and simply skip these updates.
$scope=$gid>0?' AND v.game_id='.(int)$gid:'';
$classicCondition='(UPPER(COALESCE(gp.engine_key,"")) IN ("UE1","UE2","UE3","UE4") OR (UPPER(COALESCE(gp.engine_key,""))="UE5" AND v.package_family="classic-linkerload"))';
$select=$db->query('SELECT v.file_id,v.package_name FROM ue_uedb5_files v JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles gp ON gp.id=g.profile_id AND gp.is_active=1 WHERE v.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME.' AND '.$classicCondition.$scope);
$exactRegistrations=$select->fetchAll(PDO::FETCH_ASSOC)?:[];
$db->beginTransaction();
try{
    $u=$db->prepare('UPDATE ue_uedb5_files SET package_key_kind=?,package_key=? WHERE file_id=? AND package_key_kind=?');
    foreach($exactRegistrations as$r)$u->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$r['package_name'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME),(int)$r['file_id'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME]);
    $db->exec('UPDATE ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id SET p.package_key_kind=v.package_key_kind,p.package_key=v.package_key WHERE p.source_kind=1 AND v.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME.($gid>0?' AND p.game_id='.(int)$gid:''));
    $aliasSql='SELECT p.source_id,a.package_name FROM ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id JOIN ue_file_package_aliases a ON a.id=p.source_id AND a.file_id=p.file_id AND a.game_id=p.game_id WHERE p.source_kind=2 AND v.package_key_kind='.Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME.($gid>0?' AND p.game_id='.(int)$gid:'');
    $aliases=$db->query($aliasSql)->fetchAll(PDO::FETCH_ASSOC)?:[];$ua=$db->prepare('UPDATE ue_uedb5_provider_keys SET package_key_kind=?,package_key=? WHERE source_kind=2 AND source_id=?');
    foreach($aliases as$r)$ua->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$r['package_name'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME),(int)$r['source_id']]);
    $exactUnaffected=array_values(array_filter($unaffected,static fn(array$r):bool=>PdoClassicSourceIdentityImpactQuery::isClassicEngine((string)$r['engine_key'],(string)$r['package_family'])));
    $selPkg=$db->prepare('SELECT file_id,package_key,required_package_name FROM ue_uedb5_dependency_packages WHERE file_id=? AND package_key_kind=?');
    $updEdge=$db->prepare('UPDATE ue_uedb5_dependency_edges SET required_package_key_kind=?,required_package_key=? WHERE file_id=? AND required_package_key_kind=? AND required_package_key=?');
    $updPkg=$db->prepare('UPDATE ue_uedb5_dependency_packages SET package_key_kind=?,package_key=? WHERE file_id=? AND package_key_kind=? AND package_key=?');
    foreach($exactUnaffected as$r){$fid=(int)$r['file_id'];$selPkg->execute([$fid,Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME]);foreach($selPkg->fetchAll(PDO::FETCH_ASSOC)?:[]as$p){$old=(string)$p['package_key'];$newKey=Uedb5SqlProjectionContract::classicPackageKeyBinary((string)$p['required_package_name'],Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME);$updEdge->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,$newKey,$fid,Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,$old]);$updPkg->execute([Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,$newKey,$fid,Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,$old]);}}
    $statusIn=implode(',',array_fill(0,count(OLD_POLICIES),'?'));$us=$db->prepare('UPDATE ue_uedb5_migration_status SET dependency_policy=?,updated_at=UTC_TIMESTAMP() WHERE file_id=? AND dependency_policy IN ('.$statusIn.')');
    foreach($unaffected as$r)$us->execute(array_merge([$new,(int)$r['file_id']],OLD_POLICIES));
    $db->commit();
}catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw$e;}

$pass1Reparsed=[];$pass1Failed=[];$pass1Done=[];
if($reparsePass1){$svc=new Uedb5GameSourceMigrationService($db,catalog_config());foreach(array_slice($pass1Required,0,$limit)as$r){try{$out=$svc->runFile((int)$r['game_id'],(int)$r['file_id'],true);$pass1Reparsed[]=['game_id'=>(int)$r['game_id'],'file_id'=>(int)$r['file_id'],'source_policy'=>(string)($out['result']['source_policy']??'')];$pass1Done[(int)$r['file_id']]=true;}catch(Throwable$e){$pass1Failed[]=['game_id'=>(int)$r['game_id'],'file_id'=>(int)$r['file_id'],'error'=>$e->getMessage()];}}}

$rebuilt=[];$failed=[];$blockedPass1=[];
if($rebuild){$svc=new Uedb5GameDependencyPassService($db,catalog_config());foreach(array_slice($impacted,0,$limit)as$r){$fid=(int)$r['file_id'];$needsPass1=array_intersect((array)$r['reasons'],['ue1_pre50_pass1_reparse','ue2_unreal2_v69_pass1_reparse'])!==[];if($needsPass1&&!isset($pass1Done[$fid])){$blockedPass1[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'reason'=>'pass1_reparse_required'];continue;}try{$out=$svc->runFile((int)$r['game_id'],$fid,true,true);$rebuilt[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'reasons'=>$r['reasons'],'dependency_count'=>(int)($out['result']['dependency_count']??0)];}catch(Throwable$e){$failed[]=['game_id'=>(int)$r['game_id'],'file_id'=>$fid,'error'=>$e->getMessage()];}}}

$v4Rebuilt=[];$v4Failed=[];
if($rebuildV4){$v4=new PdoCatalogDependencyRebuilder($db,catalog_config());$statsGames=[];foreach(array_slice($v4Impacted,0,$limit)as$r){try{$v4->rebuild((int)$r['file_id'],null,0,100,'Rebuilding source-exact V4 dependencies',true);$v4Rebuilt[]=['game_id'=>(int)$r['game_id'],'file_id'=>(int)$r['file_id'],'reasons'=>$r['reasons']];$statsGames[(int)$r['game_id']]=true;}catch(Throwable$e){$v4Failed[]=['game_id'=>(int)$r['game_id'],'file_id'=>(int)$r['file_id'],'error'=>$e->getMessage()];}}foreach(array_keys($statsGames)as$statsGame)(new PdoGameCatalogStats($db))->rebuildGame((int)$statsGame,15);}

$allFailed=array_merge($pass1Failed,$failed,$v4Failed);
$result=['ok'=>$allFailed===[]&&$blockedPass1===[],'apply'=>true,'preflight'=>$pre,'rekeyed_exact_classic_registrations'=>count($exactRegistrations),'rolled_forward_unaffected'=>count($unaffected),'pass1_reparsed'=>$pass1Reparsed,'pass1_failed'=>$pass1Failed,'rebuilt_impacted'=>$rebuilt,'blocked_pass1'=>$blockedPass1,'failed_impacted'=>$failed,'rebuilt_v4_impacted'=>$v4Rebuilt,'failed_v4_impacted'=>$v4Failed,'v4_metadata_reparse_required'=>$pre['pass1_reparse_file_ids_by_game'],'remaining_impacted_not_rebuilt'=>max(0,count($impacted)-count($rebuilt)),'rebuild_limit'=>($rebuild||$rebuildV4||$reparsePass1)?$limit:0];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($result['ok']?0:2);
