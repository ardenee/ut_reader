#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ProviderKeyPublisher;

$options=getopt('',['game:','apply','continuous','limit::','progress-every::','preflight']);
$slug=trim((string)($options['game']??''));
if($slug===''){
    fwrite(STDERR,"Usage: php catalog/bin/sync-uedb5-provider-keys.php --game=ut99 [--preflight|--apply --continuous]\n");
    exit(1);
}
$app=catalog_bootstrap();$db=$app->db;
$gameStmt=$db->prepare('SELECT id,name,slug FROM ue_games WHERE slug=? LIMIT 1');
$gameStmt->execute([$slug]);$game=$gameStmt->fetch(PDO::FETCH_ASSOC);
if(!is_array($game)){fwrite(STDERR,"Unknown game slug.\n");exit(1);}
$gameId=(int)$game['id'];
$mismatchWhere='v.game_id=? AND ('
    .'NOT EXISTS (SELECT 1 FROM ue_uedb5_provider_keys p WHERE p.source_kind=1 AND p.source_id=v.file_id AND p.file_id=v.file_id AND p.package_key_kind=v.package_key_kind AND p.package_key=v.package_key) '
    .'OR EXISTS (SELECT 1 FROM ue_file_package_aliases a WHERE a.file_id=v.file_id AND a.game_id=v.game_id AND v.package_key_kind=1 '
    .'AND NOT EXISTS (SELECT 1 FROM ue_uedb5_provider_keys p WHERE p.source_kind=2 AND p.source_id=a.id AND p.file_id=v.file_id)) '
    .'OR EXISTS (SELECT 1 FROM ue_uedb5_provider_keys p WHERE p.file_id=v.file_id AND p.source_kind=2 '
    .'AND NOT EXISTS (SELECT 1 FROM ue_file_package_aliases a WHERE a.id=p.source_id AND a.file_id=v.file_id AND a.game_id=v.game_id))'
    .')';
$count=static function(PDO $db,string $sql,array $args=[]):int{
    $s=$db->prepare($sql);$s->execute($args);return(int)$s->fetchColumn();
};
$preflight=[
    'game'=>$game,
    'staged_files'=>$count($db,'SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=?',[$gameId]),
    'catalog_aliases_for_staged_files'=>$count($db,'SELECT COUNT(*) FROM ue_file_package_aliases a JOIN ue_uedb5_files v ON v.file_id=a.file_id AND v.game_id=a.game_id WHERE v.game_id=?',[$gameId]),
    'projected_alias_keys'=>$count($db,'SELECT COUNT(*) FROM ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id WHERE v.game_id=? AND p.source_kind=2',[$gameId]),
    'files_needing_sync'=>$count($db,'SELECT COUNT(*) FROM ue_uedb5_files v WHERE '.$mismatchWhere,[$gameId]),
];
if(isset($options['preflight'])||!isset($options['apply'])){
    echo json_encode(['ok'=>true,'preflight'=>$preflight],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(0);
}
$limit=max(1,min(5000,(int)($options['limit']??1000)));
$progressEvery=max(1,(int)($options['progress-every']??100));
$continuous=isset($options['continuous']);
$publisher=new PdoUedb5ProviderKeyPublisher($db);
$cursor=0;$processed=0;$failed=0;$failures=[];
do{
    $sql='SELECT v.file_id FROM ue_uedb5_files v WHERE v.file_id>? AND '.$mismatchWhere.' ORDER BY v.file_id LIMIT '.$limit;
    $s=$db->prepare($sql);$s->execute(array_merge([$cursor],[$gameId]));
    $rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
    if($rows===[])break;
    foreach($rows as $row){
        $fileId=(int)$row['file_id'];$cursor=$fileId;$processed++;
        try{
            $countRows=$publisher->publish($fileId);
            if($processed%$progressEvery===0||!$continuous){
                echo json_encode(['status'=>'ok','processed'=>$processed,'file_id'=>$fileId,'provider_keys'=>$countRows],JSON_UNESCAPED_SLASHES),PHP_EOL;
            }
        }catch(Throwable $e){
            $failed++;if(count($failures)<50)$failures[]=['file_id'=>$fileId,'error'=>$e->getMessage()];
            echo json_encode(['status'=>'failed','processed'=>$processed,'file_id'=>$fileId,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES),PHP_EOL;
        }
    }
}while($continuous&&count($rows)===$limit);
$remaining=$count($db,'SELECT COUNT(*) FROM ue_uedb5_files v WHERE '.$mismatchWhere,[$gameId]);
echo json_encode(['ok'=>$failed===0,'summary'=>['game'=>$game,'processed'=>$processed,'failed'=>$failed,'remaining'=>$remaining,'failures'=>$failures]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===0?0:2);
