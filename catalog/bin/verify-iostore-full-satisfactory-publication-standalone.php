<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStorePackageNameIndexer;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreMemberCatalogPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$identity=$db->query('SELECT @@port AS port,@@datadir AS dir')->fetch();
if((int)$identity['port']!==3339||stripos((string)$identity['dir'],'uedb5-isolated-mysql')===false)
 throw new RuntimeException('Refusing non-isolated MySQL.');
// Isolated fixture was historically catalogued under a UE2 profile.
// Scope the UE5 profile assignment to this test and restore it at exit.
$fixtureGameId=2;
$originalProfile=$db->query('SELECT profile_id FROM ue_games WHERE id=2')->fetchColumn();
if($originalProfile===false)throw new RuntimeException('Isolated fixture game missing.');
$restoreProfile=$db->prepare('UPDATE ue_games SET profile_id=? WHERE id=2');
register_shutdown_function(static function()use($restoreProfile,$originalProfile):void{
 try{$restoreProfile->execute([$originalProfile]);}catch(Throwable $e){
  error_log('Could not restore isolated fixture game profile: '.$e->getMessage());
 }
});
$db->exec('UPDATE ue_games SET profile_id=9001 WHERE id=2');
$root='C:/Temp/uedb5-iostore-retention-test';
$containerId=1;
$info=$db->query('SELECT c.game_id,c.status,c.utoc_relative_path,c.utoc_sha256,p.engine_key FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE c.id=1')->fetch();
if(!is_array($info)||$info['status']!=='ready'||!str_starts_with(strtoupper((string)$info['engine_key']),'UE5'))
 throw new RuntimeException('Fixture container must be ready with active UE5 profile.');
if(!is_dir($root)||!str_starts_with((string)$info['utoc_relative_path'],'games/')||str_contains((string)$info['utoc_relative_path'],'..'))
 throw new RuntimeException('Unsafe fixture root or TOC.');
$path=realpath($root.'/'.str_replace('\\','/',(string)$info['utoc_relative_path']));
if($path===false||!str_starts_with(strtolower(str_replace('\\','/',$path)),strtolower($root).'/'))
 throw new RuntimeException('Retained fixture TOC path escaped root.');
$lib=getenv('UNREALDB_OODLE_LIBRARY');
$toc=new Uedb5IoStoreTocReader($path,null,$lib!==false&&$lib!==''?$lib:null);
if(!hash_equals(strtolower((string)$info['utoc_sha256']),strtolower($toc->tocSha256())))
 throw new RuntimeException('Fixture TOC checksum mismatch.');
$counts=$db->prepare('SELECT COUNT(*) total,SUM(metadata_status="indexed") awaiting_names,SUM(metadata_status="failed") failed_names,SUM(metadata_status="ready" AND publication_status<>"published") ready_unpublished,SUM(publication_status="published") published FROM ue_iostore_packages WHERE container_id=?');
$counts->execute([$containerId]);$start=$counts->fetch();
if((int)$start['total']!==27240)throw new RuntimeException('Unexpected package count: '.$start['total']);
echo json_encode(['stage'=>'start','counts'=>$start,'isolated'=>true]),PHP_EOL;flush();
$indexer=new CatalogIoStorePackageNameIndexer($db,$root,$lib!==false&&$lib!==''?$lib:null);
$round=0;
while(true){
 $counts->execute([$containerId]);$c=$counts->fetch();
 if((int)$c['awaiting_names']===0)break;
 $out=$indexer->indexBatch($containerId,250);
 $round++;
 if($round%10===0||$out['failed']>0) {
  echo json_encode(['stage'=>'names','batch'=>$round,'remaining'=>$out['remaining'],'failed_in_batch'=>$out['failed']]),PHP_EOL;flush();
 }
 if($out['processed']===0)throw new RuntimeException('Name indexer made no progress.');
}
$counts->execute([$containerId]);$afterNames=$counts->fetch();
echo json_encode(['stage'=>'names_done','counts'=>$afterNames]),PHP_EOL;flush();
if((int)$afterNames['failed_names']>0)throw new RuntimeException('Name indexing completed with failures: '.$afterNames['failed_names']);
$publisher=new CatalogIoStoreMemberCatalogPublisher($db,$root);
$todo=$db->prepare('SELECT toc_index FROM ue_iostore_packages WHERE container_id=? AND metadata_status="ready" AND publication_status<>"published" ORDER BY toc_index LIMIT 250');
$publishedThisRun=0;
while(true){
 $todo->execute([$containerId]);$rows=$todo->fetchAll();
 if($rows===[])break;
 foreach($rows as $row){
  $publisher->publish($toc,$containerId,(int)$row['toc_index']);
  $publishedThisRun++;
  if($publishedThisRun%250===0){
   echo json_encode(['stage'=>'publication','published_this_run'=>$publishedThisRun]),PHP_EOL;flush();
  }
 }
}
$counts->execute([$containerId]);$final=$counts->fetch();
$ok=(int)$final['total']===27240&&(int)$final['published']===27240
 &&(int)$final['awaiting_names']===0&&(int)$final['ready_unpublished']===0&&(int)$final['failed_names']===0;
echo json_encode(['stage'=>'final','ok'=>$ok,'counts'=>$final,'published_this_run'=>$publishedThisRun],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
