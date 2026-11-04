#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(2);
require_once dirname(__DIR__).'/lib/CatalogSupportCore.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
$opts=getopt('',['game:','limit:','after-id::','file-id::']);
$game=(int)($opts['game']??0);
$limit=max(1,min(500,(int)($opts['limit']??10)));
$after=max(0,(int)($opts['after-id']??0));
$fileId=max(0,(int)($opts['file-id']??0));
if($game<1){fwrite(STDERR,"Usage: php backfill-zen-exam-projection.php --game=14 --limit=50 [--after-id=...] [--file-id=...]\n");exit(2);}
$config=catalog_config();$db=catalog_db($config);
$reader=new Uedb5MetadataReader((string)$config['storage_path']);
$projector=new PdoZenExamProjection($db);
$params=[$game,$after];
$condition='f.game_id=? AND f.id>?';
if($fileId>0){$condition.=' AND f.id=?';$params[]=$fileId;}
$q=$db->prepare('SELECT f.id,f.game_id,v.payload_sha256 '
    .'FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
    .'JOIN ue_iostore_packages m ON m.file_id=f.id '
    .'WHERE '.$condition.' AND f.scan_status="verified" AND v.package_family="zen-iostore" '
    .'AND v.format_version=5 AND m.publication_status="published" '
    .'AND NOT EXISTS (SELECT 1 FROM ue_uedb5_zen_exam_files e '
    .'WHERE e.file_id=f.id AND e.source_sha256=v.payload_sha256) '
    .'ORDER BY f.id LIMIT '.$limit);
$q->execute($params);
$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
$start=hrtime(true);$total=0;$processed=0;
foreach($rows as $file){
 $id=(int)$file['id'];
 $sha=(string)$file['payload_sha256'];
 // Canonical immutable .uedb5 verified once during the backfill, never in the
 // HTTP rendering path. A mismatching source aborts without SQL changes.
 $reader->verify($game,$id,$sha);
 $snapshot=$reader->snapshot($game,$id);
 $db->beginTransaction();
 try{
  $projector->publish($snapshot,$sha);
  $db->commit();
 }catch(Throwable $e){
  if($db->inTransaction())$db->rollBack();
  throw $e;
 }
 $processed++;$total+=array_sum(array_map('count',array_intersect_key(
   (array)$snapshot['sections'],array_flip(['name_map','imports','exports']))));
 echo json_encode(['file_id'=>$id,'rows_indexed'=>$total,'ok'=>true],JSON_UNESCAPED_SLASHES),PHP_EOL;
}
echo json_encode(['game_id'=>$game,'processed'=>$processed,'rows_indexed'=>$total,
 'last_id'=>(int)($rows[count($rows)-1]['id']??$after),
 'wall_ms'=>round((hrtime(true)-$start)/1e6,2),
 'source_read_policy'=>'verified-once-per-backfill_not_browse'],JSON_UNESCAPED_SLASHES),PHP_EOL;
