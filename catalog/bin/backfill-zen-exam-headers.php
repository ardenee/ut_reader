<?php
declare(strict_types=1);
if(PHP_SAPI !== 'cli')exit(2);
require_once dirname(__DIR__).'/lib/CatalogSupportCore.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;

$o=getopt('',['game:','limit:','after-id::','file-id::','shard::','shards::']);
$game=(int)($o['game']??0);
$limit=max(1,min(2000,(int)($o['limit']??100)));
$after=max(0,(int)($o['after-id']??0));
$file=max(0,(int)($o['file-id']??0));
$shards=max(1,min(4,(int)($o['shards']??1)));
$shard=(int)($o['shard']??0);
if($shard<0||$shard>=$shards)throw new InvalidArgumentException('Invalid shard index.');
if($game<1){fwrite(STDERR,"Usage: php backfill-zen-exam-headers.php --game=14 --limit=100 [--after-id=..] [--file-id=..]\n");exit(2);}
$config=catalog_config();
$db=catalog_db($config);
$reader=new Uedb5MetadataReader((string)$config['storage_path']);
$projection=new PdoZenExamProjection($db);
$params=[$game,$after];
$filter='f.game_id=? AND f.id>?';
if($file>0){$filter.=' AND f.id=?';$params[]=$file;}
if($shards>1){$filter.=' AND MOD(f.id,?)=?';$params[]=$shards;$params[]=$shard;}
$sql='SELECT f.id,v.payload_sha256 FROM ue_files f '
 .'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
 .'JOIN ue_uedb5_zen_exam_files e ON e.file_id=f.id AND e.source_sha256=v.payload_sha256 '
 .'WHERE '.$filter.' AND f.scan_status="verified" AND v.format_version=5 AND v.package_family="zen-iostore" '
 .'AND NOT EXISTS (SELECT 1 FROM ue_uedb5_zen_exam_rows r '
 .'WHERE r.file_id=f.id AND r.section="header" AND r.row_index=0) '
 .'ORDER BY f.id LIMIT '.$limit;
$stmt=$db->prepare($sql);
$stmt->execute($params);
$files=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
$completed=0;$last=$after;
$start=hrtime(true);
// A bounded batch is atomic: failures roll back only this call's header rows.
// The canonical snapshots are verified individually against the retained SHA.
$db->beginTransaction();
try{
 foreach($files as $record){
  $id=(int)$record['id'];$sha=(string)$record['payload_sha256'];
  $reader->verify($game,$id,$sha);
  $snapshot=['sections'=>['package_summary'=>$reader->page($game,$id,'package_summary',0,1)]];
  $projection->publishHeaderOnly($id,$snapshot,$sha);
  $completed++;$last=$id;
 }
 $db->commit();
}catch(Throwable $error){
 if($db->inTransaction())$db->rollBack();
 throw $error;
}
echo json_encode(['game_id'=>$game,'completed'=>$completed,'last_id'=>$last,'ms'=>round((hrtime(true)-$start)/1e6,2),
 'source_policy'=>'sha_verified_canonical_uedb5_metadata_once_per_header'],JSON_UNESCAPED_SLASHES),PHP_EOL;
