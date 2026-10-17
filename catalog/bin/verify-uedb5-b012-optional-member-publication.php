<?php
declare(strict_types=1);

/**
 * D030: optional ExportBundleData chunk 1 is a segment of its main Zen
 * package, not an independent publishable file. Read-only + isolated
 * disposable SQLite assertions, never touches PRD or persistent catalog DB.
 */
require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreMemberCatalogPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreIndexedPackageReader;

if(PHP_SAPI!=='cli') exit(2);
$root=dirname(__DIR__,2);
$checks=[];$fails=[];
$check=static function(string $key,bool $pass)use(&$checks,&$fails):void{
    $checks[$key]=$pass;if(!$pass)$fails[]=$key;
};
$read=static fn(string $rel):string=>(string)file_get_contents($root.'/'.$rel);
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),"/\\");
$epic=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$source=is_file($epic)?(string)file_get_contents($epic):'';
$check('b012_d030_pinned_epic_583_chunk0_and_chunk1_source',
  hash('sha256',$source)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9'
  &&str_contains($source,'CreateIoChunkId(Desc.PackageIdToLoad.Value(), 1, EIoChunkType::ExportBundleData)')
  &&str_contains($source,'const uint16 ChunkIndex = bRequestOptionalChunk ? 1 : 0;')
  &&str_contains($source,'const uint16 ChunkIndex = 0;'));
$indexer=$read('catalog/src/Infrastructure/Storage/CatalogIoStoreContainerIndexer.php');
$name=$read('catalog/src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php');
$nameJob=$read('catalog/src/Infrastructure/Jobs/CatalogIoStoreNameIndexJobHandler.php');
$publishJob=$read('catalog/src/Infrastructure/Jobs/CatalogIoStoreMemberPublishJobHandler.php');
$publisher=$read('catalog/src/Infrastructure/Import/CatalogIoStoreMemberCatalogPublisher.php');
$reader=$read('catalog/src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php');
$snapshot=$read('catalog/src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php');
$check('b012_d030_original_toc_still_retains_all_export_bundle_chunks',
  str_contains($indexer,'chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA)')
  &&str_contains($indexer,"(int)\$chunk['chunk_index']")
  &&!str_contains($indexer,"if((int)\$chunk['chunk_index']!==0)continue;"));
$check('b012_d030_optional_still_parsed_beneath_owning_main_snapshot',
  str_contains($snapshot,'CHUNK_TYPE_EXPORT_BUNDLE_DATA, 0')
  &&str_contains($snapshot,'CHUNK_TYPE_EXPORT_BUNDLE_DATA, 1')
  &&str_contains($snapshot,"'optional_segment' => \$optionalZen === null ? []"));
$check('b012_d030_name_job_reads_only_main_chunks',
  str_contains($name,'WHERE container_id=? AND chunk_index=0 AND metadata_status="indexed"'));
$check('b012_d030_name_indexer_rechecks_toc_segment_identity',
  str_contains($name,"(int)\$chunk['chunk_index']!==0"));
$check('b012_d030_name_index_totals_ignore_legacy_optional_rows',
  str_contains($name,'FROM ue_iostore_packages WHERE container_id=? AND chunk_index=0'));
$check('b012_d030_name_index_failed_counter_ignores_optional_rows',
  str_contains($nameJob,'WHERE container_id=? AND chunk_index=0 AND metadata_status="failed"'));
$check('b012_d030_publish_job_queue_selects_main_only',
  str_contains($publishJob,'WHERE container_id=? AND chunk_index=0 AND metadata_status="ready"'));
$check('b012_d030_publish_job_counts_only_main_rows',
  str_contains($publishJob,'FROM ue_iostore_packages WHERE container_id=? AND chunk_index=0'));
$check('b012_d030_direct_publisher_reads_and_rejects_optional_member',
  str_contains($publisher,'m.logical_size,m.chunk_index,')
  &&str_contains($publisher,"(int)\$member['chunk_index']!==0"));
$check('b012_d030_direct_publisher_rechecks_toc_chunk0',
  str_contains($publisher,"(int)\$chunk['chunk_index']!==0"));
$check('b012_d030_direct_inspection_rejects_optional_member',
  str_contains($reader,"(int)\$member['chunk_index']!==0")
  &&str_contains($reader,"(int)\$chunk['chunk_index']!==0"));
$check('b012_d030_parser_and_snapshot_code_unchanged',
  hash('sha256',$snapshot)==='dccb03419fb8e64e143f49d452f02538b2bb98a03d4316e1c4de0d43950ccdc9');
$check('b012_d030_pdo_sqlite_available',
  in_array('sqlite',PDO::getAvailableDrivers(),true));
