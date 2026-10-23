#!/usr/bin/env php
<?php
/** Epic v1.200/UT99 native UMesh+ULodMesh real-header archive probes. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$head=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($head);$s=chr($head|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);return $s;
};
$prefix=static function(int $v):string{
 $raw=$v<40?pack('V2',0,0):'';
 if($v<47){$raw.="\0\0".str_repeat("\0",8);}
 return $raw."\0".($v<57?"\0":"").($v<58?"\0":"");
};
$box=static fn():string=>pack('g6',1,2,3,4,5,6).chr(1);
$sphere=static fn(int $v,bool $fullLegacy=false):string=>
    ($v<=61&&!$fullLegacy)?pack('g3',7,8,9):pack('g4',7,8,9,10);
$body=static function(string $kind,int $ver,array $o=[])use($idx,$prefix,$box,$sphere):string{
 $raw=$prefix($ver);
 $base=512; // $raw already includes the UObject prefix at serialOffset 512.
 $fullLegacy=($o['legacyFullSphere']??false)===true;
 $raw.=$box().($ver<=40?pack('g4',7,8,9,10):$sphere($ver,$fullLegacy));
 $array=static function(
  string $field,string $bytes,int $count,int $minimum=1,bool $lazy=false
 )use(&$raw,$base,$idx,$ver,$o):void{
  $num=($o['emptyArrays']??false)?0:($o['countOverride'][$field]??$count);
  $elements=$num===0?'':($num>$count&&$num<=32?str_repeat($bytes,$num):$bytes);
  $prefix=$idx($num);
  $lazyValue=$o['badSeekPos'][$field]??0;
  $seek=$lazy&&$ver>61
    ?pack('V',$base+strlen($raw)+4+strlen($prefix)+strlen($elements)+$lazyValue):'';
  $raw.=$seek.$prefix.$elements;
 };
 $packed=1|(2046<<11)|(3<<22);
 $array('Verts',pack('V',$packed),1,4,$ver>=62);
 $tri=pack('v3',0,1,2)."\x0a\x14\x1e\x28\x32\x3c".pack('V2',0x42,2);
 $array('Tris',$tri,1,20,$ver>=62);
 $notify=pack('g',0.75).$idx(5);
 $anim=$idx(5).$idx(5).pack('V2',0,2).$idx(1).$notify.pack('g',22.5);
 $array('AnimSeqs',$anim,1,15);
 $array('Connects',pack('V2',3,4),1,8,$ver>=62);
 $raw.=$box().$sphere($ver,$fullLegacy);
 $array('VertLinks',pack('V',12),1,4,$ver>=62);
 $array('Textures',$idx($o['textureRef']??-1),1,1);
 $array('BoundingBoxes',$box(),1,25);
 $array('BoundingSpheres',$sphere($ver,$fullLegacy),1,$ver<=61&&!$fullLegacy?12:16);
 $raw.=pack('V4',20,4,0xff,0x22);
 $raw.=pack('g6',1,2,3,4,5,6);
 $raw.=pack('V5',100,200,300,5,7);
 if($ver===65){$raw.=pack('g',2.75);}
 if($ver>=66){$array('TextureLOD',pack('g',1.25),1,4);}
 if($kind==='LodMesh'){
  $array('CollapsePointThus',pack('v',1),1,2);
  $array('FaceLevel',pack('v',2),1,2);
  $face=pack('v4',0,1,2,3);
  $array('Faces',$face,1,8);
  $array('CollapseWedgeThus',pack('v',3),1,2);
  $array('Wedges',pack('vCC',2,9,8),1,4);
  $array('Materials',pack('V2',0x44,1),1,8);
  $array('SpecialFaces',$face,1,8);
  $raw.=pack('V2',32,4).pack('g3',5.5,0.25,0.75)
       .pack('V',10).pack('g2',1.25,2.5);
  $array('RemapAnimVerts',pack('v',7),1,2);
  $raw.=pack('V',11);
 }
 return $raw.($o['extension']??'');
};
$package=static function(string $kind,int $ver,string $raw)use($idx):string{
 $names=['None','Mesh','LodMesh','Instance','Engine','Sequence','Class'];
 $nmap='';foreach($names as $n){$nmap.=($ver>=64?$idx(strlen($n)+1):'').$n."\0".pack('V',0);}
 $im=$idx(4).$idx(6).($ver<50?$idx(0):pack('V',0)).$idx($kind==='LodMesh'?2:1);
 $summarySize=$ver>=68?56:44;
 $no=$summarySize;$io=$no+strlen($nmap);$eo=$io+strlen($im);
 $class=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(3).pack('V',0)
       .$idx(strlen($raw)).$idx(512);
 $sum=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io)
     .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $table=$sum.$nmap.$im.$class;
 if(strlen($table)>512)throw new RuntimeException('Synthetic package tables overflow');
 return $table.str_repeat("\0",512-strlen($table)).$raw;
};
$paths=[];$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$parse=static function(string $kind,int $ver,string $data)use(&$paths,$package):array{
 $p=tempnam(sys_get_temp_dir(),'ue1mesh');
 if(!$p)throw new RuntimeException('Cannot create fixture');
 $paths[]=$p;file_put_contents($p,$package($kind,$ver,$data));
 $pkg=new Package($p);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Synthetic package validation failed');
 $tags=Tag::inspect($p,$pkg,0);
 if(($tags['status']??'')!=='tag_envelope_decoded'){
  throw new RuntimeException('UE1 package UObject property tag envelope invalid');
 }
 return [Native::inspect($p,$pkg,0,$tags),$tags];
};
$bad=static function(string $kind,int $v,string $data)use($parse):bool{
 try{$parse($kind,$v,$data);return false;}catch(RuntimeException){return true;}
};
try{
 foreach([35,50,61,62,65,66,69] as $v){
  [$r]=$parse('Mesh',$v,$body('Mesh',$v));
  $ok=$r['status']==='native_mesh_record_complete'
    &&$r['verts']['count']===1
    &&$r['verts']['records_preview'][0]['x']===1
    &&$r['verts']['records_preview'][0]['y']===-2
    &&$r['verts']['records_preview'][0]['z']===3
    &&$r['tris']['records_preview'][0]['uv'][1]===[30,40]
    &&$r['animation_sequences']['records_preview'][0]['name']['name']==='Sequence'
    &&$r['animation_sequences']['records_preview'][0]['notifies']['count']===1
    &&$r['connects']['records_preview'][0]['triangle_list_offset']===4
    &&$r['bounding_boxes']['count']===1
    &&$r['textures']['records_preview'] === [-1]
    &&$r['cur_poly']===5&&$r['cur_vertex']===7
    &&$r['mesh_bounding_box']['is_valid']===1;
  $check('source_mesh_v'.$v,$ok);
 }
 [$legacy]=$parse('Mesh',61,$body('Mesh',61));
 $check('v61_old_inline_mesh_arrays',
    $legacy['verts']['lazy_seek_pos']===null
    &&$legacy['mesh_bounding_sphere']['w']===null
    &&!isset($legacy['texture_lod']));
 foreach([41,50,61] as $ver){
  [$a]=$parse('Mesh',$ver,$body('Mesh',$ver));
  [$b]=$parse('Mesh',$ver,$body('Mesh',$ver,['legacyFullSphere'=>true]));
  $check('legacy_mesh_both_sphere_variants_v'.$ver,
    $a['status']==='native_mesh_record_complete'
    &&$a['sphere_wire_layout']==='sphere_xyz_only_12'
    &&$a['mesh_bounding_sphere']['w']===null
    &&$b['status']==='native_mesh_record_complete'
    &&$b['sphere_wire_layout']==='sphere_xyz_radius_16'
    &&$b['mesh_bounding_sphere']['w']===10.0
    &&$b['bounding_spheres']['records_preview'][0]['w']===10.0
    &&$b['source_serialized_base_bytes']===41);
  $check('legacy_mesh_sphere_extra_length_v'.$ver,
    strlen($body('Mesh',$ver,['legacyFullSphere'=>true]))===
      strlen($body('Mesh',$ver))+12);
  $check('legacy_mesh_wrong_trailer_rejected_v'.$ver,
    $bad('Mesh',$ver,substr($body('Mesh',$ver,['legacyFullSphere'=>true]),0,-1)));
 }
 [$modern]=$parse('Mesh',69,$body('Mesh',69));
 $check('v69_lazy_seekpos_and_texturelod',
    $modern['verts']['lazy_seek_pos']===$modern['verts']['end_offset']
    &&$modern['tris']['lazy_seek_pos']===$modern['tris']['end_offset']
    &&$modern['connects']['lazy_seek_pos']===$modern['connects']['end_offset']
    &&$modern['vertex_links']['lazy_seek_pos']===$modern['vertex_links']['end_offset']
    &&$modern['mesh_bounding_sphere']['w']===10.0
    &&$modern['texture_lod']['records_preview']===[1.25]);
 [$v65]=$parse('Mesh',65,$body('Mesh',65));
 $check('v65_single_legacy_float',
    $v65['legacy_v65_float']===2.75&&!isset($v65['texture_lod']));
 [$v66]=$parse('Mesh',66,$body('Mesh',66));
 $check('v66_TextureLOD_array',$v66['texture_lod']['count']===1
    &&!isset($v66['legacy_v65_float']));
 foreach([35,61,69] as $ver){
  [$empty]=$parse('Mesh',$ver,$body('Mesh',$ver,['emptyArrays'=>true]));
  $check('all_zero_arrays_mesh_v'.$ver,
    $empty['status']==='native_mesh_record_complete'
    &&$empty['verts']['count']===0
    &&$empty['animation_sequences']['count']===0
    &&$empty['textures']['count']===0
    &&(!isset($empty['texture_lod'])||$empty['texture_lod']['count']===0));
 }
 [$big]=$parse('Mesh',69,$body('Mesh',69,['countOverride'=>['Verts'=>18]]));
 $check('large_vertex_array_streamed_without_drop',$big['status']==='native_mesh_record_complete'
    &&$big['verts']['count']===18
    &&count($big['verts']['records_preview'])===16
    &&$big['verts']['preview_truncated']===true);
 [$lod]=$parse('LodMesh',69,$body('LodMesh',69));
 $check('v69_lod_inherited_mesh',$lod['status']==='native_lodmesh_record_complete'
    &&$lod['verts']['count']===1&&$lod['tris']['count']===1);
 $check('v69_lod_faces_wedges_materials',
    $lod['faces']['records_preview'][0]['wedges']===[0,1,2]
    &&$lod['wedges']['records_preview'][0]['uv']===[9,8]
    &&$lod['materials']['records_preview'][0]['poly_flags']===0x44
    &&$lod['special_faces']['count']===1);
 [$emptyLod]=$parse('LodMesh',69,$body('LodMesh',69,['emptyArrays'=>true]));
 $check('v69_lod_all_zero_arrays_source_order',
   $emptyLod['status']==='native_lodmesh_record_complete'
   &&$emptyLod['faces']['count']===0
   &&$emptyLod['special_faces']['count']===0
   &&$emptyLod['remap_anim_verts']['count']===0);
 $check('v69_lod_scalars_remap',
    $lod['model_verts']===32&&$lod['special_verts']===4
    &&$lod['mesh_scale_max']===5.5&&$lod['lod_hysteresis']===0.25
    &&$lod['lod_strength']===0.75&&$lod['lod_min_verts']===10
    &&$lod['lod_morph']===1.25&&$lod['lod_z_displace']===2.5
    &&$lod['remap_anim_verts']['records_preview']===[7]
    &&$lod['old_frame_verts']===11
    &&$lod['runtime_vertex_remap_executed']===false);
 [$partial]=$parse('LodMesh',61,$body('Mesh',61));
 $check('lod_pre62_unverified_not_invented',
    $partial['status']==='native_lodmesh_source_version_unverified');
 [$extra]=$parse('Mesh',69,$body('Mesh',69,['extension'=>"\xab"]));
 $check('mesh_extension_not_complete',
    $extra['status']==='native_mesh_has_extension'&&$extra['extension_bytes']===1);
 [$extra]=$parse('LodMesh',69,$body('LodMesh',69,['extension'=>"\xcd"]));
 $check('lod_extension_not_complete',
    $extra['status']==='native_lodmesh_has_extension'&&$extra['extension_bytes']===1);
 $check('reject_invalid_lazy_seekpos',
    $bad('Mesh',69,$body('Mesh',69,['badSeekPos'=>['Verts'=>1]])));
 $check('reject_negative_anim_count',
    $bad('Mesh',50,$body('Mesh',50,['countOverride'=>['AnimSeqs'=>-1]])));
 $check('reject_oversize_tri_count',
    $bad('Mesh',69,$body('Mesh',69,['countOverride'=>['Tris'=>9999]])));
 $check('reject_invalid_texture_ref',
    $bad('Mesh',69,$body('Mesh',69,['textureRef'=>-2])));
 $check('reject_bad_lod_face_count',
    $bad('LodMesh',69,$body('LodMesh',69,['countOverride'=>['Faces'=>999]])));
 $check('reject_truncated_lod_scalars',
    $bad('LodMesh',69,substr($body('LodMesh',69),0,-1)));
 $check('reject_truncated_legacy_mesh',
    $bad('Mesh',35,substr($body('Mesh',35),0,-1)));
}finally{foreach($paths as $p){@unlink($p);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failed'=>$failed,'checks'=>$checks,
 'source'=>['Unreal v1.200 Engine/Src/UnMesh.cpp:32-46, Engine/Inc/UnMesh.h FMeshVert/Tri/AnimSeq/Connect',
 'UT99 v400 Engine/Src/UnMesh.cpp:32-52, Engine/Inc/UnMesh.h',
 'UT99 v400 Engine/Src/ULodMesh.cpp:23-48, Core/Inc/UnTemplate.h TLazyArray::operator<<']],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
