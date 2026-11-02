#!/usr/bin/env php
<?php
/** UE1 1996 native UBspNodes and UBspSurfs source contract and mutations. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspReader as Bsp;
$checks=[];$bad=[];$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{
 $checks[$n]=$ok;if(!$ok)$bad[]=$n;
};
$make=static function(int $version,int $type):array{
 $prefix="Unreal Resource\x1A\0";
 $name=pack('a32V','BSP',0);$nameOff=strlen($prefix);
 if($type===17){
    $data=pack('g3V',1.0,-2.0,3.5,0x11223344)
       .pack('V2',1,0)
       .pack('V7',0x80,0,0,0xffffffff,1,0xffffffff,0)
       .pack('C4',1,2,3,4)
       .pack('V2',0xffffffff,8);
    $extension=pack('V4',1,1,0,0)
      .pack('V2',0xffffffff,0)
      .pack('V2',1,0)
      .pack('V2',2,0)
      .str_repeat("\0",63*24);
    $headerBytes=1636;
    if(strlen($extension)!==1552||strlen($data)!==64)
        throw new RuntimeException('Source-defined BSP node fixture size mismatch');
 }else{
    $data=pack('V9',5,6,0x20,0,1,2,3,4,5)
      .pack('C2v',10,20,0)
      .pack('Vv2',0xffffffff,0xfffe,4);
    $extension='';$headerBytes=84;
    if(strlen($data)!==48)throw new RuntimeException('Bad native FBspSurf fixture');
 }
 $dataOff=$nameOff+strlen($name);$expOff=$dataOff+strlen($data);
 $export=pack('a32VVV','BspOne',1,$type,$headerBytes);
 $headOff=$expOff+strlen($export);
 $header=pack('V2a32V9',0,$type,'BspOne',0,0,1,0xffffffff,0,$dataOff,strlen($data),0,0)
    .pack('V2',1,1).$extension;
 if(strlen($header)!==$headerBytes)throw new RuntimeException('Wrong BSP native header');
 $summary=pack('V9',$version,1,0,1,$nameOff,0,$expOff,$headOff,$headerBytes)
    .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$name.$data.$export.$header.'[SUMMARY]'.$summary,
  'data'=>$dataOff,'export'=>$expOff,'header'=>$headOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1Bsp96');
if(!is_string($tmp))throw new RuntimeException('Temp fixture unavailable');
try {
 foreach([9,10] as $ver)foreach([17,18] as $type){
  $f=$make($ver,$type);file_put_contents($tmp,$f['bytes']);
  $r=Bsp::read($tmp,true)['bsp_resources'][0]??[];
  $k="v{$ver}_type{$type}";
  $check("{$k}_source_header_records",
      ($r['status']??null)==='native_bsp_records_decoded'
      && ($r['record_count']??null)===1
      && ($r['record_capacity']??null)===1
      && ($r['record_stride']??null)===($type===17?64:48)
      && ($r['header_bytes']??null)===($type===17?1636:84)
      && ($r['runtime_references_resolved']??true)===false);
  if($type===17){
   $n=$r['records'][0]??[];
   $check("{$k}_native_node_and_zones",
     ($r['zone_count']??null)===1
     && ($r['zones'][0]['actor_index']??null)===-1
     && ($r['zones'][0]['connectivity_mask_le_hex']??null)==='0100000000000000'
     && ($n['plane_xyz']??null)===[1.0,-2.0,3.5]
     && ($n['plane_w_or_flags_raw_word']??null)===0x11223344
     && ($n['back_index']??null)===-1
     && ($n['vertex_count']??null)===3
     && ($n['dynamic_indices']??null)===[-1,8]);
  }else{
   $s=$r['records'][0]??[];
   $check("{$k}_native_surf_fields",
     ($s['texture_reference_raw_word']??null)===5
     && ($s['brush_reference_raw_word']??null)===6
     && ($s['poly_flags']??null)===0x20
     && ($s['pan_u']??null)===10
     && ($s['pan_v']??null)===20
     && ($s['actor_index']??null)===-1
     && ($s['last_start_y']??null)===-2
     && ($s['last_end_y']??null)===4);
  }
  $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
       $tmp,['format'=>'early_unreal_resource','version'=>$ver]
  );
  $part=$staged['native_metadata']['resource_bsp_records']['bsp_resources'][0]??[];
  $check("{$k}_staging_unverified_bsp",
    ($part['record_count']??null)===1
    && ($part['runtime_references_resolved']??true)===false
    && ($staged['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header']+76,4));
  try{Bsp::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$k}_native_count_over_capacity_rejected",$reject);
  $wrong=$type===17?1600:80;
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',$wrong),$f['export']+40,4));
  $partial=Bsp::read($tmp,false)['bsp_resources'][0]??[];
  try{Bsp::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$k}_short_header_unverified",($partial['status']??null)==='incomplete_original_bsp_header'&&$reject);
  if($type===17){
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',65),$f['header']+84,4));
    try{Bsp::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("{$k}_too_many_zones_rejected",$reject);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('C',17),$f['data']+54,1));
    try{Bsp::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("{$k}_too_many_node_vertices_rejected",$reject);
  }
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
