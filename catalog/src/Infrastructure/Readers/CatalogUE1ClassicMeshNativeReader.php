<?php
/**
 * Classic UE1 v1.200/UT99 v400 UMesh and ULodMesh on-disk serializers.
 * Source: Engine/Src/UnMesh.cpp:32-55; Engine/Inc/UnMesh.h fixed-field
 * record operators; UT99 Engine/Src/ULodMesh.cpp:23-48 and TLazyArray
 * operator<< in Core/Inc/UnTemplate.h:887-923.
 *
 * Interprets file archive fields only, preserving array counts/typed samples.
 * No vertex animation playback, runtime Load()/LOD mesh remapping or Preload.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicMeshNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $kind,CatalogUE1PackageReader $pkg,int $ver,callable $read,
        callable $compact,callable $uint,int &$position,int $limit
    ):array{
        if(!in_array($kind,['mesh','lodmesh'],true)){
            throw new RuntimeException('Unsupported UE1 mesh archive class');
        }
        if($kind==='lodmesh'&&$ver<62){
            return ['status'=>'native_lodmesh_source_version_unverified',
                    'reason'=>'UT99 ULodMesh archive family not pinned below version 62'];
        }
        $result=CatalogUE1ClassicPrimitiveNativeReader::readBase(
            $ver,$read,$position,$limit);
        $i32=static function()use($uint):int{
            $n=$uint();return $n>=0x80000000?$n-0x100000000:$n;
        };
        $u16=static fn():int=>(int)unpack('v',$read(2))[1];
        $f32=static fn():float=>(float)unpack('g',$read(4))[1];
        $vector=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $box=static fn():array=>['min'=>$vector(),'max'=>$vector(),
            'is_valid'=>ord($read(1))];
        $sphere=static function()use($vector,$f32,$ver):array{
            $r=$vector();$r['w']=$ver<=61?null:$f32();return $r;
        };
        $ref=static function(string $field)use($compact,$pkg):int{
            $n=$compact();
            if(($n<0&&-$n>count($pkg->getImports()))
             ||($n>0&&$n>count($pkg->getExports()))){
                throw new RuntimeException('UE1 mesh invalid '.$field.' reference');
            }
            return $n;
        };
        $name=static function(string $field)use($compact,$pkg):array{
            $id=$compact();$names=$pkg->getNames();
            if($id<0||!isset($names[$id])){
                throw new RuntimeException('UE1 mesh invalid '.$field.' FName');
            }
            return ['index'=>$id,'name'=>(string)$names[$id]['name']];
        };
        $array=static function(
            string $field,callable $item,int $minimumBytes=1,bool $lazy=false
        )use($uint,$compact,$ver,$limit,&$position):array{
            $seek=null;
            if($lazy && $ver>61){$seek=$uint();}
            $count=$compact();
            if($count<0||$minimumBytes<1||
               $count>intdiv($limit-$position,$minimumBytes)){
                throw new RuntimeException('UE1 mesh '.$field.' invalid count/bounds');
            }
            $start=$position;$items=[];
            for($i=0;$i<$count;$i++){
                $row=$item();
                if($i<16){$items[]=$row;}
            }
            if($seek!==null && $seek!==$position){
                throw new RuntimeException('UE1 mesh '.$field.' lazy SeekPos mismatch');
            }
            return ['count'=>$count,'records_preview'=>$items,
                    'data_offset'=>$start,'end_offset'=>$position,
                    'lazy_seek_pos'=>$seek,
                    'preview_truncated'=>$count>count($items)];
        };
        $u8=static fn():int=>ord($read(1));
        $meshVert=static function()use($uint):array{
            $d=$uint();
            $x=$d&0x7ff;$y=($d>>11)&0x7ff;$z=($d>>22)&0x3ff;
            return ['packed'=>$d,'x'=>$x>=1024?$x-2048:$x,
                    'y'=>$y>=1024?$y-2048:$y,'z'=>$z>=512?$z-1024:$z];
        };
        $tri=static function()use($u16,$u8,$uint,$i32):array{
            $vertices=[$u16(),$u16(),$u16()];
            $uv=[[$u8(),$u8()],[$u8(),$u8()],[$u8(),$u8()]];
            return ['vertices'=>$vertices,'uv'=>$uv,
                'poly_flags'=>$uint(),'texture_index'=>$i32()];
        };
        $connect=static fn():array=>[
            'triangle_count'=>$i32(),'triangle_list_offset'=>$i32()];
        $animNotify=static function()use($f32,$name):array{
            return ['time'=>$f32(),'function_name'=>$name('AnimNotify.Function')];
        };
        $animSeq=static function()use($name,$i32,$f32,$array,$animNotify):array{
            $result=['name'=>$name('AnimSeq.Name'),'group'=>$name('AnimSeq.Group'),
                'start_frame'=>$i32(),'num_frames'=>$i32()];
            $result['notifies']=$array('Notifys',$animNotify,5);
            $result['rate']=$f32();
            return $result;
        };
        $result['archive_kind']=$kind;
        // v1.200 uses TArray for these four, UT99 changes these to
        // TLazyArray with absolute SeekPos when archive version >61.
        $modern=$ver>=62;
        $result['verts']=$array('Verts',$meshVert,4,$modern);
        $result['tris']=$array('Tris',$tri,20,$modern);
        $result['animation_sequences']=$array('AnimSeqs',$animSeq,15);
        $result['connects']=$array('Connects',$connect,8,$modern);
        $result['mesh_bounding_box']=$box();
        $result['mesh_bounding_sphere']=$sphere();
        $result['vertex_links']=$array('VertLinks',$i32,4,$modern);
        $result['textures']=$array('Textures',static fn():int=>$ref('Texture'),1);
        $result['bounding_boxes']=$array('BoundingBoxes',$box,25);
        $result['bounding_spheres']=$array(
            'BoundingSpheres',$sphere,$ver<=61?12:16);
        $result['frame_verts']=$i32();$result['anim_frames']=$i32();
        $result['and_flags']=$uint();$result['or_flags']=$uint();
        $result['scale']=$vector();$result['origin']=$vector();
        $result['rot_origin']=['pitch'=>$i32(),'yaw'=>$i32(),'roll'=>$i32()];
        $result['cur_poly']=$i32();$result['cur_vertex']=$i32();
        if($ver===65){$result['legacy_v65_float']=$f32();}
        if($ver>=66){$result['texture_lod']=$array('TextureLOD',$f32,4);}
        if($kind==='lodmesh'){
            // UT99 ULodMesh::Serialize first invokes full UMesh::Serialize.
            $result['collapse_point_thus']=$array('CollapsePointThus',$u16,2);
            $result['face_level']=$array('FaceLevel',$u16,2);
            $face=static fn():array=>[
                'wedges'=>[$u16(),$u16(),$u16()],
                'material_index'=>$u16()];
            $result['faces']=$array('Faces',$face,8);
            $result['collapse_wedge_thus']=$array('CollapseWedgeThus',$u16,2);
            $wedge=static fn():array=>[
                'vertex_index'=>$u16(),'uv'=>[$u8(),$u8()]];
            $result['wedges']=$array('Wedges',$wedge,4);
            $material=static fn():array=>[
                'poly_flags'=>$uint(),'texture_index'=>$i32()];
            $result['materials']=$array('Materials',$material,8);
            $result['special_faces']=$array('SpecialFaces',$face,8);
            $result['model_verts']=$i32();$result['special_verts']=$i32();
            $result['mesh_scale_max']=$f32();
            $result['lod_hysteresis']=$f32();
            $result['lod_strength']=$f32();
            $result['lod_min_verts']=$i32();
            $result['lod_morph']=$f32();
            $result['lod_z_displace']=$f32();
            $result['remap_anim_verts']=$array('RemapAnimVerts',$u16,2);
            $result['old_frame_verts']=$i32();
            // Load-time vertex permutations and the discarded temporary
            // RemapAnimVerts list are O10 runtime behavior, not extra bytes.
            $result['runtime_vertex_remap_executed']=false;
        }
        $result['status']=$position===$limit
            ?'native_'.$kind.'_record_complete':'native_'.$kind.'_has_extension';
        $result['extension_bytes']=$limit-$position;
        return $result;
    }
}
