<?php
declare(strict_types=1);
/** Independent UE5.8.3 UnrealPak listing vs UnrealDB compact PAK11 index. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/lib/CatalogPakArchive.php';
$pak=getenv('UE583_SATISFACTORY_PAK') ?: 'C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.pak';
$exe=getenv('UNREALDB_EPIC_UNREALPAK') ?: 'H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPak.exe';
if(!is_file($pak)||!is_file($exe))throw new RuntimeException('Real PAK and genuine Epic UE5.8.3 UnrealPak must exist');
$cmd=escapeshellarg($exe).' '.escapeshellarg($pak).' -List 2>&1';
$lines=[];$exit=-1;exec($cmd,$lines,$exit);
if($exit!==0)throw new RuntimeException('Epic UnrealPak -List failed: '.implode('; ',array_slice($lines,-7)));
$epic=[];
foreach($lines as $line){
 if(!preg_match('/LogPakFile: Display: "(.+)" offset: (\d+), size: (\d+) bytes, sha1: ([0-9A-F]{40}), compression: ([^.]+)\./i',$line,$m))continue;
 $name=$m[1];
 if(isset($epic[$name]))throw new RuntimeException('Duplicate Epic listing path: '.$name);
 $epic[$name]=['offset'=>(int)$m[2],'size'=>(int)$m[3],'sha1'=>$m[4],'compression'=>trim($m[5])];
}
$f=catalog_pak_footer_candidates($pak);if(count($f)!==1||(int)$f[0]['version']!==11)throw new RuntimeException('Not expected PAK11');
$idx=catalog_pak_parse_index($pak,$f[0]);
$db=[];foreach($idx['entries'] as $entry)$db[(string)$entry['filename']]=$entry;
$differences=[];$matched=0;$missingEpic=[];$missingDb=[];
foreach($db as $name=>$row){
 if(!isset($epic[$name])){if(count($missingEpic)<10)$missingEpic[]=$name;continue;}
 $e=$epic[$name];$checks=[
  'offset'=>(int)$row['offset']===$e['offset'],
  'stored_size'=>(int)$row['size']===$e['size'],
  'compression'=>strcasecmp((string)$row['compression_name'],$e['compression'])===0,
 ];
 if(!in_array(false,$checks,true)){$matched++;}elseif(count($differences)<12){$differences[]=['file'=>$name,'epic'=>$e,'unrealdb'=>['offset'=>$row['offset'],'size'=>$row['size'],'compression'=>$row['compression_name']],'checks'=>$checks];}
}
foreach($epic as $name=>$row)if(!isset($db[$name])&&count($missingDb)<10)$missingDb[]=$name;
$ok=count($epic)===4761&&count($db)===4761&&$matched===4761&&$differences===[]&&$missingEpic===[]&&$missingDb===[];
echo json_encode(['ok'=>$ok,'independent_oracle'=>'Official Epic UE5.8.3 UnrealPak.exe -List','archive_version'=>11,'epic_entries'=>count($epic),'unrealdb_entries'=>count($db),'matching_name_offset_size_compression'=>$matched,'sample_mismatches'=>$differences,'missing_in_epic'=>$missingEpic,'missing_in_unrealdb'=>$missingDb,'hash_note'=>'Epic SHA1 covers stored payload; compact encoded UnrealDB PAK index does not contain comparable SHA1; decoded payload SHA1 tested separately'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
