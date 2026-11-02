#!/usr/bin/env php
<?php
/** Source-defined Unreal v9/v10 ULightMesh FLightMeshIndex record. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceLightMeshReader as LM;
$checks=[];$bad=[];
$check=static function(string $id,bool $yes)use(&$checks,&$bad):void{
 $checks[$id]=$yes;if(!$yes)$bad[]=$id;
};
$make=static function(int $v):array{
 $pre="Unreal Resource\x1A\0";$name=pack('a32V','LIGHT',0);
 $nameOff=strlen($pre);
 $index=pack('V8',100,2,3,4,5,32,1,0)
   .pack('V16',0xffffffff,...array_fill(0,15,0))
   .pack('C4',5,4,3,0);
 if(strlen($index)!==100)throw new RuntimeException('Invalid source light index fixture');
 $data=$index."\x11\x22\x33";
 $dataOff=$nameOff+strlen($name);$exportOff=$dataOff+strlen($data);
 $exp=pack('a32VVV','LightOne',1,19,84);
 $hOff=$exportOff+strlen($exp);
 $hdr=pack('V2a32V9',0,19,'LightOne',0,0,1,0xffffffff,0,$dataOff,103,0,0)
    .pack('V2',1,103);
 if(strlen($hdr)!==84)throw new RuntimeException('Bad native light header fixture');
 $tail=pack('V9',$v,1,0,1,$nameOff,0,$exportOff,$hOff,84)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$pre.$name.$data.$exp.$hdr.'[SUMMARY]'.$tail,
   'header'=>$hOff,'export'=>$exportOff,'data'=>$dataOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1light');
if(!is_string($tmp))throw new RuntimeException('Cannot create test path');
try{
 foreach([9,10] as $v){
  $fx=$make($v);file_put_contents($tmp,$fx['bytes']);
  $read=LM::read($tmp,true)['lightmesh_records'][0]??[];
  $rec=$read['index_records'][0]??[];
  $check("v{$v}_native_header_and_data_regions",
   ($read['status']??null)==='native_lightmesh_index_records_decoded'
   && ($read['index_count']??null)===1
   && ($read['data_bytes']??null)===103
   && ($read['index_record_stride']??null)===100
   && ($read['uninterpreted_sample_bytes']??null)===3);
  $check("v{$v}_source_light_index_words",
   ($rec['data_offset']??null)===100
   && ($rec['texture_u_start']??null)===2
   && ($rec['mesh_u_size']??null)===4
   && ($rec['mesh_spacing']??null)===32
   && ($rec['num_static_lights']??null)===1);
  $check("v{$v}_actor_index_and_bit_fields",
   ($rec['light_actor_raw_indices'][0]??null)===-1
   && ($rec['mesh_shift']??null)===5
   && ($rec['mesh_u_bits']??null)===4
   && ($rec['mesh_v_bits']??null)===3
   && ($rec['runtime_actor_references_resolved']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
   $tmp,['format'=>'early_unreal_resource','version'=>$v]);
  $staged=$stage['native_metadata']['resource_lightmesh_records']['lightmesh_records'][0]??[];
  $check("v{$v}_staging_does_not_mark_samples_decoded",
   ($staged['index_count']??null)===1
   && ($staged['sample_bytes_decoded']??true)===false
   && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['header']+76,4));
  try{LM::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_too_many_indices_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',104),$fx['header']+80,4));
  try{LM::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_query_data_size_mismatch_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',83),$fx['export']+40,4));
  $partial=LM::read($tmp,false)['lightmesh_records'][0]??[];
  try{LM::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_short_header_not_promoted",
   ($partial['status']??null)==='incomplete_native_lightmesh_header'&&$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
