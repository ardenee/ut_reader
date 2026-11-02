<?php
/** Mutate one source-shaped FName name-map payload of a disposable genuine
 * package copy.  String length is preserved; no table offsets are altered.
 * Does not modify original Epic files or build/install binaries.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$source='H:/Epic Games/UE_4.27/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset';
$dest='C:/Temp/UE427Oracle/Saved/UE427P07Probe/MissingNativeScript.uasset';
$original=file_get_contents($source);
if(!is_string($original))throw new RuntimeException('Missing genuine source');
$reader=new UnrealPackageReader4($source);
if($reader->validatePackage()!==[])throw new RuntimeException('Genuine UE4 package does not validate');
$names=$reader->getNames();$h=$reader->getHeader();
if(!in_array('/Script/Engine',array_column($names,'name'),true))throw new RuntimeException('Expected source FName absent');
$start=(int)$h['nameOffset'];$end=(int)$h['importOffset'];
if($end<=$start)throw new RuntimeException('Invalid name-map boundaries');
$needle='/Script/Engine';$altered='/Script/EnginZ';
$pos=strpos($original,$needle,$start);
if($pos===false || $pos>=$end || strpos($original,$needle,$pos+1)!==false)
 throw new RuntimeException('Expected exactly one source name payload');
$bytes=substr_replace($original,$altered,$pos,strlen($needle));
if(strlen($bytes)!==strlen($original))throw new RuntimeException('Mutation changed file length');
if(!is_dir(dirname($dest)) && !mkdir(dirname($dest),0777,true))throw new RuntimeException('Missing temp directory');
file_put_contents($dest,$bytes);
$mutated=new UnrealPackageReader4($dest);
$valid=$mutated->validatePackage()===[];
$namesMut=$mutated->getNames();
$found=in_array($altered,array_column($namesMut,'name'),true)&&!in_array($needle,array_column($namesMut,'name'),true);
$ok=$valid&&$found &&hash('sha256',$original)===hash_file('sha256',$source)
 &&hash_file('sha256',$dest)!==hash_file('sha256',$source);
echo json_encode(['ok'=>$ok,'original'=>$source,'disposable_fixture'=>$dest,
 'physical_byte_offset'=>$pos,'old_name'=>$needle,'new_name'=>$altered,
 'length_bytes'=>strlen($bytes),'mutated_reader_errors'=>$mutated->validatePackage(),
 'source_sha256'=>hash_file('sha256',$source),'fixture_sha256'=>hash_file('sha256',$dest)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
