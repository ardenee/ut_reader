#!/usr/bin/env php
<?php
/** Native Epic v0.867 UBitArray/UBitMatrix RLE serializer v22/v23. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBitArrayReader as Bit;
$checks=[];$bad=[];$check=static function(string $name,bool $ok)use(&$checks,&$bad):void{
 $checks[$name]=$ok;if(!$ok)$bad[]=$name;
};
$fixture=static function(int $version,bool $matrix):array{
 $names='';foreach(['BitArray','BitMatrix','ArrayInstance'] as $n)$names.=$n."\0".pack('V',0);
 $nameOff=56;$headerOff=$nameOff+strlen($names);
 $numBits=$matrix?6:66;$numWords=(int)ceil($numBits/32);
 $compressed=$matrix?"\x06":"\x40\x40\x02";
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $header=$stack.pack('V3',$numWords,$numWords,$numBits).($matrix?pack('V',3):'');
 $dataOff=$headerOff+strlen($header);
 $objOff=$dataOff+strlen($compressed);
 $objects=pack('vvVV',3,($matrix?2:1),0,$headerOff)
   .pack('V4',0,$dataOff,strlen($header),strlen($compressed));
 $summary=pack('a32V6',"Unrealfile\x1A",$version,1,3,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$header.$compressed.$objects,
  'header'=>$headerOff,'data'=>$dataOff,'objects'=>$objOff,
  'num_bits'=>$numBits,'class'=>$matrix?'BitMatrix':'BitArray'];
};
$temp=tempnam(sys_get_temp_dir(),'ue1bits97');
if(!is_string($temp))throw new RuntimeException('No bit archive temp');
try{
 foreach([22,23] as $version)foreach([false,true] as $matrix){
  $fx=$fixture($version,$matrix);file_put_contents($temp,$fx['bytes']);
  $r=Bit::read($temp,true)['bit_archive_objects'][0]??[];
  $k="v{$version}_".($matrix?'matrix':'array');
  $expected=$matrix?str_repeat('0',6):str_repeat('0',64).'11';
  $check("{$k}_rle_bits_reconstructed",
      ($r['status']??null)==='native_bit_rle_decoded'
      && ($r['source_class']??null)===$fx['class']
      && ($r['num_bits']??null)===$fx['num_bits']
      && ($r['bits_binary']??null)===$expected
      && ($r['bits_set_count']??null)===($matrix?0:2)
      && ($r['unconsumed_stream_bytes']??null)===0);
  $check("{$k}_source_header_and_rle_lengths",
      ($r['database_num_words']??null)===($matrix?1:3)
      && ($r['database_max_words']??null)===($matrix?1:3)
      && ($r['matrix_side']??null)===($matrix?3:null)
      && ($r['rle_steps']??null)===($matrix?1:2)
      && ($r['runtime_class_references_resolved']??true)===false);
  $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $temp,['format'=>'early_unrealfile','version'=>$version]
  );
  $s=$staged['native_metadata']['unrealfile_bit_archive']['bit_archive_objects'][0]??[];
  $check("{$k}_staging_preserves_not_runtime",
      ($s['num_bits']??null)===$fx['num_bits']
      && ($staged['parse_complete']??true)===false);
  file_put_contents($temp,substr_replace($fx['bytes'],pack('V',0),$fx['header']+20,4));
  try{Bit::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$k}_bad_capacity_or_bits_rejected",$reject);
  $len=$matrix?0:1;
  file_put_contents($temp,substr_replace($fx['bytes'],pack('V',$len),$fx['objects']+24,4));
  try{Bit::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$k}_truncated_rle_rejected",$reject);
  if($matrix){
    file_put_contents($temp,substr_replace($fx['bytes'],pack('V',4),$fx['header']+28,4));
    try{Bit::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("{$k}_invalid_triangular_side_rejected",$reject);
  }
 }
}finally{@unlink($temp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
