<?php
declare(strict_types=1);
require 'C:/Apache24/htdocs/unrealdb/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenEffectivePackageStoreContextBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageStoreBackendArbitrator;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||stripos($id['datadir'],'uedb5-isolated-mysql')===false){throw new RuntimeException('Not the isolated database');}
$stored='C:/Temp/uedb5-iostore-retention-test';
$q=$db->query('SELECT id,utoc_relative_path,utoc_sha256 FROM ue_iostore_containers WHERE id=1 LIMIT 1')->fetch();
if(!$q||!str_starts_with((string)$q['utoc_relative_path'],'games/')){throw new RuntimeException('Missing expected isolated container');}
$path=realpath($stored.'/'.str_replace('\\','/',(string)$q['utoc_relative_path']));
if(!is_string($path)||!str_starts_with(strtolower(str_replace('\\','/',$path)),strtolower($stored).'/')){throw new RuntimeException('Invalid retained path');}
$toc=new Uedb5IoStoreTocReader($path,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$chunks=$toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_CONTAINER_HEADER);
$results=[];
foreach(array_slice($chunks,0,2) as $index){
 $bytes=$toc->readChunk($index);
 $parsed=Uedb5IoStoreContainerHeaderReader::parse($bytes);
 $results[]=['chunk_index'=>$index,'source_bytes'=>strlen($bytes),'parsed_version'=>$parsed['version'],
  'package_ids'=>count($parsed['package_ids']),'optional_ids'=>count($parsed['optional_segment_package_ids']),
  'redirects'=>count($parsed['package_redirects'])];
}
// Bounded cross-check: two published physical members must exist in the
// original package-ID list and carry matching V5 provider-key projections.
// These are file facts, NOT proof of actual runtime mount Order/Sequence.
$read=$db->prepare('SELECT m.toc_index,m.file_id,HEX(m.package_id) package_id,'
 .'f.game_id,f.scan_status FROM ue_iostore_packages m '
 .'JOIN ue_files f ON f.id=m.file_id '
 .'WHERE m.container_id=? AND m.toc_index IN (50,51) ORDER BY m.toc_index');
$read->execute([1]);$published=$read->fetchAll(PDO::FETCH_ASSOC);
$providerCheck=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_provider_keys '
 .'WHERE file_id=? AND game_id=? AND package_key_kind=2 AND package_key=UNHEX(?)');
$entryList=[];$matched=[];
foreach($published as $row){
 $pid=(string)$row['package_id'];
 $fileId=(int)$row['file_id'];
 $providerCheck->execute([$fileId,(int)$row['game_id'],$pid]);
 $inHeader=in_array($pid,$parsed['package_ids'],true);
 $projected=(int)$providerCheck->fetchColumn()>0;
 $verified=(string)$row['scan_status']==='verified';
 $matched[]=['toc_index'=>(int)$row['toc_index'],'file_id'=>$fileId,
  'package_id'=>$pid,'in_header'=>$inHeader,
  'v5_provider_projected'=>$projected,'verified'=>$verified];
 if($inHeader&&$projected&&$verified){
  $entryList[]=['package_id'=>$pid,'file_id'=>$fileId];
 }
}
$built=Uedb5ZenEffectivePackageStoreContextBuilder::build([
 ['container_id'=>'isolated-retained-header','order'=>0,'sequence'=>0,
  'package_redirects'=>$parsed['package_redirects'],
  'localized_packages'=>$parsed['localized_packages'],
  'package_entries'=>$entryList],
],[],1);
$selected=[];
foreach($entryList as $entry){
 $answer=Uedb5ZenPackageStoreBackendArbitrator::entry(
  $built['package_store_backend_results'],$entry['package_id']);
 $selected[]=['package_id'=>$entry['package_id'],
  'selected_file_id'=>$answer['entry']['file_id']??null,
  'status'=>$answer['status'],'backend_id'=>$answer['backend_id']];
}
$ok=count($results)>0 && count($matched)===2 && count($entryList)===2
 &&$built['package_store_context_authoritative']===false
 &&count($selected)===2;
foreach($selected as $i=>$result){
 $ok=$ok && $result['status']==='Ok'
   &&$result['backend_id']==='file'
   &&(int)$result['selected_file_id']===$entryList[$i]['file_id'];
}
echo json_encode(['ok'=>$ok,'read_only'=>true,'isolated_mysql'=>true,
 'header'=>$results,'checked_published_members'=>$matched,
 'file_backend_selection'=>$selected,'runtime_authority_claimed'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