$historical=[];
foreach([
 'catalog/src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php',
 'catalog/src/Infrastructure/Jobs/CatalogIoStoreMemberPublishJobHandler.php',
 'catalog/src/Infrastructure/Import/CatalogIoStoreMemberCatalogPublisher.php',
 'catalog/src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php'
] as $rel){
 $output=[];$status=127;
 exec('git -c '.escapeshellarg('safe.directory='.str_replace('\\','/',$root))
     .' -C '.escapeshellarg($root).' show '.escapeshellarg('6cf82b0d:'.$rel).' 2>&1',$output,$status);
 $historical[$rel]=$status===0?implode("\n",$output):'';
}
$check('b012_d030_historical_main_chunk_name_gate_was_absent',
 $historical['catalog/src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php']!==''
 &&!str_contains($historical['catalog/src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php'],
    'WHERE container_id=? AND chunk_index=0 AND metadata_status="indexed"'));
$check('b012_d030_historical_publish_queue_failed_to_exclude_optional',
 $historical['catalog/src/Infrastructure/Jobs/CatalogIoStoreMemberPublishJobHandler.php']!==''
 &&!str_contains($historical['catalog/src/Infrastructure/Jobs/CatalogIoStoreMemberPublishJobHandler.php'],
    'WHERE container_id=? AND chunk_index=0 AND metadata_status="ready"'));
$check('b012_d030_historical_direct_publisher_accepted_metadata_chunk1',
 $historical['catalog/src/Infrastructure/Import/CatalogIoStoreMemberCatalogPublisher.php']!==''
 &&!str_contains($historical['catalog/src/Infrastructure/Import/CatalogIoStoreMemberCatalogPublisher.php'],
    "(int)\$member['chunk_index']!==0"));
$check('b012_d030_historical_member_reader_lacked_chunk1_rejection',
 $historical['catalog/src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php']!==''
 &&!str_contains($historical['catalog/src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php'],
    "(int)\$member['chunk_index']!==0"));
