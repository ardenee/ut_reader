#!/usr/bin/env php
<?php
/** Source-defined UE1 1996 v9/v10 FPoly /Zp4 native record fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourcePolysReader as Polys;
$checks=[];$bad=[];$check=static function(string $k,bool $ok)use(&$checks,&$bad):void{
 $checks[$k]=$ok;if(!$ok)$bad[]=$k;
};
$fixture=static function(int $version):array{
 $prefix="Unreal Resource\x1A\0";$names=pack('a32V','POLYS',0);
 $nameOff=strlen($prefix);$dataOff=$nameOff+strlen($names);
 $vec=static fn(float $x,float $y,float $z,int $w):string=>pack('g3V',$x,$y,$z,$w);
 $resource=$vec(10.0,20.0,30.0,100)
   .$vec(0.0,0.0,1.0,101)
   .$vec(2.0,0.0,0.0,102)
   .$vec(0.0,3.0,0.0,103)
   .$vec(1.0,2.0,3.0,104)
   .str_repeat("\0",15*16)
   .pack('V3v2V3C4',0x20,42,43,1,2,1,0xffffffff,5,6,7,8,9);
 if(strlen($resource)!==352)throw new RuntimeException('Invalid native FPoly 352-byte fixture');
 $exportOff=$dataOff+strlen($resource);$export=pack('a32VVV','PolyOne',1,20,84);
 $headerOff=$exportOff+strlen($export);
 $header=pack('V2a32V9',0,20,'PolyOne',0,0,1,0xffffffff,0,$dataOff,352,0,0)
   .pack('V2',1,1);
 if(strlen($header)!==84)throw new RuntimeException('Invalid UPolys header');
 $tail=pack('V9',$version,1,0,1,$nameOff,0,$exportOff,$headerOff,84)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$resource.$export.$header.'[SUMMARY]'.$tail,
   'data'=>$dataOff,'header'=>$headerOff,'export'=>$exportOff];
};
$temp=tempnam(sys_get_temp_dir(),'ue1polys96');
if(!is_string($temp))throw new RuntimeException('Cannot allocate test fixture');
try{
 foreach([9,10] as $v){
   $f=$fixture($v);file_put_contents($temp,$f['bytes']);
   $parsed=Polys::read($temp,true)['polys_resources'][0]??[];
   $poly=$parsed['records'][0]??[];
   $check("v{$v}_native_fpoly_layout",
      ($parsed['status']??null)==='native_polys_records_decoded'
      && ($parsed['record_stride']??null)===352
      && ($parsed['record_count']??null)===1);
   $check("v{$v}_vector_word_and_vertex",
      ($poly['base']['xyz']??null)===[10.0,20.0,30.0]
      && ($poly['base']['w_or_flags_raw_word']??null)===100
      && ($poly['normal']['xyz']??null)===[0.0,0.0,1.0]
      && ($poly['active_vertices'][0]['xyz']??null)===[1.0,2.0,3.0]
      && ($poly['vertex_count']??null)===1);
   $check("v{$v}_native_polygon_references",
      ($poly['poly_flags']??null)===0x20
      && ($poly['brush_reference_raw_word']??null)===42
      && ($poly['texture_reference_raw_word']??null)===43
      && ($poly['group_name_index_raw_word']??null)===1
      && ($poly['item_name_index_raw_word']??null)===2
      && ($poly['link_index']??null)===-1
      && ($poly['brush_poly_index']??null)===5
      && ($poly['pan_u']??null)===6 && ($poly['zones']??null)===[8,9]);
   $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $temp,['format'=>'early_unreal_resource','version'=>$v]);
   $staged=$stage['native_metadata']['resource_polys_records']['polys_resources'][0]??[];
   $check("v{$v}_staging_keeps_source_unverified",
     ($staged['record_count']??null)===1
     && ($staged['runtime_references_resolved']??true)===false
     && ($stage['parse_complete']??true)===false);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',17),$f['data']+336,4));
   try{Polys::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$v}_too_many_vertices_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',2),$f['header']+76,4));
   try{Polys::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$v}_count_over_capacity_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',80),$f['export']+40,4));
   $partial=Polys::read($temp,false)['polys_resources'][0]??[];
   try{Polys::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$v}_short_header_stays_incomplete",
      ($partial['status']??null)==='incomplete_source_polys_header'&&$reject);
 }
}finally{@unlink($temp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
