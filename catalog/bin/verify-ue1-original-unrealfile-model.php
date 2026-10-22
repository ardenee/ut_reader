#!/usr/bin/env php
<?php
/** Epic v0.867 UModel::SerializeHeader + UPrimitive bounds. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileModelHeaderReader as Model;
$checks=[];$bad=[];
$check=static function(string $name,bool $yes)use(&$checks,&$bad):void{
 $checks[$name]=$yes;if(!$yes)$bad[]=$name;
};
$make=static function(int $version):array{
 $names="Model\0".pack('V',0)."TestModel\0".pack('V',0);
 $nameOff=56;
 $bound=static function(float $radius):string{
  return pack('g6Vg4',-1.0,-2.0,-3.0,1.0,2.0,3.0,1,0.0,0.0,0.0,$radius);
 };
 $scale=static fn(float $f,int $axis):string=>
  pack('g3gV',$f,$f,$f,0.5,$axis);
 $data=$bound(8.0)
  .pack('V9',1,0,1,0,0,1,0,0,1)
  .pack('g3',10.0,20.0,30.0)
  .pack('V3',0xffffffff,0x80000000,42)
  .pack('g3',1.0,2.0,3.0)
  .$scale(1.0,2)
  .pack('V4',2,0x22,0x10,0x04)
  .pack('V',1)
  .pack('g3',4.0,5.0,6.0)
  .$scale(2.0,3)
  .$scale(3.0,4)
  .$bound(16.0);
 if(strlen($data)!==252)throw new RuntimeException('UModel native source fixture is not 252 bytes');
 $header=pack('V2',0,0).str_repeat("\xff",8).$data;
 $headOff=$nameOff+strlen($names);$objectsOff=$headOff+strlen($header);
 $object=pack('vvVV',2,1,0,$headOff).pack('V4',0,0,268,0);
 $summary=pack('a32V6',"Unrealfile\x1A",$version,1,2,$nameOff,$objectsOff,0);
 return ['bytes'=>$summary.$names.$header.$object,
  'member'=>$headOff+16,'table'=>$objectsOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1model97');
if(!is_string($tmp))throw new RuntimeException('Unable to create fixture');
try{
 foreach([22,23] as $version){
  $fx=$make($version);file_put_contents($tmp,$fx['bytes']);
  $rows=Model::read($tmp,true)['model_headers'];$r=$rows[0]??[];
  $check("v{$version}_native_bounds_and_size",
   count($rows)===1
   && ($r['status']??null)==='native_model_header_decoded'
   && ($r['native_header_bytes']??null)===252
   && ($r['local_bound']['sphere_radius']??null)===8.0
   && ($r['transformed_bound']['sphere_radius']??null)===16.0
   && ($r['local_bound']['is_valid']??null)===1);
  $check("v{$version}_model_source_refs_and_transforms",
   ($r['child_object_refs']['Vectors']['raw_one_based_ref']??null)===1
   && ($r['child_object_refs']['Points']['raw_one_based_ref']??null)===0
   && ($r['child_object_refs']['LeafHulls']['raw_one_based_ref']??null)===1
   && ($r['location']??null)===[10.0,20.0,30.0]
   && ($r['rotation_signed']??null)===[-1,-2147483648,42]
   && ($r['pre_pivot']??null)===[1.0,2.0,3.0]
   && ($r['scale']['xyz']??null)===[1.0,1.0,1.0]
   && ($r['scale']['sheer_axis']??null)===2);
  $check("v{$version}_post_scale_and_flags",
   ($r['csg_operator']??null)===2
   && ($r['color_raw_word']??null)===0x22
   && ($r['polygon_flags']??null)===0x10
   && ($r['model_flags']??null)===0x04
   && ($r['root_outside']??null)===1
   && ($r['post_pivot']??null)===[4.0,5.0,6.0]
   && ($r['post_scale']['sheer_axis']??null)===3
   && ($r['temporary_scale']['sheer_axis']??null)===4
   && ($r['runtime_model_constructed']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unrealfile','version'=>$version]);
  $s=$stage['native_metadata']['unrealfile_model_headers']['model_headers'][0]??[];
  $check("v{$version}_unverified_native_model_staging",
   ($s['child_object_refs']['Vectors']['raw_one_based_ref']??null)===1
   && ($stage['parse_complete']??true)===false);
  // The first model reference must be a valid one-based ResMap index.
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['member']+44,4));
  try{Model::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
  $check("v{$version}_invalid_resmap_rejected",$rejected);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',3),$fx['member']+24,4));
  try{Model::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
  $check("v{$version}_invalid_bound_validity_rejected",$rejected);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',264),$fx['table']+20,4));
  $partial=Model::read($tmp,false)['model_headers'][0]??[];
  try{Model::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
  $check("v{$version}_short_header_never_verified",
   ($partial['status']??null)==='incomplete_original_model_header'&&$rejected);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
