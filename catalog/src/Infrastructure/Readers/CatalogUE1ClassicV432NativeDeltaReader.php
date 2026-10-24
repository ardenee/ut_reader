<?php
/**
 * UT99 v432 native-source additions layered on the v400 archive baseline.
 *
 * v432 Engine/Inc/UnMesh.h defines FMeshExtWedge (2 WORDs and 2 FLOATs).
 * v432 Engine/Inc/UnSkeletalMesh.h adds USkeletalMesh and UAnimation.
 * Their Serialize implementations are not in the v432 public tree:
 * Core/Engine Lib has compiled declarations but no shipped Src bodies.
 *
 * Do NOT infer class-native serialization order from member declarations.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicV432NativeDeltaReader
{
    /**
     * FMeshExtWedge::operator<<(FArchive&,FMeshExtWedge&):
     * iVertex : WORD, Flags : WORD, TexUV.U/V : FLOAT32LE.
     * Caller must establish the actual native array offset/count from
     * compiled/source evidence before invoking this helper.
     *
     * @return array<string,mixed>
     */
    public static function decodeExtendedWedges(
        string $bytes,int $offset,int $count
    ):array{
        $length=strlen($bytes);
        if($offset<0||$count<0||$offset>$length
            ||$count>intdiv($length-$offset,12)){
            throw new RuntimeException('UT99 v432 FMeshExtWedge archive bounds');
        }
        $byteCount=$count*12;
        $sha=hash_init('sha256');
        $preview=[];
        for($n=0;$n<$count;$n++){
            $base=$offset+$n*12;
            $entry=substr($bytes,$base,12);
            hash_update($sha,$entry);
            if($n<16){
                $parts=unpack('vvertex/vflags/gU/gV',$entry);
                $preview[]=[
                    'vertex_index'=>(int)$parts['vertex'],
                    'flags'=>(int)$parts['flags'],
                    'uv_u'=>(float)$parts['U'],
                    'uv_v'=>(float)$parts['V'],
                ];
            }
        }
        return [
            'source_record'=>'FMeshExtWedge',
            'source'=>'UT99 v432 Engine/Inc/UnMesh.h',
            'source_record_size_bytes'=>12,
            'count'=>$count,
            'archive_offset'=>$offset,
            'archive_bytes'=>$byteCount,
            'archive_sha256'=>hash_final($sha),
            'records_preview'=>$preview,
            'preview_truncated'=>$count>16,
        ];
    }

    /**
     * v432 UnSkeletalMesh.h VJointPos source archive is 44 bytes:
     * quaternion, FVector, Length, XSize, XSize AGAIN, ZSize.
     * YSize is declared in memory but never sent to Ar. Preserve
     * that exact duplicate source behavior rather than 'fixing' it.
     *
     * @return array<string,mixed>
     */
    private static function jointAt(string $bytes,int &$pos):array
    {
        if($pos<0||$pos>strlen($bytes)-44){
            throw new RuntimeException('UT99 v432 VJointPos truncated');
        }
        $p=unpack('g4quat/g3pos/g4size',substr($bytes,$pos,44));
        $pos+=44;
        return [
            'quat'=>[(float)$p['quat1'],(float)$p['quat2'],
                (float)$p['quat3'],(float)$p['quat4']],
            'position'=>[(float)$p['pos1'],(float)$p['pos2'],
                (float)$p['pos3']],
            'length'=>(float)$p['size1'],
            'x_size_first_archived'=>(float)$p['size2'],
            'x_size_second_archived'=>(float)$p['size3'],
            'x_size_after_archive_load'=>(float)$p['size3'],
            'z_size'=>(float)$p['size4'],
            'y_size_archived'=>false,
            'source_duplicate_x_size'=>true,
        ];
    }

    /**
     * Source-proven VJointPos array records; caller determines an
     * actual array location from the owning method independently.
     *
     * @return array<string,mixed>
     */
    public static function decodeJointPositions(
        string $bytes,int $offset,int $count
    ):array{
        $length=strlen($bytes);
        if($offset<0||$count<0||$offset>$length
            ||$count>intdiv($length-$offset,44)){
            throw new RuntimeException('UT99 v432 VJointPos bounds');
        }
        $preview=[];$pos=$offset;
        for($n=0;$n<$count;$n++){
            $item=self::jointAt($bytes,$pos);
            if($n<16){$preview[]=$item;}
        }
        return [
            'source_record'=>'VJointPos',
            'source'=>'UT99 v432 Engine/Inc/UnSkeletalMesh.h',
            'archive_offset'=>$offset,
            'source_record_size_bytes'=>44,
            'count'=>$count,'archive_bytes'=>$pos-$offset,
            'archive_sha256'=>hash('sha256',substr($bytes,$offset,$pos-$offset)),
            'records_preview'=>$preview,'preview_truncated'=>$count>16,
            'source_duplicate_x_size'=>true,
        ];
    }

    /**
     * FMeshBone archive operator serializes:
     *   FName, DWORD Flags, VJointPos, INT NumChildren, INT ParentIndex.
     * Its in-memory Depth is NOT in the archive operator.
     * UE1 FName indices are signed compact indices (not raw DWORDs).
     *
     * @return array<string,mixed>
     */
    public static function decodeReferenceBones(
        string $bytes,int $offset,int $count,?int $nameCount=null
    ):array{
        $length=strlen($bytes);
        if($offset<0||$count<0||$offset>$length
            ||$count>intdiv($length-$offset,57)){
            throw new RuntimeException('UT99 v432 FMeshBone bounds');
        }
        $pos=$offset;$preview=[];
        for($i=0;$i<$count;$i++){
            $name=CatalogUE1ClassicCompactIndexReader::readAt($bytes,$pos);
            if($name===null||$name<0
                ||($nameCount!==null&&$name>=$nameCount)){
                throw new RuntimeException('UT99 v432 FMeshBone invalid name index');
            }
            if($pos>strlen($bytes)-56){
                throw new RuntimeException('UT99 v432 FMeshBone truncated fields');
            }
            $flags=(int)unpack('V',substr($bytes,$pos,4))[1];
            $pos+=4;
            $joint=self::jointAt($bytes,$pos);
            $values=unpack('Vchildren/Vparent',substr($bytes,$pos,8));
            $pos+=8;
            if($i<16){
                $preview[]=[
                    'name_index'=>$name,'flags'=>$flags,
                    'bone_position'=>$joint,
                    'num_children'=>$values['children']>=0x80000000
                        ?(int)$values['children']-0x100000000
                        :(int)$values['children'],
                    'parent_index'=>$values['parent']>=0x80000000
                        ?(int)$values['parent']-0x100000000
                        :(int)$values['parent'],
                    'depth_serialized'=>false,
                ];
            }
        }
        return [
            'source_record'=>'FMeshBone',
            'source'=>'UT99 v432 Engine/Inc/UnSkeletalMesh.h',
            'archive_offset'=>$offset,
            'count'=>$count,'archive_bytes'=>$pos-$offset,
            'archive_end_offset'=>$pos,
            'archive_sha256'=>hash('sha256',substr($bytes,$offset,$pos-$offset)),
            'records_preview'=>$preview,'preview_truncated'=>$count>16,
            'source_depth_field_serialized'=>false,
        ];
    }

    /**
     * v432 VBoneInfIndex::operator<< archives four WORDs (8 bytes).
     * v432 VBoneInfluence::operator<< archives two WORDs (4 bytes).
     * No owner array position/order is inferred by these record codecs.
     *
     * @return array<string,mixed>
     */
    public static function decodeBoneInfluences(
        string $bytes,int $offset,int $count,string $recordKind
    ):array{
        $layouts=[
            'VBoneInfIndex'=>['format'=>'vweight_index/vnumber/vdetail_a/vdetail_b',
                              'size'=>8],
            'VBoneInfluence'=>['format'=>'vpoint_index/vbone_weight','size'=>4],
        ];
        $layout=$layouts[$recordKind]??null;
        if($layout===null){
            throw new RuntimeException('Unknown v432 bone-influence record');
        }
        $width=$layout['size'];$length=strlen($bytes);
        if($offset<0||$count<0||$offset>$length
            ||$count>intdiv($length-$offset,$width)){
            throw new RuntimeException('UT99 v432 bone-influence bounds');
        }
        $preview=[];
        for($i=0;$i<min($count,16);$i++){
            $row=unpack($layout['format'],substr($bytes,
                $offset+$i*$width,$width));
            $preview[]=$row;
        }
        $byteCount=$count*$width;
        return [
            'source_record'=>$recordKind,
            'source'=>'UT99 v432 Engine/Inc/UnSkeletalMesh.h',
            'source_record_size_bytes'=>$width,
            'count'=>$count,'archive_offset'=>$offset,
            'archive_bytes'=>$byteCount,
            'archive_sha256'=>hash('sha256',substr($bytes,$offset,$byteCount)),
            'records_preview'=>$preview,'preview_truncated'=>$count>16,
        ];
    }

    /**
     * UAnimation::Serialize compiled v432 Engine.dll:
     * ?Serialize@UAnimation (RVA 0x1FD2 -> 0xECEC0)
     * Super::Serialize then at this+0x28, +0x34, +0x40 three TArray
     * operators. UnSkeletalMesh.h declares matching RefBones, Moves,
     * AnimSeqs in precisely that order.
     * Source-defined item operators are inline in UnSkeletalMesh.h and
     * UnAnim.h. No raw importer arrays are serialized here.
     *
     * @return array<string,mixed>
     */
    public static function inspectAnimation(
        CatalogUE1PackageReader $pkg,callable $read,callable $compact,
        callable $uint,int &$position,int $limit
    ):array{
        $initial=$position;
        $i32=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $f32=static fn():float=>(float)unpack('g',$read(4))[1];
        $vec=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $quat=static fn():array=>['x'=>$f32(),'y'=>$f32(),
                                'z'=>$f32(),'w'=>$f32()];
        $fname=static function()use($compact,$pkg):array{
            $index=$compact();$names=$pkg->getNames();
            if($index<0||!isset($names[$index])){
                throw new RuntimeException('UT99 v432 animation FName out of range');
            }
            return ['index'=>$index,'text'=>(string)$names[$index]['name']];
        };
        $array=static function(string $label,callable $item,int $minBytes)
            use($compact,&$position,$limit):array{
            $count=$compact();
            if($count<0||$minBytes<1
                ||$count>intdiv($limit-$position,$minBytes)){
                throw new RuntimeException(
                    'UT99 v432 animation '.$label.' array count/bounds');
            }
            $start=$position;$preview=[];
            for($i=0;$i<$count;$i++){
                $entry=$item();
                if($i<16){$preview[]=$entry;}
            }
            return ['count'=>$count,'data_offset'=>$start,
                'archive_bytes'=>$position-$start,
                'records_preview'=>$preview,
                'preview_truncated'=>$count>16];
        };
        $namedBone=static fn():array=>[
            'name'=>$fname(),'flags'=>$uint(),'parent_index'=>$i32(),
        ];
        $analogTrack=static function()use($array,$uint,$quat,$vec,$f32):array{
            $flags=$uint();
            return [
                'flags'=>$flags,
                'key_quat'=>$array('AnalogTrack.KeyQuat',$quat,16),
                'key_pos'=>$array('AnalogTrack.KeyPos',$vec,12),
                'key_time'=>$array('AnalogTrack.KeyTime',$f32,4),
            ];
        };
        $motionChunk=static function()use(
            $vec,$f32,$i32,$uint,$array,$analogTrack
        ):array{
            return [
                'root_speed_3d'=>$vec(),
                'track_time'=>$f32(),
                'start_bone'=>$i32(),
                'flags'=>$uint(),
                'bone_indices'=>$array('MotionChunk.BoneIndices',$i32,4),
                'anim_tracks'=>$array('MotionChunk.AnimTracks',$analogTrack,7),
                'root_track'=>$analogTrack(),
            ];
        };
        $notify=static fn():array=>[
            'time'=>$f32(),'function'=>$fname(),
        ];
        $animSeq=static function()use(
            $fname,$i32,$array,$notify,$f32
        ):array{
            return [
                'name'=>$fname(),'group'=>$fname(),
                'start_frame'=>$i32(),'num_frames'=>$i32(),
                'notifies'=>$array('FMeshAnimSeq.Notifys',$notify,5),
                'rate'=>$f32(),
            ];
        };
        $refBones=$array('RefBones',$namedBone,9);
        $moves=$array('Moves',$motionChunk,33);
        $sequences=$array('AnimSeqs',$animSeq,15);
        return [
            'status'=>$position===$limit
                ?'native_animation_record_complete'
                :'native_animation_has_extension',
            'source_profile'=>CatalogUE1ClassicSourceFamily::UT99_V432,
            'compiled_method'=>'Engine.dll RVA 0x1FD2 -> 0xECEC0',
            'source_record_definitions'=>'Engine/Inc/UnSkeletalMesh.h and UnAnim.h',
            'parent_method'=>'UObject::Serialize',
            'archive_fields_in_order'=>['RefBones','Moves','AnimSeqs'],
            'ref_bones'=>$refBones,'moves'=>$moves,
            'animation_sequences'=>$sequences,
            'archive_bytes_consumed'=>$position-$initial,
            'extension_bytes'=>$limit-$position,
            'raw_import_animation_arrays_serialized'=>false,
            'runtime_linker_object_graph_verified'=>false,
        ];
    }

    /**
     * USkeletalMesh::Serialize compiled Engine.dll v432:
     * RVA 0x341D -> body RVA 0xED290.
     * 1. ULodMesh::Serialize
     * 2. ExtWedges, Points, RefSkeleton, BoneWeightIdx,
     *    BoneWeights, LocalPoints (each TArray)
     * 3. SkeletalDepth, DefaultAnimation object ref
     * 4. WeaponBoneIndex, WeaponAdjust FCoords (12 FLOATs).
     *
     * This ordering is established from the actual compiled DLL
     * rather than inferred from member declaration order.
     *
     * @return array<string,mixed>
     */
    public static function inspectSkeletalMesh(
        CatalogUE1PackageReader $pkg,int $version,callable $read,
        callable $compact,callable $uint,int &$position,int $limit,
        ?callable $seek=null
    ):array{
        $start=$position;
        $base=CatalogUE1ClassicMeshNativeReader::inspect(
            'lodmesh',$pkg,$version,$read,$compact,$uint,
            $position,$limit,$seek,CatalogUE1ClassicSourceFamily::UT99_V432);
        if(!in_array((string)($base['status']??''),[
            'native_lodmesh_record_complete',
            'native_lodmesh_has_extension'],true)){
            return ['status'=>'native_v432_inherited_lodmesh_not_decoded',
                'inherited'=>$base,'extension_bytes'=>$limit-$position];
        }
        $i32=static function()use($uint):int{
            $v=$uint();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $f32=static fn():float=>(float)unpack('g',$read(4))[1];
        $vec=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $array=static function(string $label,callable $item,int $minBytes)
            use($compact,&$position,$limit):array{
            $count=$compact();
            if($count<0||$minBytes<1
                ||$count>intdiv($limit-$position,$minBytes)){
                throw new RuntimeException(
                    'UT99 v432 '.$label.' source array count/bounds');
            }
            $offset=$position;$preview=[];
            for($i=0;$i<$count;$i++){
                $row=$item();
                if($i<16){$preview[]=$row;}
            }
            return ['count'=>$count,'data_offset'=>$offset,
                'archive_bytes'=>$position-$offset,
                'records_preview'=>$preview,'preview_truncated'=>$count>16];
        };
        $wedge=static function()use($read):array{
            $v=unpack('vvertex/vflags/gU/gV',$read(12));
            return ['vertex_index'=>(int)$v['vertex'],
                'flags'=>(int)$v['flags'],
                'uv_u'=>(float)$v['U'],'uv_v'=>(float)$v['V']];
        };
        $name=static function()use($compact,$pkg):array{
            $n=$compact();$names=$pkg->getNames();
            if($n<0||!isset($names[$n])){
                throw new RuntimeException('UT99 v432 FMeshBone invalid FName');
            }
            return ['index'=>$n,'text'=>(string)$names[$n]['name']];
        };
        $refBone=static function()use($name,$uint,$read,$i32):array{
            $boneName=$name();$flags=$uint();
            $v=unpack('g4quat/g3pos/g4size',$read(44));
            $children=$i32();$parent=$i32();
            return [
                'name'=>$boneName,'flags'=>$flags,
                'quat'=>[$v['quat1'],$v['quat2'],$v['quat3'],$v['quat4']],
                'position'=>[$v['pos1'],$v['pos2'],$v['pos3']],
                'length'=>$v['size1'],
                'x_size_first'=>$v['size2'],
                'x_size_second'=>$v['size3'],
                'loaded_x_size'=>$v['size3'],
                'z_size'=>$v['size4'],
                'source_y_size_archived'=>false,
                'num_children'=>$children,'parent_index'=>$parent,
                'source_depth_archived'=>false,
            ];
        };
        $infIndex=static function()use($read):array{
            $v=unpack('vindex/vnumber/vdetail_a/vdetail_b',$read(8));
            return $v;
        };
        $influence=static function()use($read):array{
            return unpack('vpoint_index/vbone_weight',$read(4));
        };
        $extWedges=$array('ExtWedges',$wedge,12);
        $points=$array('Points',$vec,12);
        $skeleton=$array('RefSkeleton',$refBone,57);
        $indices=$array('BoneWeightIdx',$infIndex,8);
        $weights=$array('BoneWeights',$influence,4);
        $localPoints=$array('LocalPoints',$vec,12);
        // Compiled v432 serializes fields at 0x22C/0x230
        // before the member stored at 0x1F8 and its FCoords.
        $depth=$i32();
        $animationRef=$compact();
        if(($animationRef<0&&-$animationRef>count($pkg->getImports()))
            ||($animationRef>0&&$animationRef>count($pkg->getExports()))){
            throw new RuntimeException('UT99 v432 invalid DefaultAnimation index');
        }
        $weaponBoneIndex=$i32();
        $adjust=['origin'=>$vec(),'x_axis'=>$vec(),
                 'y_axis'=>$vec(),'z_axis'=>$vec()];
        return [
            'status'=>$position===$limit
                ?'native_skeletalmesh_record_complete'
                :'native_skeletalmesh_has_extension',
            'compiled_method'=>'Engine.dll RVA 0x341D -> 0xED290',
            'source_profile'=>CatalogUE1ClassicSourceFamily::UT99_V432,
            'inherited_lodmesh'=>$base,
            'archive_fields_in_order'=>[
                'ULodMesh','ExtWedges','Points','RefSkeleton',
                'BoneWeightIdx','BoneWeights','LocalPoints',
                'SkeletalDepth','DefaultAnimation',
                'WeaponBoneIndex','WeaponAdjust'],
            'extended_wedges'=>$extWedges,
            'points'=>$points,'reference_skeleton'=>$skeleton,
            'bone_weight_indices'=>$indices,'bone_weights'=>$weights,
            'local_points'=>$localPoints,'skeletal_depth'=>$depth,
            'default_animation_reference'=>$animationRef,
            'weapon_bone_index'=>$weaponBoneIndex,
            'weapon_adjust'=>$adjust,
            'archive_bytes_consumed'=>$position-$start,
            'extension_bytes'=>$limit-$position,
            'runtime_bone_hierarchy_verified'=>false,
        ];
    }

    /**
     * Compiled v432 UFont::Serialize RVA 0x846D0:
     *   Super; Pages; CharactersPerPage;
     *   if (Archive.Ver()>=69) CharRemap, IsRemapped;
     *   else IsRemapped=0 (no archive bytes).
     *
     * TMapBase<TCHAR,TCHAR>::operator<< source: Ar << Pairs.
     * Compiled specialization RVA 0x84D00 reads 8-byte
     * in-memory TPair records, but writes only the two WORD
     * key/value members at offsets 4 and 6: 4 FILE bytes/pair.
     * This is not the HashNext memory pointer.
     *
     * @return array<string,mixed>
     */
    public static function decodeFontRemap(
        int $version,callable $read,callable $compact,
        callable $uint,int &$position,int $limit,
        int $exportSerialOffset,int $physicalFileSize
    ):array{
        if($version<69){
            return [
                'source_v432_font_remap_archived'=>false,
                'source_is_remapped_zeroed_on_legacy_load'=>true,
                'is_remapped_raw'=>0,
                'is_remapped'=>false,
                'char_remap'=>null,
            ];
        }
        if($limit-$position<5){
            throw new RuntimeException(
                'UT99 v432 truncated font remap count and IsRemapped');
        }
        $count=$compact();
        if($count<0||$limit-$position<4
            ||$count>intdiv($limit-$position-4,4)){
            // Compiled v432 UFont consumes all CharRemap pairs, then the
            // IsRemapped DWORD, and checks export SerialSize afterwards.
            // A malicious extra pair may reach +4 past the export. Recreate
            // only the attempted logical cursor/error; NEVER read beyond the
            // export. Check physical-file length to distinguish an actual
            // end-of-file error from an export-size mismatch.
            if($count>=0&&$limit-$position>=4
                &&$position<=$physicalFileSize
                &&$count<=intdiv($physicalFileSize-$position-4,4)){
                $got=$position+$count*4+4-$exportSerialOffset;
                $expected=$limit-$exportSerialOffset;
                throw new RuntimeException(
                    'Font: Serial size mismatch: Got '.$got.
                    ', Expected '.$expected
                );
            }
            throw new RuntimeException(
                'UT99 v432 font CharRemap pairs exceed native bounds');
        }
        $pairOffset=$position;
        $sha=hash_init('sha256');
        $preview=[];
        for($i=0;$i<$count;$i++){
            $bytes=$read(4);
            hash_update($sha,$bytes);
            if($i<16){
                $v=unpack('vsource_char/vreplacement_char',$bytes);
                $preview[]=[
                    'source_char_codepoint'=>(int)$v['source_char'],
                    'replacement_char_codepoint'=>(int)$v['replacement_char'],
                ];
            }
        }
        $remappedRaw=$uint();
        return [
            'source_v432_font_remap_archived'=>true,
            'source_compiled_method'=>'Engine.dll RVA 0x846D0',
            'source_tmap_pair_serializer'=>'Engine.dll RVA 0x84D00',
            'source_is_remapped_zeroed_on_legacy_load'=>false,
            'is_remapped_raw'=>$remappedRaw,
            'is_remapped'=>$remappedRaw!==0,
            'char_remap'=>[
                'count'=>$count,
                'source_pair_wire_bytes'=>4,
                'source_pair_inmemory_bytes'=>8,
                'archive_data_offset'=>$pairOffset,
                'archive_data_bytes'=>$count*4,
                'archive_data_sha256'=>hash_final($sha),
                'pairs_preview'=>$preview,
                'preview_truncated'=>$count>16,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function inspect(
        string $path,string $className,int $offset,int $length,
        string $sourceProfile
    ):?array{
        if($sourceProfile!==CatalogUE1ClassicSourceFamily::UT99_V432){
            return null;
        }
        $kind=strtolower($className);
        if(!in_array($kind,['skeletalmesh','animation'],true)){
            return null;
        }
        if($length<0||$offset<0){
            throw new RuntimeException('Invalid UT99 v432 native extent');
        }
        $size=filesize($path);
        if($size===false||$offset>$size||$length>$size-$offset){
            throw new RuntimeException('UT99 v432 native bytes exceed file');
        }
        $fp=fopen($path,'rb');
        if($fp===false){throw new RuntimeException('Cannot open UT99 v432 package');}
        try{
            if(fseek($fp,$offset)!==0){
                throw new RuntimeException('Cannot seek UT99 v432 native bytes');
            }
            $digest=hash_init('sha256');
            $remaining=$length;
            while($remaining>0){
                $chunk=fread($fp,min(65536,$remaining));
                if(!is_string($chunk)||$chunk===''){
                    throw new RuntimeException('Truncated UT99 v432 native payload');
                }
                hash_update($digest,$chunk);
                $remaining-=strlen($chunk);
            }
        }finally{
            fclose($fp);
        }
        return [
            'status'=>'native_v432_serializer_source_order_unverified',
            'class_name'=>$className,
            'native_payload_offset'=>$offset,
            'native_payload_bytes'=>$length,
            'native_payload_sha256'=>hash_final($digest),
            'inherited_engine_source'=>'UT99 v400 Core/Engine Serialize implementation baseline',
            'v432_class_declaration'=>'Engine/Inc/UnSkeletalMesh.h',
            'v432_extra_struct_definition'=>'Engine/Inc/UnMesh.h::FMeshExtWedge',
            'v432_native_method_body_available'=>false,
            'archive_order_guessed'=>false,
            'requires_compiled_engine_method_evidence'=>true,
            'g5_verification'=>'OPEN',
        ];
    }
}
