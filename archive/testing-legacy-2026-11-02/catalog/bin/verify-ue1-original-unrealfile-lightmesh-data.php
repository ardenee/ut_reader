#!/usr/bin/env php
<?php
/** FLightMeshIndex variable-length archived actor references, v22/v23. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileLightMeshDataReader as Light;
$checks=[];$fails=[];
$check=static function(string $label,bool $ok)use(&$checks,&$fails):void{
 $checks[$label]=$ok;if(!$ok)$fails[]=$label;
};
$make=static function(int $v):array{
 $names="LightMesh\0".pack('V',0)."MeshLight\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $header=$stack.pack('V3',1,1,1); // Num,Max,Bits
 $data=pack('V8',7,0xffffffff,2,16,8,32,1,1)
    .pack('C3',5,4,3).pack('V2',1,0);
 $nameOff=56;$hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($header);
 $tableOff=$dataOff+strlen($data);
 if(strlen($data)!==43)throw new RuntimeException('Wrong FLightMeshIndex serialized fixture');
 $entry=pack('vvVV',2,1,0,$hOff)
   .pack('V4',0,$dataOff,strlen($header),strlen($data));
 $sum=pack('a32V6',"Unrealfile\x1A",$v,1,2,$nameOff,$tableOff,0);
 return ['bytes'=>$sum.$names.$header.$data.$entry,
    'h'=>$hOff+16,'data'=>$dataOff,'table'=>$tableOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1lights97');
if(!is_string($tmp))throw new RuntimeException('No test fixture');
try{
 foreach([22,23] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $r=Light::read($tmp,true)['lightmesh_records'][0]??[];
  $item=$r['records'][0]??[];
  $check("v{$v}_source_member_and_count",
    ($r['status']??null)==='native_lightmesh_indices_decoded'
    && ($r['record_count']??null)===1
    && ($r['record_capacity']??null)===1
    && ($r['bits_ref_raw_one_based']??null)===1
    && ($r['serialized_data_bytes']??null)===43);
  $check("v{$v}_light_index_values_and_two_actor_refs",
    ($item['data_offset']??null)===7
    && ($item['texture_u_start']??null)===-1
    && ($item['mesh_u_size']??null)===16
    && ($item['mesh_v_size']??null)===8
    && ($item['num_static_lights']??null)===1
    && ($item['num_dynamic_lights']??null)===1
    && ($item['actor_refs_raw_one_based']??null)===[1,0]
    && ($r['runtime_lights_resolved']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
   $tmp,['format'=>'early_unrealfile','version'=>$v]
  );
  $s=$stage['native_metadata']['unrealfile_lightmesh_records']['lightmesh_records'][0]??[];
  $check("v{$v}_unverified_staging_preserves_light_refs",
    ($s['records'][0]['actor_refs_raw_one_based']??null)===[1,0]
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',17),$f['data']+24,4));
  try{Light::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_excess_static_lights_rejected",$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['data']+35,4));
  try{Light::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_actor_reference_outside_resmap_rejected",$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',44),$f['table']+24,4));
  try{Light::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_extra_trailing_data_rejected",$bad);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fails===[],'passed'=>count($checks)-count($fails),
  'total'=>count($checks),'failures'=>$fails],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
