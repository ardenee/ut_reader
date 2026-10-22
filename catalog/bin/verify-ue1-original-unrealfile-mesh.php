#!/usr/bin/env php
<?php
/** Source-defined original v0.867 UPrimitive/UMesh archive header. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileMeshHeaderReader as Mesh;
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$fixture=static function(int $v):array{
 $names="Mesh\0".pack('V',0)."FirstMesh\0".pack('V',0);
 $nameOff=56;
 $bound=pack('g6Vg4',-2.0,-3.0,-4.0,2.0,3.0,4.0,1,0.0,1.0,2.0,7.0);
 $refs=pack('V8',1,0,1,0,0,1,0,0);
 $counts=pack('V4',24,4,0x1234,0x5678);
 $vec=pack('g3',1.0,2.0,3.0).pack('g3',-1.0,-2.0,-3.0);
 $rot=pack('V3',0xffffffff,100,0x80000000);
 $edit=pack('V2',0xffffffff,2);
 $native=$bound.$refs.$counts.$vec.$rot.$edit;
 if(strlen($native)!==136)throw new RuntimeException('Wrong UMesh native fixture member count');
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $header=$stack.$native;$headOff=$nameOff+strlen($names);
 $objectsOff=$headOff+strlen($header);
 $object=pack('vvVV',2,1,0,$headOff).pack('V4',0,0,152,0);
 $summary=pack('a32V6',"Unrealfile\x1A",$v,1,2,$nameOff,$objectsOff,0);
 return ['bytes'=>$summary.$names.$header.$object,'native'=>$headOff+16,
     'table'=>$objectsOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1mesh97');
if(!is_string($tmp))throw new RuntimeException('Unable to create fixture');
try{
 foreach([22,23] as $v){
  $f=$fixture($v);file_put_contents($tmp,$f['bytes']);
  $rows=Mesh::read($tmp,true)['mesh_headers'];$r=$rows[0]??[];
  $check("v{$v}_primitive_bounding_volume",
   count($rows)===1
   && ($r['status']??null)==='native_mesh_header_decoded'
   && ($r['native_header_bytes']??null)===136
   && ($r['local_bound']['min']??null)===[-2.0,-3.0,-4.0]
   && ($r['local_bound']['max']??null)===[2.0,3.0,4.0]
   && ($r['local_bound']['sphere_radius']??null)===7.0);
  $check("v{$v}_typed_children_flags_vectors",
   ($r['child_object_refs']['Verts']['raw_one_based_ref']??null)===1
   && ($r['child_object_refs']['Tris']['raw_one_based_ref']??null)===0
   && ($r['child_object_refs']['Connects']['raw_one_based_ref']??99)===0
   && ($r['frame_vertices']??null)===24
   && ($r['animation_frames']??null)===4
   && ($r['and_flags']??null)===0x1234
   && ($r['or_flags']??null)===0x5678
   && ($r['scale']??null)===[1.0,2.0,3.0]
   && ($r['origin']??null)===[-1.0,-2.0,-3.0]);
  $check("v{$v}_signed_rotation_and_edit_fields",
   ($r['rotation_signed']??null)===[-1,100,-2147483648]
   && ($r['current_polygon']??null)===-1
   && ($r['current_vertex']??null)===2
   && ($r['runtime_mesh_constructed']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
    $tmp,['format'=>'early_unrealfile','version'=>$v]);
  $s=$stage['native_metadata']['unrealfile_mesh_headers']['mesh_headers'][0]??[];
  $check("v{$v}_typed_unverified_staging",
   ($s['rotation_signed'][0]??null)===-1
   && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['native']+44,4));
  try{Mesh::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_out_of_bounds_resmap_rejected",$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',3),$f['native']+24,4));
  try{Mesh::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_invalid_bbox_flag_rejected",$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',0xffffffff),$f['native']+76,4));
  try{Mesh::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_negative_vertex_count_rejected",$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',148),$f['table']+20,4));
  $loose=Mesh::read($tmp,false)['mesh_headers'][0]??[];
  try{Mesh::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_short_header_never_complete",
   ($loose['status']??null)==='incomplete_original_mesh_header'&&$bad);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($checks)-count($fail),
 'total'=>count($checks),'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