$details=[];
try {
  $db=new PDO('sqlite::memory:');
  $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,slug TEXT)');
  $db->exec('CREATE TABLE ue_iostore_containers(id INTEGER PRIMARY KEY,game_id INTEGER,name TEXT,utoc_relative_path TEXT,utoc_sha256 TEXT,status TEXT)');
  $db->exec('CREATE TABLE ue_iostore_packages(id INTEGER PRIMARY KEY,container_id INTEGER,toc_index INTEGER,package_id BLOB,chunk_index INTEGER,logical_size INTEGER,file_id INTEGER,publication_status TEXT,metadata_status TEXT,package_name TEXT,name_count INTEGER,import_count INTEGER,export_count INTEGER)');
  $db->exec("INSERT INTO ue_games VALUES(7,'test')");
  $db->exec("INSERT INTO ue_iostore_containers VALUES(1,7,'isolated','games/test/example.utoc','testsha','ready')");
  $rows=[
    [1,1,20,'0011223344556677',0,100,null,'indexed','indexed','MainA',3,1,2],
    [2,1,21,'0011223344556677',1,50,null,'indexed','indexed','OptionalA',3,1,2],
    [3,1,22,'8899AABBCCDDEEFF',0,60,null,'indexed','indexed','MainB',2,1,1],
    [4,1,23,'8899AABBCCDDEEFF',1,80,null,'indexed','failed','OptionalB',2,1,1],
  ];
  $insert=$db->prepare('INSERT INTO ue_iostore_packages VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
  foreach($rows as $row){
    $row[3]=hex2bin($row[3]);$insert->execute($row);
  }
  // The actual production SQL literal, not a hand-written query, is
  // extracted and executed against a disposable SQLite table.
  $extract=static function(string $body,string $begin,string $end):string{
    $a=strpos($body,$begin);
    if($a===false)throw new RuntimeException('Production SQL start missing');
    $b=strpos($body,$end,$a);
    if($b===false)throw new RuntimeException('Production SQL end missing');
    return substr($body,$a,$b-$a);
  };
  $sql=$extract($name,
      'SELECT id,toc_index,HEX(package_id) package_id FROM ue_iostore_packages',
      " LIMIT '.\$limit");
  $statement=$db->prepare($sql.' LIMIT 100');$statement->execute([1]);
  $filtered=$statement->fetchAll(PDO::FETCH_ASSOC);
  $check('b012_d030_production_name_selection_executes_main_only_in_sqlite',
      array_column($filtered,'toc_index')===[20,22]);
  $sqlTotal=$extract($name,
    'SELECT COUNT(*) total,SUM(metadata_status="indexed") remaining FROM ue_iostore_packages',
    "');");
  $statement=$db->prepare($sqlTotal);$statement->execute([1]);
  $count=$statement->fetch(PDO::FETCH_ASSOC);
  $check('b012_d030_production_name_count_excludes_optional_chunks',
      (int)$count['total']===2 &&(int)$count['remaining']===2);
  $sqlBefore='SELECT id,toc_index,HEX(package_id) package_id FROM ue_iostore_packages WHERE container_id=1 AND metadata_status="indexed" ORDER BY toc_index LIMIT 100';
  $old=$db->query($sqlBefore)->fetchAll(PDO::FETCH_ASSOC);
  $check('b012_d030_negative_before_old_name_index_would_index_optional_chunk',
      in_array(21,array_map('intval',array_column($old,'toc_index')),true)
      &&!in_array(21,array_map('intval',array_column($filtered,'toc_index')),true));
  $sqlPublish='SELECT toc_index FROM ue_iostore_packages WHERE container_id=1 AND chunk_index=0 AND metadata_status="ready" AND publication_status<>"published" ORDER BY toc_index LIMIT 10';
  $db->exec('UPDATE ue_iostore_packages SET metadata_status="ready" WHERE id IN (1,2,3)');
  $candidate=$db->query($sqlPublish)->fetchAll(PDO::FETCH_ASSOC);
  $check('b012_d030_publish_queue_keeps_only_main_and_legacy_optional_does_not_block',
      array_map('intval',array_column($candidate,'toc_index'))===[20,22]);
  $counts=$db->query('SELECT COUNT(*) total,SUM(metadata_status="ready" AND publication_status<>"published") remaining,SUM(metadata_status="indexed") awaiting_names,SUM(metadata_status="failed") failed_names FROM ue_iostore_packages WHERE container_id=1 AND chunk_index=0')->fetch(PDO::FETCH_ASSOC);
  $check('b012_d030_publish_counts_exclude_optional_failed_and_ready_rows',
      (int)$counts['total']===2 &&(int)$counts['remaining']===2
      &&(int)$counts['awaiting_names']===0 &&(int)$counts['failed_names']===0);
  // Short circuit must occur before opening potentially external source files.
  $viewer=new CatalogIoStoreIndexedPackageReader($db,sys_get_temp_dir());
  $blocked=false;
  try {$viewer->inspect(1,21);}catch(RuntimeException $e){
    $blocked=str_contains($e->getMessage(),'Optional IoStore chunk');
  }
  $check('b012_d030_real_viewer_rejects_optional_index_before_source_io',$blocked);
  $tocPath='C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
  if(!is_file($tocPath))throw new RuntimeException('Retained real Satisfactory TOC not found');
  $toc=new Uedb5IoStoreTocReader($tocPath);
  $rejected=false;
  $publishing=new CatalogIoStoreMemberCatalogPublisher($db,sys_get_temp_dir());
  try {$publishing->publish($toc,1,21);}catch(RuntimeException $e){
    $rejected=str_contains($e->getMessage(),'cannot be published as a separate file');
  }
  $check('b012_d030_real_publisher_rejects_optional_ready_row_before_source_io',$rejected);
  $check('b012_d030_legacy_optional_rows_preserved_not_deleted',
      (int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages')->fetchColumn()===4);
  $actualMain=0;$actualOptional=0;
  foreach($toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA) as $pos){
    $record=$toc->chunks()[$pos];
    if((int)$record['chunk_index']===0)$actualMain++;
    elseif((int)$record['chunk_index']===1)$actualOptional++;
  }
  $check('b012_d030_retained_real_satisfactory_export_bundle_identity',
    $actualMain===27240 &&$actualOptional===0);
  $details=['isolated_sqlite_rows'=>count($rows),
    'selected_name_members'=>array_map('intval',array_column($filtered,'toc_index')),
    'selected_publish_members'=>array_map('intval',array_column($candidate,'toc_index')),
    'retained_real_satisfactory_main_chunks'=>$actualMain,
    'retained_real_satisfactory_optional_chunks'=>$actualOptional,
    'real_optional_fixture_available'=>false];
} catch(Throwable $error){
  $fails[]='b012_d030_fixture_setup_or_execution';
  $details['exception']=$error->getMessage();
}
echo json_encode([
 'ok'=>$fails===[],
 'operation'=>'UE5-583-OPTIONAL-EXPORT-SEGMENT-001',
 'checks'=>$checks,
 'failures'=>$fails,'details'=>$details,
 'preexisting_index_rows_deleted'=>false,'production_db_written'=>false,
 'real_optional_cooked_fixture_executed'=>false,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
