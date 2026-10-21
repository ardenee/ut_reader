#!/usr/bin/env php
<?php
/** Epic v0.84a 1996 UMesh six native capacity-bounded sections. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceMeshReader as Mesh;
$checks=[];$errors=[];
$check=static function(string $k,bool $yes)use(&$checks,&$errors):void{
 $checks[$k]=$yes;if(!$yes)$errors[]=$k;
};
$make=static function(int $ver):array{
 $prefix="Unreal Resource\x1A\0";
 $names=pack('a32V','MESH',0);$nameOff=strlen($prefix);
 $triangle=pack('v3C2C6C2',0,0,0,1,2,10,20,30,40,50,60,3,4);
 $vertex=pack('V',1|(2046<<11)|(3<<22));
 $index=pack('V2',1,0);
 $links=pack('v',0);
 $bound=pack('g6',-1.0,-2.0,-3.0,1.0,2.0,3.0);
 $seq=pack('v2V3',1,0,0,1,30);
 $raw=$triangle.$vertex.$index.$links.$bound.$seq;
 if(strlen($raw)!==70)throw new RuntimeException('UMesh section source fixture must be 70 bytes');
 $dataOff=$nameOff+strlen($names);$exportOff=$dataOff+strlen($raw);
 $ex=pack('a32VVV','MeshOne',1,14,164);$hOff=$exportOff+strlen($ex);
 $hdr=pack('V2a32V9',0,14,'MeshOne',0,0,1,0xffffffff,0,$dataOff,70,0,0)
    .pack('g3',1.0,2.0,3.0)
    .pack('V3',100,200,300)
    .pack('g6',-5.0,-6.0,-7.0,5.0,6.0,7.0)
    .pack('V10',1,1,1,1,1,1,1,1,1,1);
 if(strlen($hdr)!==164)throw new RuntimeException('UMesh header source fixture must be 164 bytes');
 $tail=pack('V9',$ver,1,0,1,$nameOff,0,$exportOff,$hOff,164)
    .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$raw.$ex.$hdr.'[SUMMARY]'.$tail,
   'header'=>$hOff,'export'=>$exportOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1mesh96');
if(!is_string($tmp))throw new RuntimeException('Unable to write fixture');
try {
 foreach([9,10] as $v){
   $f=$make($v);file_put_contents($tmp,$f['bytes']);
   $out=Mesh::read($tmp,true)['mesh_records'][0]??[];
   $check("v{$v}_native_header_and_sections",
     ($out['status']??null)==='native_mesh_records_decoded'
     && ($out['header_bytes']??null)===164
     && ($out['origin']??null)===[1.0,2.0,3.0]
     && ($out['rotation_raw_ints']??null)===[100,200,300]
     && ($out['counts']['triangles']['num']??null)===1
     && ($out['sections']['animation_sequences']['size']??null)===16);
   $check("v{$v}_triangles_and_vertices",
     ($out['triangles'][0]['texture_uv_bytes']??null)===[10,20,30,40,50,60]
     && ($out['animated_vertices'][0]['x']??null)===1
     && ($out['animated_vertices'][0]['y']??null)===-2
     && ($out['animated_vertices'][0]['z']??null)===3);
   $check("v{$v}_link_bounds_and_animation",
     ($out['vertex_link_indices'][0]['num_triangles']??null)===1
     && ($out['frame_bounds'][0]['min']??null)===[-1.0,-2.0,-3.0]
     && ($out['animation_sequences'][0]['name_reference_raw_word']??null)===1
     && ($out['animation_sequences'][0]['rate']??null)===30
     && ($out['runtime_name_references_resolved']??true)===false);
   $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unreal_resource','version'=>$v]
   );
   $s=$staged['native_metadata']['resource_mesh_records']['mesh_records'][0]??[];
   $check("v{$v}_staging_unverified_mesh",
     ($s['status']??null)==='native_mesh_records_decoded'
     && ($staged['parse_complete']??true)===false
     && ($s['internal_mesh_animation_evaluated']??true)===false);
   file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header']+124,4));
   try{Mesh::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
   $check("v{$v}_count_gt_capacity_rejected",$rejected);
   file_put_contents($tmp,substr_replace($f['bytes'],pack('V',71),$f['header']+64,4));
   try{Mesh::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
   $check("v{$v}_incorrect_totalmax_rejected",$rejected);
   file_put_contents($tmp,substr_replace($f['bytes'],pack('V',160),$f['export']+40,4));
   $incomplete=Mesh::read($tmp,false)['mesh_records'][0]??[];
   try{Mesh::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
   $check("v{$v}_short_header_rejected",
     ($incomplete['status']??null)==='incomplete_source_mesh_header'&&$rejected);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$errors===[],'passed'=>count($checks)-count($errors),
    'total'=>count($checks),'failures'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:1);
