#!/usr/bin/env php
<?php
/** Original UE1 v9/v10 native ULightMesh and FLightMeshIndex fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceLightMeshReader as Light;
$checks=[];$bad=[];$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{
 $checks[$n]=$ok;if(!$ok)$bad[]=$n;
};
$make=static function(int $ver):array{
 $prefix="Unreal Resource\x1A\0";$names=pack('a32V','LIGHT',0);
 $nameOff=strlen($prefix);
 $index=pack('V8',100,10,20,4,5,32,1,0)
    .pack('V16',7,...array_fill(0,15,0))
    .pack('C4',5,2,3,0);
 if(strlen($index)!==100)throw new RuntimeException('Native FLightMeshIndex fixture size wrong');
 $payload=$index.str_repeat("\xaa",16);
 $dataOff=$nameOff+strlen($names);$expOff=$dataOff+strlen($payload);
 $ex=pack('a32VVV','LightOne',1,19,84);
 $headerOff=$expOff+strlen($ex);
 $h=pack('V2a32V9',0,19,'LightOne',0,0,1,0xffffffff,0,$dataOff,116,0,0)
    .pack('V2',1,116);
 $trailer=pack('V9',$ver,1,0,1,$nameOff,0,$expOff,$headerOff,84)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$payload.$ex.$h.'[SUMMARY]'.$trailer,
   'data'=>$dataOff,'header'=>$headerOff,'export'=>$expOff];
};
$temp=tempnam(sys_get_temp_dir(),'ue1Light96');
if(!is_string($temp))throw new RuntimeException('No native light fixture');
try{
 foreach([9,10] as $ver){
   $f=$make($ver);file_put_contents($temp,$f['bytes']);
   $r=Light::read($temp,true)['light_meshes'][0]??[];
   $row=$r['indexes'][0]??[];
   $check("v{$ver}_light_mesh_header",
     ($r['status']??null)==='native_lightmesh_indices_decoded'
     && ($r['index_count']??null)===1
     && ($r['index_record_stride']??null)===100
     && ($r['data_bytes']??null)===116
     && ($r['remaining_light_samples_bytes']??null)===16
     && ($r['lighting_samples_decoded']??true)===false);
   $check("v{$ver}_index_record_values",
     ($row['data_offset']??null)===100
     && ($row['texture_u_start']??null)===10
     && ($row['mesh_u_size']??null)===4
     && ($row['num_static_lights']??null)===1
     && ($row['light_actor_indices_raw'][0]??null)===7
     && ($row['mesh_shift']??null)===5
     && ($row['runtime_actor_references_resolved']??true)===false);
   $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $temp,['format'=>'early_unreal_resource','version'=>$ver]);
   $record=$stage['native_metadata']['resource_lightmesh_records']['light_meshes'][0]??[];
   $check("v{$ver}_staging_does_not_pretend_bulk",
      ($record['index_count']??null)===1
      && ($record['lighting_samples_decoded']??true)===false
      && ($stage['parse_complete']??true)===false);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',2),$f['header']+76,4));
   try{Light::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_index_count_exceeds_data_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',117),$f['data'],4));
   try{Light::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_index_data_offset_out_of_range_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',17),$f['data']+24,4));
   try{Light::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_over_16_static_lights_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',80),$f['export']+40,4));
   $incomplete=Light::read($temp,false)['light_meshes'][0]??[];
   try{Light::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$ver}_incomplete_native_header_rejected",
     ($incomplete['status']??null)==='incomplete_source_lightmesh_header'&&$reject);
 }
}finally{@unlink($temp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
