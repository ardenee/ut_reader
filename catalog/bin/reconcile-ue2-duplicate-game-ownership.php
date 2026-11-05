#!/usr/bin/env php
<?php
/**
 * Reconcile exact duplicate files wrongly assigned to Unreal II with their
 * already-verified UT2003/UT2004 counterparts.
 *
 * Must be run against the exact preflight SHA-256 manifest. Uses the canonical
 * per-file removal service, which atomically retires V5 projections and the
 * ue_files identity. Legacy source UEDB5 snapshots are backed up separately.
 *
 * Does not change the correct game's verified record or copy bytes between
 * games. It is resumable and stops on any unverified condition.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(2); }
require_once dirname(__DIR__).'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceRemovalService;

$options=getopt('',['manifest:','manifest-sha256:','db-host::','storage-root::','ledger::','limit::','apply']);
$manifestPath=(string)($options['manifest']??'');
$manifestHash=strtolower(trim((string)($options['manifest-sha256']??'')));
if (!is_file($manifestPath) || !preg_match('/^[0-9a-f]{64}$/',$manifestHash)
    || !hash_equals($manifestHash,(string)hash_file('sha256',$manifestPath))) {
    fwrite(STDERR,"Manifest file and exact --manifest-sha256 are required.\n"); exit(2);
}
$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
$records=(array)($manifest['records']??[]);
if (($manifest['format']??'')!=='ue2-provenance-migration-preflight'
    || (int)($manifest['source_game_id']??0)!==2
    || count($records)!==391
    || empty($manifest['all_source_target_sha256_verified'])
    || !empty(array_diff(array_map(static fn(array $row):int=>(int)($row['target_game_id']??0),$records),[4,5]))) {
    throw new RuntimeException('Manifest is not the verified 391-file Unreal II ownership audit.');
}
$config=catalog_config();
if (isset($options['db-host'])) { $config['db']['host']=trim((string)$options['db-host']); }
if (isset($options['storage-root'])) { $config['storage_path']=trim((string)$options['storage-root']); }
if (trim((string)($config['storage_path']??''))==='') { throw new RuntimeException('Storage root must be explicit.'); }
$limit=max(1,min(391,(int)($options['limit']??391)));
$apply=isset($options['apply']);
$db=catalog_db($config);
$lookup=$db->prepare('SELECT * FROM ue_files WHERE id=? LIMIT 1');
$mirror=$db->prepare('SELECT id,status FROM ue_external_mirror_jobs WHERE file_id=? ORDER BY id');
$mirrorMove=$db->prepare('UPDATE ue_external_mirror_jobs SET file_id=? WHERE id=? AND file_id=?');
$linked=$db->prepare('SELECT
 (SELECT COUNT(*) FROM ue_file_feedback WHERE file_id=?) feedback,
 (SELECT COUNT(*) FROM ue_external_download_links WHERE file_id=?) download_links,
 (SELECT COUNT(*) FROM ue_file_locations WHERE file_id=?) locations,
 (SELECT COUNT(*) FROM ue_file_package_aliases WHERE file_id=?) aliases');
$removeService=new CatalogFileMaintenanceRemovalService($db,$config);
$seen=[];$done=0;$missing=0;$errors=0;$processed=0;
$ledgerPath=trim((string)($options['ledger']??(dirname($manifestPath).DIRECTORY_SEPARATOR.'apply-ledger.jsonl')));
$ledger=null;
if($apply){
    $ledger=@fopen($ledgerPath,'ab');
    if(!is_resource($ledger)){throw new RuntimeException('Cannot open durable operator ledger: '.$ledgerPath);}
}
function ownerOfPath(string $path):int {
    $path=str_replace('\\','/',$path);
    if(preg_match('~(?:^|/)[^/]*(?:ut2004|unreal tournament 2004)[^/]*/~i',$path))return 5;
    if(preg_match('~(?:^|/)[^/]*(?:ut2003|unreal tournament 2003)[^/]*/~i',$path))return 4;
    return 0;
}
function ledgerLine($fh,array $item):void {
    if(!is_resource($fh)){return;}
    if(!flock($fh,LOCK_EX)){throw new RuntimeException('Cannot lock durable ledger.');}
    try{
        $data=json_encode(['utc'=>gmdate('c')]+$item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        if(fwrite($fh,$data)!==strlen($data)||!fflush($fh)){throw new RuntimeException('Cannot persist durable ledger.');}
    }finally{flock($fh,LOCK_UN);}
}
$lockName='uedb5-engine-dependency-game-2';
if($apply){
    $s=$db->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lockName]);
    if((int)$s->fetchColumn()!==1){throw new RuntimeException('Cannot acquire exclusive Unreal II dependency publication lock.');}
}
try{
    foreach($records as $entry){
        $id=(int)($entry['source_file_id']??0);
        $targetId=(int)($entry['target_file_id']??0);
        $targetGame=(int)($entry['target_game_id']??0);
        if($id<1||$targetId<1||isset($seen[$id]))throw new RuntimeException('Duplicate or invalid manifest row '.$id);
        $seen[$id]=true;
        $lookup->execute([$id]);$source=$lookup->fetch(PDO::FETCH_ASSOC);
        $lookup->execute([$targetId]);$dest=$lookup->fetch(PDO::FETCH_ASSOC);
        if(!is_array($dest) || (int)$dest['game_id']!==$targetGame || (string)$dest['scan_status']!=='verified'
            || !hash_equals(strtolower((string)($entry['target']['sha1']??'')),strtolower((string)$dest['sha1']))
            || (int)$dest['file_size']!==(int)($entry['physical_size']??-1)) {
            throw new RuntimeException('Verified destination changed: '.$id.' target '.$targetId);
        }
        if($source===false){
            $missing++;
            continue; // previously completed; never recreate an incorrect-game record
        }
        if((int)$source['game_id']!==2 || (string)$source['scan_status']!=='verified'
            || ownerOfPath((string)$source['source_relative_path'])!==$targetGame
            || !hash_equals(strtolower((string)($entry['source']['sha1']??'')),strtolower((string)$source['sha1']))
            || (int)$source['file_size']!==(int)($entry['physical_size']??-1)
            || (string)$source['relative_path']!==(string)($entry['source']['relative_path']??'')
            || (string)$dest['relative_path']!==(string)($entry['target']['relative_path']??'')) {
            throw new RuntimeException('Source identity/provenance changed: '.$id);
        }
        if(!hash_equals(strtolower((string)$source['md5']),strtolower((string)$dest['md5']))
            || !hash_equals(strtolower((string)$source['sha1']),strtolower((string)$dest['sha1']))) {
            throw new RuntimeException('Source and destination file digests diverged: '.$id);
        }
        $linked->execute([$id,$id,$id,$id]);$links=$linked->fetch(PDO::FETCH_ASSOC);
        if(array_sum(array_map('intval',(array)$links))!==0){
            throw new RuntimeException('Non-derived source-linked data requires review for file #'.$id);
        }
        $mirror->execute([$id]);$mirrorRows=$mirror->fetchAll(PDO::FETCH_ASSOC);
        if(!$apply) {
            $done++;
            continue;
        }
        if($processed>=$limit){break;}
        $sourcePath=CatalogFileMaintenanceSupport::storagePath($config,$source);
        $destPath=CatalogFileMaintenanceSupport::storagePath($config,$dest);
        if(!is_file($sourcePath??'')||!is_file($destPath??'')
            || $sourcePath===$destPath
            || !hash_equals(strtolower((string)$entry['physical_sha256']),strtolower((string)hash_file('sha256',$sourcePath)))
            || !hash_equals(strtolower((string)$entry['physical_sha256']),strtolower((string)hash_file('sha256',$destPath)))) {
            throw new RuntimeException('Physical source/destination SHA-256 check failed for '.$id);
        }
        $metadataPath=CatalogFileMaintenanceSupport::uedb5MetadataPath($config,2,$id);
        if(!is_file($metadataPath)||!hash_equals(strtolower((string)$entry['source_uedb5_sha256']),strtolower((string)hash_file('sha256',$metadataPath)))) {
            throw new RuntimeException('Original source metadata changed before deletion: '.$id);
        }
        ledgerLine($ledger,['stage'=>'start','source_file_id'=>$id,'target_file_id'=>$targetId,'target_game_id'=>$targetGame]);
        if($mirrorRows!==[]){
            $db->beginTransaction();
            try{
                foreach($mirrorRows as $job){
                    if(!in_array((string)$job['status'],['queued','failed','completed','cancelled','canceled','succeeded'],true)){
                        throw new RuntimeException('Mirror job is active, refusing source removal '.$id);
                    }
                    $mirrorMove->execute([$targetId,(int)$job['id'],$id]);
                    if($mirrorMove->rowCount()!==1)throw new RuntimeException('Mirror job reassignment failed '.$job['id']);
                }
                $db->commit();
            }catch(Throwable $exception){
                if($db->inTransaction())$db->rollBack();
                throw $exception;
            }
        }
        $result=$removeService->remove($id,null,true);
        if(trim((string)($result['warning']??''))!==''){
            ledgerLine($ledger,['stage'=>'warning','source_file_id'=>$id,'target_file_id'=>$targetId,'warning'=>$result['warning']]);
            throw new RuntimeException('Source removal returned cleanup warnings for file '.$id.': '.$result['warning']);
        }
        $lookup->execute([$id]);
        if($lookup->fetch(PDO::FETCH_ASSOC)!==false || is_file($sourcePath) || is_file($metadataPath)){
            throw new RuntimeException('Post-deletion state check failed for '.$id);
        }
        $processed++;$done++;
        ledgerLine($ledger,['stage'=>'deleted','source_file_id'=>$id,'target_file_id'=>$targetId,'target_game_id'=>$targetGame]);
        if(($processed%20)===0 || $processed===$limit){
            echo 'DUPLICATE_SOURCE_REMOVED '.$processed.' THIS_RUN; '.$missing.' PREVIOUSLY_GONE; LAST_ID='.$id.PHP_EOL;
            fflush(STDOUT);
        }
    }
    echo json_encode(['ok'=>true,'applied'=>$apply,'this_run_deleted'=>$processed,'remaining_for_later'=>391-$processed-$missing,'preexisting_missing'=>$missing,'preview_eligible'=>$done,'manifest_sha256'=>$manifestHash,'ledger'=>$apply?$ledgerPath:null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $error){
    $errors++;
    ledgerLine($ledger,['stage'=>'stopped','error'=>$error->getMessage(),'this_run_deleted'=>$processed]);
    fwrite(STDERR,'RECONCILIATION_STOPPED '.$error->getMessage().PHP_EOL);
    exit(1);
}finally{
    if($apply){
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    if(is_resource($ledger))fclose($ledger);
}
