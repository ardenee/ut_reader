<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$tocPath='C:/Temp/uedb5-iostore-retention-test/games/unreal2/iostore/63e4f0e322276684718563e13619474c8cf5981b29e15e502620a4373e351ab5/FactoryGame-WindowsServer.utoc';
$root='C:/Temp/ue583-epic-zen-retained-extract-20261019/';
if(!is_file($exe)||!is_file($tocPath)||!is_dir($root))throw new RuntimeException('Native Epic oracle fixture missing');
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$info=$db->query('SELECT @@port p,@@datadir d')->fetch();
if((int)$info['p']!==3339||stripos($info['d'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Production database forbidden');
$toc=new Uedb5IoStoreTocReader($tocPath,null,'D:/php8.5/ext/oo2core_9_win64.dll');
$hIndexes=$toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_CONTAINER_HEADER);
if(count($hIndexes)!==1)throw new RuntimeException('ContainerHeader not unique');
$h=Uedb5IoStoreContainerHeaderReader::parse($toc->readChunk($hIndexes[0]));
$stores=[];foreach($h['store_entries'] as $e)$stores[strtoupper((string)$e['package_id'])]=$e;
$groups=[];
foreach(['/Engine/','/Game/'] as $prefix){
 $q=$db->prepare('SELECT toc_index,package_name FROM ue_iostore_packages WHERE container_id=4 AND chunk_index=0 AND package_name LIKE ? ORDER BY toc_index LIMIT 90');
 $q->execute([$prefix.'%']);$groups[]=$q->fetchAll();
}
$rows=[];
for($i=0;$i<90;$i++)foreach($groups as $group)if(isset($group[$i]))$rows[]=$group[$i];
if (in_array('--fixtures-only', $argv, true)) {
 echo json_encode(['toc_chunks'=>count($toc->chunks()),'store_entries'=>count($stores),'sample_rows'=>count($rows),'first_sample'=>array_slice($rows,0,3)],JSON_PRETTY_PRINT),PHP_EOL;
 exit(count($rows)>=20?0:2);
}
$mapping=[
 'has_versioning_info'=>fn($z)=>(int)$z['summary']['has_versioning_info'],
 'header_size'=>fn($z)=>(int)$z['summary']['header_size'],
 'package_flags'=>fn($z)=>hexdec((string)$z['summary']['package_flags']),
 'imported_public_export_hashes_offset'=>fn($z)=>(int)$z['summary']['imported_public_export_hashes_offset'],
 'import_map_offset'=>fn($z)=>(int)$z['summary']['import_map_offset'],
 'export_map_offset'=>fn($z)=>(int)$z['summary']['export_map_offset'],
 'export_bundle_entries_offset'=>fn($z)=>(int)$z['summary']['export_bundle_entries_offset'],
 'dependency_bundle_headers_offset'=>fn($z)=>(int)$z['summary']['dependency_bundle_headers_offset'],
 'dependency_bundle_entries_offset'=>fn($z)=>(int)$z['summary']['dependency_bundle_entries_offset'],
 'imported_package_names_offset'=>fn($z)=>(int)$z['summary']['imported_package_names_offset'],
 'ue4_version'=>fn($z)=>$z['versioning_info']===null?-1:(int)$z['versioning_info']['package_version']['ue4'],
 'ue5_version'=>fn($z)=>$z['versioning_info']===null?-1:(int)$z['versioning_info']['package_version']['ue5'],
 'imported_hash_count'=>fn($z)=>count($z['imported_public_export_hashes']),
 'import_count'=>fn($z)=>count($z['import_map']),
 'export_count'=>fn($z)=>count($z['exports']),
 'cell_import_count'=>fn($z)=>count($z['cell_import_map']),
 'cell_export_count'=>fn($z)=>count($z['cell_exports']),
 'bulk_data_count'=>fn($z)=>count($z['bulk_data_map']),
 'export_bundle_count'=>fn($z)=>count($z['export_bundle_entries']),
 'dependency_bundle_header_count'=>fn($z)=>count($z['dependency_bundle_headers']),
 'dependency_bundle_entry_count'=>fn($z)=>count($z['dependency_bundle_entries'])
];
$checked=0;$compared=0;$fail=[];$versions=[];$skipped=0;
foreach($rows as $row){
 $name=(string)$row['package_name'];
 $stem=str_starts_with($name,'/Engine/')?'Engine/Content/'.substr($name,8):(str_starts_with($name,'/Game/')?'FactoryGame/Content/'.substr($name,6):null);
 if($stem===null)continue;
 $stem=(string)preg_replace('/\.(uasset|umap)$/i','',$stem);
 $path=$root.$stem.'.uheader';
 if(!is_file($path)){$skipped++;continue;}
 try{
  $index=(int)$row['toc_index'];$c=$toc->chunks()[$index];$id=strtoupper((string)$c['package_id']);
  if(!isset($stores[$id]))throw new RuntimeException('Missing package-store entry '.$id);
  $z=Uedb5ZenPackageReader::parse($toc->readChunk($index),$id,$stores[$id]);
  $lines=[];$rc=0;exec(escapeshellarg($exe).' -ZenHeaderProbe='.escapeshellarg($path).' -unattended 2>&1',$lines,$rc);
  $stdout=str_replace('\\n', "\n", implode("\n",$lines)); // Native probe row markers use literal backslash-n separators.
  if(!in_array($rc,[0,777006],true)||!preg_match('/UE583_NATIVE_ZEN_ORACLE (\{[^\r\n]+\})/',$stdout,$m))throw new RuntimeException('Epic native probe exit '.$rc.' '.substr($stdout,-450));
  $native=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
  foreach($mapping as $key=>$decode){
   if(!array_key_exists($key,$native))throw new RuntimeException('Missing native field '.$key);
   $epic=(int)$native[$key];$local=(int)$decode($z);
   if($epic!==$local)throw new RuntimeException($key.' Epic='.$epic.' UnrealDB='.$local);
   $compared++;
  }
  if(!preg_match_all('/^UE583_NATIVE_ZEN_EXPORT (\\d+) (\\d+) (\\d+) (\\d+) (\\d+) (\\d+)$/m',$stdout,$nativeExports,PREG_SET_ORDER))throw new RuntimeException('Missing native export row markers');
  if(count($nativeExports)!==count($z['exports']))throw new RuntimeException('Native export row count mismatch');
  foreach($nativeExports as $entry){
   $i=(int)$entry[1];$row=$z['exports'][$i]??null;
   if(!is_array($row))throw new RuntimeException('Missing UnrealDB export row '.$i);
   foreach(['cooked_serial_offset'=>(int)$row['cooked_serial_offset'],'cooked_serial_size'=>(int)$row['cooked_serial_size'],'object_flags'=>hexdec($row['object_flags']),'filter_flags'=>(int)$row['filter_flags']] as $field=>$actual){
    $column=['cooked_serial_offset'=>2,'cooked_serial_size'=>3,'object_flags'=>5,'filter_flags'=>6][$field];
    if((int)$entry[$column]!==$actual)throw new RuntimeException('Export['.$i.'].'.$field.' native='.$entry[$column].' UnrealDB='.$actual);
    $compared++;
   }
  }
  if(!preg_match_all('/^UE583_NATIVE_ZEN_DEPHEADER (\\d+) (-?\\d+) (\\d+) (\\d+) (\\d+) (\\d+)$/m',$stdout,$nativeDeps,PREG_SET_ORDER))throw new RuntimeException('Missing native dependency header markers');
  if(count($nativeDeps)!==count($z['dependency_bundle_headers']))throw new RuntimeException('Native dependency header row count mismatch');
  foreach($nativeDeps as $entry){
   $i=(int)$entry[1];$row=$z['dependency_bundle_headers'][$i]??null;
   if(!is_array($row))throw new RuntimeException('Missing UnrealDB dependency header '.$i);
   $actual=[(int)$row['first_entry_index'],(int)$row['entry_count'][0][0],(int)$row['entry_count'][0][1],(int)$row['entry_count'][1][0],(int)$row['entry_count'][1][1]];
   foreach($actual as $j=>$value){if((int)$entry[$j+2]!==$value)throw new RuntimeException('DependencyHeader['.$i.'].field'.$j.' native='.$entry[$j+2].' UnrealDB='.$value);$compared++;}
  }
  preg_match_all('/^UE583_NATIVE_ZEN_IMPORT (\\d+) ([0-9A-F]{16})$/m',$stdout,$nativeImports,PREG_SET_ORDER);
  if(count($nativeImports)!==count($z['import_map']))throw new RuntimeException('Native imported identity count mismatch');
  foreach($nativeImports as $entry){$i=(int)$entry[1];$raw=(string)($z['import_map'][$i]['raw_type_and_id']??'');if($entry[2]!==$raw)throw new RuntimeException('Import['.$i.'] native='.$entry[2].' UnrealDB='.$raw);$compared++;}
  preg_match_all('/^UE583_NATIVE_ZEN_PUBHASH (\\d+) ([0-9A-F]{16})$/m',$stdout,$nativeHashes,PREG_SET_ORDER);
  if(count($nativeHashes)!==count($z['exports']))throw new RuntimeException('Native export hash count mismatch');
  foreach($nativeHashes as $entry){$i=(int)$entry[1];$raw=(string)($z['exports'][$i]['public_export_hash']??'');if($entry[2]!==$raw)throw new RuntimeException('ExportHash['.$i.'] native='.$entry[2].' UnrealDB='.$raw);$compared++;}
  preg_match_all('/^UE583_NATIVE_ZEN_DEPENTRY (\\d+) (-?\\d+)$/m',$stdout,$nativeEntries,PREG_SET_ORDER);
  if(count($nativeEntries)!==count($z['dependency_bundle_entries']))throw new RuntimeException('Native dependency entry count mismatch');
  foreach($nativeEntries as $entry){$i=(int)$entry[1];$raw=(int)($z['dependency_bundle_entries'][$i]['raw_package_index']??PHP_INT_MIN);if((int)$entry[2]!==$raw)throw new RuntimeException('DependencyEntry['.$i.'] native='.$entry[2].' UnrealDB='.$raw);$compared++;}
  $checked++;$v=(string)($z['versioning_info']['package_version']['ue5']??'unversioned');$versions[$v]=($versions[$v]??0)+1;
 }catch(Throwable $e){$fail[]=['package'=>$name,'message'=>$e->getMessage()];if(count($fail)>=8)break;}
 if($checked>=100)break;
}
$ok=$checked>=100&&$fail===[];
echo json_encode(['ok'=>$ok,'native_epic_zen_packages'=>$checked,'native_field_comparisons'=>$compared,'versions'=>$versions,'missing_extracted_headers'=>$skipped,'errors'=>$fail],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($ok?0:2);
