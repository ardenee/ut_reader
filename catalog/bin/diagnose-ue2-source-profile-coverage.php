#!/usr/bin/env php
<?php
/**
 * READ ONLY: distinguish validated UE2 package-file structure from the
 * narrower set of exact producer-version VerifyImport profiles. It does not
 * claim other versions are malformed or permanently incompatible.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(2);}
require_once dirname(__DIR__).'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue2VerifyImportSourceProfile;

$options=getopt('',['game-id::','db-host::','top::','help']);
if(isset($options['help'])){
    echo "Usage: php catalog/bin/diagnose-ue2-source-profile-coverage.php [--game-id=2|4|5] [--db-host=HOST] [--top=20]\n";
    echo "Source coverage, not parser acceptance. READ ONLY. No metadata change or reimport.\n";
    exit(0);
}
$gameId=(int)($options['game-id']??0);
if($gameId!==0&&!in_array($gameId,[2,4,5],true)){
    fwrite(STDERR,'Supported game IDs are 2 (Unreal II), 4 (UT2003), 5 (UT2004).'.PHP_EOL);
    exit(2);
}
$config=catalog_config();
if(isset($options['db-host']))$config['db']['host']=(string)$options['db-host'];
$top=max(1,min(500,(int)($options['top']??20)));
$db=catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try{
    $sql='SELECT game_id, package_version, licensee_version, COUNT(*) AS files
          FROM ue_files WHERE scan_status=? AND game_id IN (2,4,5)';
    $params=['verified'];
    if($gameId!==0){$sql.=' AND game_id=?';$params[]=$gameId;}
    $sql.=' GROUP BY game_id,package_version,licensee_version';
    $query=$db->prepare($sql);
    $query->execute($params);
    $rows=$query->fetchAll(PDO::FETCH_ASSOC);
    $out=['ok'=>true,'mode'=>'READ_ONLY','profile_definition'=>'exact_selected_game_source_VerifyImport',
       'semantics'=>'absence of a profile is NOT a parser failure and does not disprove later-loader backward compatibility',
       'games'=>[]];
    foreach($rows as $row){
        $id=(int)$row['game_id'];$version=(int)$row['package_version'];
        $lic=(int)$row['licensee_version'];$files=(int)$row['files'];
        if(!isset($out['games'][$id])){
            $out['games'][$id]=['name'=>match($id){2=>'Unreal II',4=>'UT2003',5=>'UT2004'},
                'verified'=>0,'exact_game_profile'=>0,'no_exact_game_profile'=>0,'profiles'=>[],
                'unattributed_versions'=>[]];
        }
        $g=&$out['games'][$id];$g['verified']+=$files;
        $profile=Uedb5Ue2VerifyImportSourceProfile::fromGameVersion($id,$version,$lic);
        if($profile!==null){
            $g['exact_game_profile']+=$files;
            $g['profiles'][$profile]=($g['profiles'][$profile]??0)+$files;
        }else{
            $g['no_exact_game_profile']+=$files;
            $g['unattributed_versions'][]=['engine_version'=>$version,'licensee_version'=>$lic,'files'=>$files];
        }
        unset($g);
    }
    foreach($out['games'] as &$g){
        usort($g['unattributed_versions'],static fn(array $a,array $b):int=>$b['files']<=>$a['files']);
        $g['total_unattributed_version_groups']=count($g['unattributed_versions']);
        $g['unattributed_versions']=array_slice($g['unattributed_versions'],0,$top);
    }unset($g);
    $db->rollBack();
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(0);
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
