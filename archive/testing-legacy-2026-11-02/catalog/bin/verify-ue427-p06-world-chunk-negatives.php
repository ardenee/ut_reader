<?php
/** Disposable Epic-authored modern/world and genuine legacy chunk fixtures. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$dir=$argv[1]??'C:/Temp/ue427-p06-world-fixtures';
$little=$dir.'/world-chunk-little.uasset';$swapped=$dir.'/world-chunk-swapped.uasset';
$legacy=$dir.'/legacy-single-chunk.uasset';
foreach([$little,$swapped,$legacy] as $file)if(!is_file($file))throw new RuntimeException('Missing fixture '.$file);
$modern=file_get_contents($little);$swap=file_get_contents($swapped);$old=file_get_contents($legacy);
$h=(new UnrealPackageReader4($little))->getHeader();
$marker=pack('V',(int)$h['assetRegistryDataOffset'])
 .pack('P',(int)$h['bulkDataStartOffset']).pack('V',1024).pack('V',3);
$pos=strpos(substr($modern,0,700),$marker);
if($pos===false)throw new RuntimeException('Epic summary world/chunk field block not located');
$countAt=$pos+16;$worldAt=$pos+12;
$lodCountMarker=pack('V',1).pack('V',12345);
$lodPos=strpos($modern,$lodCountMarker,1024);
if($lodPos===false)throw new RuntimeException('Epic world-tile LOD count not located');
$tmp=sys_get_temp_dir().'/ue427-p06-world-negative-'.getmypid();
if(!mkdir($tmp))throw new RuntimeException('Disposable dir unavailable');
$target=$tmp.'/mutant.uasset';$checks=[];
$run=function(string $name,string $data,bool $accept,array $expectedChunks=[])use($target,&$checks):void{
 file_put_contents($target,$data);
 $reader=new UnrealPackageReader4($target);$head=$reader->getHeader();
 $valid=$reader->validatePackage()===[] && $head['chunkIDs']===$expectedChunks;
 $checks[$name]=['ok'=>$valid===$accept,'accepted'=>$valid,
  'issues'=>array_slice($reader->validatePackage(),0,2)];
};
try{
 $run('modern_little_positive',$modern,true,[3,7,19]);
 $run('modern_swapped_positive',$swap,true,[3,7,19]);
 $run('genuine_legacy_single_id_positive',$old,true,[7]);
 $run('extreme_array_count',substr_replace($modern,pack('V',0x7fffffff),$countAt,4),false,[3,7,19]);
 $run('invalid_world_payload_offset',substr_replace($modern,pack('V',4095),$worldAt,4),false,[3,7,19]);
 $run('excessive_world_layer_string',substr_replace($modern,pack('V',0x7fffffff),1024+12+25,4),false,[3,7,19]);
 $run('negative_world_lod_count',substr_replace($modern,pack('V',0xffffffff),$lodPos,4),false,[3,7,19]);
 $run('excessive_world_lod_count',substr_replace($modern,pack('V',0x7fffffff),$lodPos,4),false,[3,7,19]);
 $oldReader=new UnrealPackageReader4($legacy);
 $oldHeader=$oldReader->getHeader();
 $oldSequence=pack('V',(int)$oldHeader['assetRegistryDataOffset'])
  .pack('P',(int)$oldHeader['bulkDataStartOffset'])
  .pack('V',(int)$oldHeader['worldTileInfoDataOffset']).pack('V',7);
 $oldPos=strpos(substr($old,0,1000),$oldSequence);
 if($oldPos===false)throw new RuntimeException('Legacy single ChunkID field not found');
 $chunkAt=$oldPos+16;
 $run('legacy_negative_sentinel_omitted',substr_replace($old,pack('V',0xffffffff),$chunkAt,4),true,[]);
 $run('legacy_zero_chunk_retained',substr_replace($old,pack('V',0),$chunkAt,4),true,[0]);
 $run('swapped_excessive_array_count',substr_replace($swap,pack('N',0x7fffffff),$countAt,4),false,[3,7,19]);
}finally{
 foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);
 @rmdir($tmp);
}
$ok=count($checks)===11&&!in_array(false,array_column($checks,'ok'),true);
echo json_encode(['ok'=>$ok,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
