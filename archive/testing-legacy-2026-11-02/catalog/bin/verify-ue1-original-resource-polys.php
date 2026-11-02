#!/usr/bin/env php
<?php
/** Source-defined v9/v10 Win32 FPoly 272-byte record acceptance. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourcePolysReader as Polys;
$checks=[];$fail=[];
$check=static function(string $id,bool $ok)use(&$checks,&$fail):void{
 $checks[$id]=$ok;if(!$ok)$fail[]=$id;
};
$make=static function(int $v):array{
 $pre="Unreal Resource\x1A\0";$names=pack('a32V','POLYS',0);
 $nameOff=strlen($pre);
 $vec=pack('g12',1,2,3,0,0,1,1,0,0,0,1,0);
 $vertices=pack('g6',0,0,0,10,0,0).str_repeat("\0",14*12);
 $raw=$vec.$vertices.pack('V3',0x12,2,3).pack('v2',1,1)
    .pack('V3',2,0xffffffff,0).pack('C4',5,6,7,8);
 if(strlen($raw)!==272)throw new RuntimeException('FPoly source fixture wrong length');
 $dataOff=$nameOff+strlen($names);$exportOff=$dataOff+strlen($raw);
 $exp=pack('a32VVV','PolyOne',1,20,84);$headOff=$exportOff+strlen($exp);
 $h=pack('V2a32V9',0,20,'PolyOne',0,0,1,0xffffffff,0,$dataOff,272,0,0)
   .pack('V2',1,1);
 if(strlen($h)!==84)throw new RuntimeException('Wrong native UPolys header');
 $tail=pack('V9',$v,1,0,1,$nameOff,0,$exportOff,$headOff,84)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$pre.$names.$raw.$exp.$h.'[SUMMARY]'.$tail,
   'data'=>$dataOff,'head'=>$headOff,'export'=>$exportOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1poly96');
if(!is_string($tmp))throw new RuntimeException('No temp poly fixture');
try{
 foreach([9,10] as $ver){
  $fx=$make($ver);file_put_contents($tmp,$fx['bytes']);
  $row=Polys::read($tmp,true)['polygon_resources'][0]??[];
  $poly=$row['polygon_records'][0]??[];
  $check("v{$ver}_native_polys_shape",
   ($row['status']??null)==='native_polygon_records_decoded'
   && ($row['record_count']??null)===1
   && ($row['record_size_bytes']??null)===272
   && ($poly['num_vertices']??null)===2);
  $check("v{$ver}_vertices_refs_and_group_names",
   ($poly['base']??null)===[1.0,2.0,3.0]
   && ($poly['vertices'][1]??null)===[10.0,0.0,0.0]
   && ($poly['poly_flags']??null)===0x12
   && ($poly['brush_raw_reference']??null)===2
   && ($poly['texture_raw_reference']??null)===3
   && ($poly['group_name_raw_word']??null)===1
   && ($poly['item_name_raw_word']??null)===1);
  $check("v{$ver}_pans_links_and_zones",
   ($poly['linked_polygon_index']??null)===-1
   && ($poly['pan_u']??null)===5&&($poly['pan_v']??null)===6
   && ($poly['zones']??null)===[7,8]
   && ($poly['runtime_reference_fixups_evaluated']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
    $tmp,['format'=>'early_unreal_resource','version'=>$ver]);
  $s=$stage['native_metadata']['resource_polygon_records']['polygon_resources'][0]??[];
  $check("v{$ver}_staging_unverified",
   ($s['record_count']??null)===1&&($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',17),$fx['data']+256,4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_too_many_polygon_vertices_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['head']+76,4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_bad_num_max_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',271),$fx['head']+64,4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_bad_serialized_record_size_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($checks)-count($fail),
 'total'=>count($checks),'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
