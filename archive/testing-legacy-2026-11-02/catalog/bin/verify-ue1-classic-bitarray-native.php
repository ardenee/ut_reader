#!/usr/bin/env php
<?php
/** v1.200 and UT99 v400 UBitArray / UBitMatrix source-encoded RLE acceptance. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;

$idx=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$head=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($head);
 $s=chr($head|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$prefix=static function(int $v):string{
 $raw=$v<40?pack('V2',0,0):'';
 if($v<47){$raw.="\0\0".str_repeat("\0",8);}
 return $raw."\0".($v<57?"\0":"").($v<58?"\0":"");
};
$package=static function(string $kind,int $ver,string $body)use($idx):string{
 $names=['None','BitArray','BitMatrix','Instance','Engine','Class'];
 $nameData='';
 foreach($names as $name){$nameData.=($ver>=64?$idx(strlen($name)+1):'').$name."\0".pack('V',0);}
 $import=$idx(4).$idx(5).($ver<50?$idx(0):pack('V',0)).$idx($kind==='BitMatrix'?2:1);
 $summarySize=$ver>=68?56:44;
 $nameOffset=$summarySize;$importOffset=$nameOffset+strlen($nameData);$exportOffset=$importOffset+strlen($import);
 $export=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(3).pack('V',0)
        .$idx(strlen($body)).$idx(512);
 $sum=pack('V9',0x9e2a83c1,$ver,0,count($names),$nameOffset,1,$exportOffset,1,$importOffset)
      .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $tables=$sum.$nameData.$import.$export;
 if(strlen($tables)>512){throw new RuntimeException('Fixture tables exceed 512B');}
 return $tables.str_repeat("\0",512-strlen($tables)).$body;
};
$paths=[];$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$parse=static function(string $kind,int $v,int $bits,string $rle,string $extra='')use($prefix,$idx,$package,&$paths):array{
 $body=$prefix($v).$idx($bits).$rle.$extra;
 $p=tempnam(sys_get_temp_dir(),'ue1bit');
 if(!$p)throw new RuntimeException('Cannot create bit-array fixture');
 $paths[]=$p;file_put_contents($p,$package($kind,$v,$body));
 $pkg=new Package($p);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Fixture package validation failed');
 $props=Tag::inspect($p,$pkg,0);
 return Native::inspect($p,$pkg,0,$props);
};
$bad=static function(string $kind,int $v,int $bits,string $rle,string $extra='')use($parse):bool{
 try{$parse($kind,$v,$bits,$rle,$extra);return false;}catch(RuntimeException){return true;}
};
try{
 foreach([35,61,69] as $v){
  $zero=$parse('BitArray',$v,5,chr(5));
  $check('zeros_v'.$v,$zero['status']==='native_bitarray_record_complete'
    &&$zero['bit_count']===5&&$zero['set_bits']===0
    &&$zero['bit_preview']===[0,0,0,0,0]);
  $one=$parse('BitArray',$v,5,"\0".chr(5));
  $check('ones_v'.$v,$one['set_bits']===5&&$one['rle_command_count']===2
    &&$one['bit_preview']===[1,1,1,1,1]);
  $blip=$parse('BitArray',$v,5,chr(0x82).chr(2));
  $check('opposite_single_bit_v'.$v,$blip['set_bits']===1
    &&$blip['bit_preview']===[0,0,1,0,0]);
  $empty=$parse('BitArray',$v,0,'');
  $check('empty_v'.$v,$empty['status']==='native_bitarray_record_complete'
    &&$empty['bit_count']===0&&$empty['rle_command_count']===0);
  $matrix=$parse('BitMatrix',$v,6,chr(6),$idx(3));
  $check('matrix_side_v'.$v,$matrix['status']==='native_bitmatrix_record_complete'
    &&$matrix['side']===3&&$matrix['bit_count']===6);
  $check('bad_matrix_side_v'.$v,$bad('BitMatrix',$v,6,chr(6),$idx(2)));
  $check('missing_second_rle_byte_v'.$v,$bad('BitArray',$v,5,chr(0x40)));
  $check('missing_rle_stream_v'.$v,$bad('BitArray',$v,5,''));
  $check('missing_matrix_side_v'.$v,$bad('BitMatrix',$v,6,chr(6)));
  $check('zero_length_non_progress_v'.$v,$bad('BitArray',$v,1,"\0\0"));
  $ext=$parse('BitArray',$v,3,chr(3),"\xfa");
  $check('extension_not_complete_v'.$v,$ext['status']==='native_bitarray_has_extension'
    &&$ext['extension_bytes']===1);
  $clip=$parse('BitArray',$v,3,chr(9));
  $check('epic_count_clamping_flagged_v'.$v,
     $clip['status']==='native_bitarray_rle_run_clipped'&&$clip['rle_run_clipped']===true
     &&$clip['bit_count']===3);
 }
 $extended=$parse('BitArray',69,300,chr(0x41).chr(44));
 $check('two_byte_300_run',$extended['status']==='native_bitarray_record_complete'
  &&$extended['bit_count']===300&&$extended['set_bits']===0
  &&count($extended['bit_preview'])===64);
 $max=$parse('BitArray',69,16383,chr(0x7f).chr(0xff));
 $check('maximum_14_bit_run',$max['status']==='native_bitarray_record_complete'
  &&$max['bit_count']===16383&&$max['rle_command_count']===1);
 $check('negative_num_bits',$bad('BitArray',69,-1,''));
 $check('negative_side',$bad('BitMatrix',69,6,chr(6),$idx(-1)));
 $check('invalid_long_run_truncation',$bad('BitArray',69,400,chr(0x41)));
}finally{foreach($paths as $p){@unlink($p);}}
echo json_encode(['ok'=>count($failed)===0,'checks'=>count($checks),
  'passed'=>count($checks)-count($failed),'failed'=>$failed,
  'scope'=>'Epic v1.200 / UT99 v400 UBitArray/UBitMatrix native archive RLE'],
  JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($failed?1:0);
