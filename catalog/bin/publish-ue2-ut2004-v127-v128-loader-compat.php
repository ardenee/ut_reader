#!/usr/bin/env php
<?php
/**
 * Scoped UE2 Pass-2 republication for UT2004 *v127/lic29* files already
 * source-staged under the v128 loader. Reads existing UEDB5, never reparses
 * originals or makes UEDB5 backups. Do not run on DEV.
 *
 * PHP --apply --after-id=N --limit=N permits manual, durable cursor restarts.
 * This is not a generic version-policy migration or runtime mount inference.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(2); }
$root=realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2004SnapshotBuilder;

$opts=getopt('', ['apply','limit::','after-id::','progress-every::','db-host::','help']);
if(isset($opts['help'])){
    echo "Preview (on PRD): php catalog/bin/publish-ue2-ut2004-v127-v128-loader-compat.php\n";
    echo "Apply (on PRD):   php catalog/bin/publish-ue2-ut2004-v127-v128-loader-compat.php --apply\n";
    echo "Resume:           --apply --after-id=LAST_SUCCESSFUL_ID\n";
    echo "Optional:         --limit=500 --progress-every=100\n";
    echo "Only UT2004 game 5, v127/licensee29, existing v128-loader-policy metadata. No file rescans.\n";
    exit(0);
}
$apply=isset($opts['apply']);
$limit=max(1,min(20000,(int)($opts['limit']??20000)));
$after=max(0,(int)($opts['after-id']??0));
$progressEvery=max(1,min(1000,(int)($opts['progress-every']??100)));
if($apply && strcasecmp((string)gethostname(),'Lelly-pc')!==0) {
    fwrite(STDERR,"Applying the scoped UT2004 publisher is permitted only locally on PRD Lelly-pc.\n");
    exit(2);
}
$config=catalog_config();
if(isset($opts['db-host'])){
    if($apply){fwrite(STDERR,"An explicit db-host override is preview-only and cannot be combined with --apply.\\n");exit(2);}
    $config['db']['host']=(string)$opts['db-host'];
}
$db=catalog_db($config);
$service=new Uedb5GameDependencyPassService($db,$config);
$preflight=$service->preflight(5);
if(($preflight['pass2_ready']??false)!==true
    || (int)($preflight['missing_staged_count']??-1)!==0){
    fwrite(STDERR,json_encode(['ok'=>false,'stage'=>'preflight','data'=>$preflight],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
$policy=Uedb5Ut2004SnapshotBuilder::POLICY_V128;
$where='FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
    .'WHERE f.game_id=5 AND f.scan_status="verified" '
    .'AND f.package_version=127 AND f.licensee_version=29 '
    .'AND v.source_policy=? AND f.id>?';
$count=$db->prepare('SELECT COUNT(*) '.$where);
$count->execute([$policy,$after]);$eligible=(int)$count->fetchColumn();
$all=$db->prepare('SELECT COUNT(*) '.str_replace(' AND f.id>?','',$where));
$all->execute([$policy]);$total=(int)$all->fetchColumn();
if(!$apply){
    echo json_encode([
        'ok'=>true,'mode'=>'READ_ONLY_PREVIEW','game_id'=>5,'version'=>127,'licensee'=>29,
        'source_loader_policy'=>$policy,'expected_original_count_in_scope'=>10312,
        'observed_total_current'=>$total,'observed_after_cursor'=>$eligible,'cursor'=>$after,
        'configured_limit'=>$limit,'will_process'=>min($limit,$eligible),
        'scope'=>'selected UE2 v127/29 UEDB5 dependencies only; no Pass1 or original-file rescan',
        'run'=>'php catalog/bin/publish-ue2-ut2004-v127-v128-loader-compat.php --apply'
    ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(0);
}
if($total!==10312){
    fwrite(STDERR,json_encode([
        'ok'=>false,'error'=>'Original known 10,312-file scope has changed; stop and re-audit before publication.',
        'observed_count'=>$total
    ],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
$select=$db->prepare('SELECT f.id '.$where.' ORDER BY f.id ASC LIMIT '.$limit);
$select->execute([$policy,$after]);
$ids=array_map('intval',$select->fetchAll(PDO::FETCH_COLUMN));
$processed=0;$lastSuccessful=$after;
$began=microtime(true);
foreach($ids as $fileId){
    try{
        $out=$service->runFile(5,$fileId,true,true);
        $result=(array)($out['result']??[]);
        if(!isset($result['dependency_count'], $result['projection']['dependency_edges'])){
            throw new RuntimeException('Targeted UEDB5 Pass2 result is incomplete.');
        }
        $lastSuccessful=$fileId;$processed++;
        if($processed%$progressEvery===0 || $processed===count($ids)){
            echo json_encode([
                'status'=>'progress','completed'=>$processed,'requested'=>count($ids),
                'last_successful_file_id'=>$lastSuccessful,
                'seconds'=>round(microtime(true)-$began,1)
            ],JSON_UNESCAPED_SLASHES),PHP_EOL;
            fflush(STDOUT);
        }
    }catch(Throwable $e){
        fwrite(STDERR,json_encode([
            'ok'=>false,'failed_file_id'=>$fileId,
            'last_successful_file_id'=>$lastSuccessful,
            'completed'=>$processed,'error'=>$e->getMessage(),
            'resume_after_id'=>$lastSuccessful
        ],JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
}
echo json_encode([
    'ok'=>true,'scope'=>'UT2004_v127_licensee29_staged_with_v128_loader_source',
    'completed'=>$processed,'last_successful_file_id'=>$lastSuccessful,
    'expected_total'=>$total,'remaining_after_this_batch'=>max(0,$eligible-$processed),
    'seconds'=>round(microtime(true)-$began,1),
    'mode'=>'TARGETED_PASS2_APPLIED',
    'original_files_rescanned'=>0,'uedb5_backups_created'=>0,
    'recommended_next'=>($eligible>$processed
        ? 'Resume using --apply --after-id='.$lastSuccessful
        : 'Rebuild UT2004 game #5 statistics via catalog/bin/rebuild-game-catalog-stats.php --game-id=5')
],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
