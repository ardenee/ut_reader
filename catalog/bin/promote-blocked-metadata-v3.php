#!/usr/bin/env php
<?php
/**
 * Promote already verified .v3-stage containers and their SQL projections.
 *
 * Run only after the v3 application code has been deployed. Each file is
 * reparsed to rebuild the exact snapshot/projections, then the normal atomic
 * writer publishes the same v3 semantics. A staged sidecar is required and its
 * source MD5 must still match the catalog row.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }
$root=dirname(__DIR__); require_once $root.'/lib/CatalogSupport.php'; require_once $root.'/lib/CatalogFileMaintenance.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

$options=getopt('',['apply','all','limit:','after-id:','stop-on-error']);
$apply=array_key_exists('apply',$options); $all=array_key_exists('all',$options);
$limit=max(1,min(10000,(int)($options['limit']??500))); $after=max(0,(int)($options['after-id']??0));
$stop=array_key_exists('stop-on-error',$options);
$config=catalog_config(); $db=catalog_db($config); $storageRoot=trim((string)($config['storage_path']??''));
if (BlockedCompressedMetadataContainer::FORMAT_VERSION!==3) throw new RuntimeException('Format-3 application code is required.');
$stmt=$db->prepare('SELECT f.* FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id WHERE f.scan_status="verified" AND m.format_version=2 AND f.id>? ORDER BY f.id'.($all?'':' LIMIT '.$limit));
$stmt->execute([$after]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
if(!$apply){echo json_encode(['ok'=>true,'dry_run'=>true,'selected'=>count($rows)],JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
$done=0;$failed=0;$last=$after;$errors=[];
foreach($rows as $n=>$file){
 $id=(int)$file['id']; $live=BlockedCompressedMetadataContainer::path($storageRoot,(int)$file['game_id'],$id); $stage=$live.'.v3-stage'; $side=$stage.'.json';
 fwrite(STDOUT,'['.($n+1).'/'.count($rows)."] #{$id} ... ");
 try{
  if(!is_file($stage)||!is_file($side)) throw new RuntimeException('Staged v3 container or sidecar is missing.');
  $meta=json_decode((string)file_get_contents($side),true,512,JSON_THROW_ON_ERROR);
  if((int)($meta['file_id']??0)!==$id || (int)($meta['format_version']??0)!==3) throw new RuntimeException('Staged sidecar identity/version mismatch.');
  if(!hash_equals(strtolower((string)$file['md5']),strtolower((string)($meta['source_md5']??'')))) throw new RuntimeException('Catalog source identity changed after staging.');
  $sha=hex2bin((string)($meta['payload_sha256_hex']??'')); if($sha===false||strlen($sha)!==32) throw new RuntimeException('Staged SHA-256 is invalid.');
  BlockedCompressedMetadataContainer::verifyFile($stage,$id,$sha,3);
  $service=new CatalogFileMaintenanceActionService($db,$config,null);
  $service->execute('sync_reimport',['file_id'=>$id,'game_id'=>(int)$file['game_id'],'package_name'=>(string)$file['package_name'],'md5'=>(string)$file['md5'],'package_guid'=>(string)($file['package_guid']??'')]);
  $v=(int)(catalog_one($db,'SELECT format_version FROM ue_file_metadata WHERE file_id=?',[$id])['format_version']??0);
  if($v!==3) throw new RuntimeException('Promotion did not publish format 3.');
  @unlink($stage); @unlink($side); $done++;$last=$id;fwrite(STDOUT,"v3 LIVE\n");
 }catch(Throwable $e){$failed++;$errors[]=['file_id'=>$id,'error'=>$e->getMessage()];fwrite(STDOUT,'FAILED: '.$e->getMessage()."\n");if($stop)break;}
}
echo json_encode(['ok'=>$failed===0,'selected'=>count($rows),'completed'=>$done,'failed'=>$failed,'last_completed_id'=>$last,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===0?0:3);
