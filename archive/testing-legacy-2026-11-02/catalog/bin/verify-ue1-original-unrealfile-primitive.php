#!/usr/bin/env php
<?php
/** UE1 May v0.86x and July v0.867 UPrimitive identical 41B FArchive bound. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePrimitiveHeaderReader as Primitive;
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;
 if(!$ok)$fail[]=$name;
};
$fixture=static function(int $v,int $valid=1):array{
 $names="Primitive\0".pack('V',0)."BasePrimitive\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $bound=pack('g6Cg4',-1.0,-2.0,-3.0,1.0,2.0,3.0,$valid,2.0,3.0,4.0,5.0);
 if(strlen($bound)!==41)throw new RuntimeException('Wrong source primitive bound fixture');
 $header=$stack.$bound;$nameOff=56;
 $headerOff=$nameOff+strlen($names);$objOff=$headerOff+strlen($header);
 $table=pack('vvVV',2,1,0,$headerOff).pack('V4',0,0,strlen($header),0);
 $summary=pack('a32V6',"Unrealfile\x1a",$v,1,2,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$header.$table,
     'bound'=>$headerOff+16,'table'=>$objOff,'header_size'=>strlen($header)];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1prim97');
if(!is_string($tmp))throw new RuntimeException('No fixture temp');
try{
 foreach([22,23] as $v){
   $f=$fixture($v);
   file_put_contents($tmp,$f['bytes']);
   $all=Primitive::read($tmp,true)['primitive_headers']??[];
   $p=$all[0]??[];
   $key="v{$v}";
   $check($key.'_source_native_extent_41',
       count($all)===1&&($p['status']??null)==='native_primitive_header_decoded'
       &&($p['native_member_bytes']??null)===41
       &&($p['unconsumed_header_bytes']??null)===0
       &&($p['unconsumed_data_bytes']??null)===0);
   $check($key.'_source_bounding_fields',
       ($p['local_bound']['min']??null)===[-1.0,-2.0,-3.0]
       &&($p['local_bound']['max']??null)===[1.0,2.0,3.0]
       &&($p['local_bound']['sphere_center']??null)===[2.0,3.0,4.0]
       &&($p['local_bound']['sphere_radius']??null)===5.0
       &&($p['local_bound']['is_valid']??null)===1);
   $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
       $tmp,['format'=>'early_unrealfile','version'=>$v]);
   $sp=$staged['native_metadata']['unrealfile_primitive_headers']['primitive_headers'][0]??[];
   $check($key.'_staging_typed_without_fake_completion',
       ($sp['status']??null)==='native_primitive_header_decoded'
       &&($sp['native_member_bytes']??null)===41
       &&($staged['parse_complete']??true)===false);
   file_put_contents($tmp,substr_replace($f['bytes'],"\x02",$f['bound']+24,1));
   $raw=Primitive::read($tmp,true)['primitive_headers'][0]??[];
   $check($key.'_source_raw_byte_validity_not_boolean',
       ($raw['local_bound']['is_valid']??null)===2
       &&($raw['status']??null)==='native_primitive_header_decoded');
   file_put_contents($tmp,substr_replace($f['bytes'],pack('V',56),$f['table']+20,4));
   $loose=Primitive::read($tmp,false)['primitive_headers'][0]??[];
   try{Primitive::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
   $check($key.'_truncated_header_not_claimed',
       ($loose['status']??null)==='incomplete_native_primitive_header'&&$bad);
   file_put_contents($tmp,substr_replace($f['bytes'],pack('V',58),$f['table']+20,4));
   $loose=Primitive::read($tmp,false)['primitive_headers'][0]??[];
   try{Primitive::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
   $check($key.'_extra_header_not_claimed_complete',
       ($loose['status']??null)==='native_primitive_extra_serialized_bytes'&&$bad);
   $validZero=$fixture($v,0);
   file_put_contents($tmp,$validZero['bytes']);
   $zero=Primitive::read($tmp,true)['primitive_headers'][0]??[];
   $check($key.'_zero_validity_is_valid_source_value',
       ($zero['local_bound']['is_valid']??null)===0
       &&($zero['status']??null)==='native_primitive_header_decoded');
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($checks)-count($fail),
 'total'=>count($checks),'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
