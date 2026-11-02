#!/usr/bin/env php
<?php
/** Original UE1 1996 UBspSurfs 48-byte Win32 FBspSurf source contract. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspSurfsReader as Surf;
$checks=[];$fails=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fails):void{
 $checks[$name]=$ok;if(!$ok)$fails[]=$name;
};
$fixture=static function(int $v):array{
 $prefix="Unreal Resource\x1A\0";
 $names=pack('a32V','SURFS',0);$nameOff=strlen($prefix);
 $raw=pack('V9',1,2,0x12,0,1,2,3,0xffffffff,4)
   .pack('C2',5,7)."\0\0".pack('Vv2',0xfffffffe,0xff9c,200);
 if(strlen($raw)!==48)throw new RuntimeException('FBspSurf native fixture wrong size');
 $dataOff=$nameOff+strlen($names);
 $exportOff=$dataOff+strlen($raw);
 $export=pack('a32VVV','SurfaceOne',1,18,84);
 $headOff=$exportOff+strlen($export);
 $head=pack('V2a32V9',0,18,'SurfaceOne',0,0,1,0xffffffff,0,$dataOff,48,0,0)
    .pack('V2',1,1);
 if(strlen($head)!==84)throw new RuntimeException('Bad native UDatabase header');
 $tail=pack('V9',$v,1,0,1,$nameOff,0,$exportOff,$headOff,84)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$raw.$export.$head.'[SUMMARY]'.$tail,
    'header'=>$headOff,'export'=>$exportOff,'data'=>$dataOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1surfs');
if(!is_string($tmp))throw new RuntimeException('Cannot create native surface fixture');
try{
 foreach([9,10] as $v){
    $fx=$fixture($v);file_put_contents($tmp,$fx['bytes']);
    $out=Surf::read($tmp,true)['bsp_surface_records'][0]??[];
    $item=$out['surface_records'][0]??[];
    $check("v{$v}_native_num_max_and_record_size",
        ($out['status']??null)==='native_bsp_surfaces_decoded'
        && ($out['record_count']??null)===1
        && ($out['serialized_record_bytes']??null)===48);
    $check("v{$v}_refs_and_native_surface_fields",
        ($item['texture_reference_raw_word']??null)===1
        && ($item['brush_reference_raw_word']??null)===2
        && ($item['poly_flags']??null)===0x12
        && ($item['v_normal']??null)===1
        && ($item['i_light_mesh']??null)===-1
        && ($item['i_brush_poly']??null)===4
        && ($item['runtime_resource_references_resolved']??true)===false);
    $check("v{$v}_pan_actor_and_transient_span_bounds",
        ($item['pan_u']??null)===5&&($item['pan_v']??null)===7
        && ($item['i_actor']??null)===-2
        && ($item['last_start_y']??null)===-100
        && ($item['last_end_y']??null)===200
        && ($item['alignment_bytes_hex']??null)==='0000');
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
         $tmp,['format'=>'early_unreal_resource','version'=>$v]);
    $stored=$stage['native_metadata']['resource_bsp_surface_records']['bsp_surface_records'][0]??[];
    $check("v{$v}_native_staging_remains_unverified",
        ($stored['record_count']??null)===1
        && ($stage['parse_complete']??true)===false
        && ($stored['runtime_resource_references_resolved']??true)===false);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['header']+76,4));
    try{Surf::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$v}_count_gt_capacity_rejected",$reject);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',47),$fx['header']+64,4));
    try{Surf::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$v}_bad_serialized_stride_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fails===[],'passed'=>count($checks)-count($fails),
'total'=>count($checks),'failures'=>$fails],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
