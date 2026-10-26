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
        callable $compact,callable $uint,int &$position,int $limit,
        ?callable $seek=null,?string $sourceFamily=null
    ):array{
        if(!in_array($kind,['mesh','lodmesh'],true)){
            throw new RuntimeException('Unsupported UE1 mesh archive class');
        }
        $family=CatalogUE1ClassicSourceFamily::select($ver,$sourceFamily);
        if($kind==='lodmesh'&&$family!==CatalogUE1ClassicSourceFamily::UT99_V432){
            return ['status'=>'native_lodmesh_game_override_not_selected',
                'reason'=>'ULodMesh serializer is defined in UT99 game source'];
        }
        $result=self::inspectWire(
            $kind,$pkg,$ver,$read,$compact,$uint,$position,$limit,$family,$seek);
        return $result+[
            'selected_source_family'=>$family,
            'source_selection'=>'explicit-or-epic-engine-baseline',
        ];
    }

    /** @return array<string,mixed> */
    private static function inspectWire(
        string $kind,CatalogUE1PackageReader $pkg,int $ver,callable $read,
        callable $compact,callable $uint,int &$position,int $limit,
        string $sourceFamily,?callable $seek
    ):array{
        $result=CatalogUE1ClassicPrimitiveNativeReader::readBase(
            $ver,$read,$position,$limit,$sourceFamily);
        $i32=static function()use($uint):int{
            $n=$uint();return $n>=0x80000000?$n-0x100000000:$n;
        };
        $u16=static fn():int=>(int)unpack('v',$read(2))[1];
        $f32=static fn():float=>(float)unpack('g',$read(4))[1];
        $vector=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $box=static fn():array=>['min'=>$vector(),'max'=>$vector(),
            'is_valid'=>ord($read(1))];
        $sphere=static function()use($vector,$f32,$ver,$sourceFamily):array{
            $r=$vector();
            $ut99=$sourceFamily===CatalogUE1ClassicSourceFamily::UT99_V432;
            // Unreal v1.200 FSphere inherits FVector's 12-byte operator;
            // only UT99 v400 serializes its FPlane radius after v61.
            $r['w']=(!$ut99||$ver<=61)?null:$f32();
            return $r;
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
        )use($uint,$compact,$ver,$limit,&$position,$seek):array{
            $seekPos=null;
            if($lazy && $ver>61){$seekPos=$uint();}
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
            $decodedEnd=$position;
            // Epic TLazyArray operator seeks to its absolute recorded end,
            // regardless of unused bytes after the decoded TArray elements.
            if($seekPos!==null&&$seekPos!==$position){
                if($seek===null){
                    throw new RuntimeException('UE1 mesh '.$field.' lazy seek callback missing');
                }
                $seek($seekPos);
            }
            return ['count'=>$count,'records_preview'=>$items,
                    'data_offset'=>$start,'end_offset'=>$decodedEnd,
                    'lazy_seek_pos'=>$seekPos,
                    'lazy_unused_payload_bytes'=>$seekPos===null?0:max(0,$seekPos-$decodedEnd),
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
        // UT99 v400 Engine/Src/UnMesh.cpp::UMesh::Serialize checks
        // Tris(0).iVertex[0] < Verts.Num() when any triangle exists.
        // Unreal v1.200 does not have this assertion, so do not reject
        // historical files using a later engine's validation rule.
        if($modern && $result['tris']['count']>0){
            $firstVertex=$result['tris']['records_preview'][0]['vertices'][0];
            if($firstVertex >= $result['verts']['count']){
                throw new RuntimeException(
                    'UT99 UMesh first triangle vertex exceeds Epic Verts.Num()');
            }
            $result['first_triangle_vertex_validated_by_ut99_source']=true;
        }else{
            $result['first_triangle_vertex_validated_by_ut99_source']=false;
        }
        $result['animation_sequences']=$array('AnimSeqs',$animSeq,15);
        $result['connects']=$array('Connects',$connect,8,$modern);
        $result['mesh_bounding_box']=$box();
        $result['mesh_bounding_sphere']=$sphere();
        $result['vertex_links']=$array('VertLinks',$i32,4,$modern);
        $result['textures']=$array('Textures',static fn():int=>$ref('Texture'),1);
        $result['bounding_boxes']=$array('BoundingBoxes',$box,25);
        $result['bounding_spheres']=$array(
            'BoundingSpheres',$sphere,
            ($sourceFamily!==CatalogUE1ClassicSourceFamily::UT99_V432
                ||$ver<=61)?12:16);
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
            // UT99 v400 ULodMesh::Serialize uses RemapAnimVerts(v) for
            // every v < FrameVerts whenever its array is nonempty.
            // v432 TArray<T>::operator() uses checkSlow(i<=ArrayNum),
            // not a persistent-archive count error. With FrameVerts
            // beyond remap array elements, compiled release loading may
            // read allocator memory; never fabricate a fatal file error
            // or deterministic vertex value for that undefined access.
            $remapTooShort=$result['remap_anim_verts']['count']>0
                &&$result['anim_frames']>0
                &&$result['remap_anim_verts']['count']<$result['frame_verts'];
            $result['runtime_remap_array_index_validated']=!$remapTooShort;
            // Original UT99 ULodMesh.cpp executes the following after the
            // archive operator sequence, while Ar.IsLoading():
            // Verts.Load(); NewVerts.Add(FrameVerts * AnimFrames);
            // NewVerts[f*FrameVerts+v]=Verts[RemapAnimVerts[v]+f*OldFrameVerts];
            // ExchangeArray(Verts,NewVerts); RemapAnimVerts.Empty().
            // The archived Verts/Remap values remain preserved above; expose
            // the distinct *loaded* values as an independently hashed stream.
            // No heuristic permutations or invented vertex data.
            $result['runtime_vertex_remap_executed']=$remapTooShort?null:false;
            if($result['remap_anim_verts']['count']>0){
                if($remapTooShort){
                    $result['runtime_vertex_remap_status']=
                        'native_remap_out_of_bounds_memory_value_undefined';
                }elseif($seek===null){
                    $result['runtime_vertex_remap_status']=
                        'source_load_permutation_requires_archive_seek';
                }else{
                    $frames=$result['anim_frames'];$perFrame=$result['frame_verts'];
                    $oldPerFrame=$result['old_frame_verts'];
                    $archived=$result['verts']['count'];
                    if($frames<0||$perFrame<0||$oldPerFrame<0
                        ||($perFrame!==0 && $frames>intdiv(PHP_INT_MAX,$perFrame))){
                        throw new RuntimeException('UT99 ULodMesh invalid runtime frame dimensions');
                    }
                    // Save the post-ULodMesh cursor: the skeletal subclass
                    // must resume its own six arrays at the identical byte.
                    $resume=$position;$hash=hash_init('sha256');$first=[];
                    $invalidVertexIndex=false;
                    $remapAt=$result['remap_anim_verts']['data_offset'];
                    $vertsAt=$result['verts']['data_offset'];
                    for($f=0;$f<$frames;$f++){
                        for($v=0;$v<$perFrame;$v++){
                            $seek($remapAt+$v*2);
                            $from=(int)unpack('v',$read(2))[1];
                            $index=$from+$f*$oldPerFrame;
                            if($index<0||$index >= $archived){
                                $invalidVertexIndex=true;
                                break 2;
                            }
                            $seek($vertsAt+$index*4);
                            $packed=$read(4);
                            hash_update($hash,$packed);
                            if(count($first)<16){
                                $first[]=str_pad(dechex((int)unpack('V',$packed)[1]),
                                    8,'0',STR_PAD_LEFT);
                            }
                        }
                    }
                    $seek($resume);
                    if($invalidVertexIndex){
                        $result['runtime_vertex_remap_executed']=null;
                        $result['runtime_vertex_remap_status']=
                            'native_verts_out_of_bounds_memory_value_undefined';
                    }else{
                        $result['runtime_vertex_remap_executed']=true;
                        $result['loaded_verts']=[
                            'count'=>$frames*$perFrame,
                            'packed_dword_preview_hex'=>$first,
                            'packed_bytes_sha256'=>hash_final($hash),
                            'source'=>'ULodMesh::Serialize Ar.IsLoading vertex permutation',
                        ];
                        $result['loaded_remap_anim_verts_count']=0;
                        $result['runtime_vertex_remap_status']='epic_load_permutation_complete';
                    }
                }
            }
        }
        $result['status']=$position===$limit
            ?'native_'.$kind.'_record_complete':'native_'.$kind.'_has_extension';
        $result['extension_bytes']=$limit-$position;
        return $result;
    }
}
