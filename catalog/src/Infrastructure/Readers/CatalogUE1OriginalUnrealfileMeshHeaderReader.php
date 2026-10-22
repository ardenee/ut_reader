<?php
/**
 * Original Unrealfile UMesh::SerializeHeader v22/v23, May v0.86x / July v0.867.
 * UnPrim.h::UPrimitive writes FBoundingVolume as 24B Min/Max,
 * BYTE IsValid (1B), FPlane Sphere (16B) = 41B (both versions).
 * UnMesh.h::UMesh then writes eight 1-based UObject refs (32B),
 * FrameVerts/AnimFrames/AndFlags/OrFlags (16B),
 * Scale and Origin FVector (24B), RotOrigin FRotation (12B),
 * CurPoly/CurVertex (8B). May FRotation writes three WORDs (6B),
 * July writes three INTs (12B). Native May=127B; July=133B.
 * Header is preceded by FExecStackMain. UMesh owns no direct data bytes;
 * mesh vertex/triangle buffers are child UDatabase objects by reference.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileMeshHeaderReader
{
    private const MAY_NATIVE_BYTES=127;
    private const JULY_NATIVE_BYTES=133;
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false,?string $sourceLineage=null):array
    {
        CatalogUE1OriginalUnrealfilePropertySerializationGate::validate($sourceLineage);
        $m=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $numObjects=(int)$m['summary']['object_count'];
        $stacks=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $byIndex=[];
        foreach($stacks as $s)$byIndex[(int)$s['object_index']]=$s;
        $out=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original Unrealfile mesh');
        try{
            foreach($m['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Mesh')!==0)continue;
                $stack=$byIndex[(int)$o['index']]??[];
                $record=['object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'UMesh','status'=>'incomplete_original_mesh_header',
                    'runtime_mesh_constructed'=>false];
                $remaining=(int)($stack['class_member_bytes_remaining']??0);
                if(($stack['status']??null)!=='native_stack_prefix_decoded'
                    ||$remaining<self::MAY_NATIVE_BYTES){
                    if($strict)throw new RuntimeException('Original UMesh source member header incomplete');
                    $out[]=$record;continue;
                }
                $lineage=CatalogUE1OriginalUnrealfilePropertySerializationGate::fromNativeHeaderLength(
                    $remaining,self::MAY_NATIVE_BYTES,self::JULY_NATIVE_BYTES,$sourceLineage
                );
                if($lineage===null){
                    if($strict)throw new RuntimeException('Original May/July UMesh header source layout not determined');
                    $record['status']='source_1997_mesh_lineage_required';
                    $out[]=$record;continue;
                }
                $may=$lineage===CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY;
                $need=$may?self::MAY_NATIVE_BYTES:self::JULY_NATIVE_BYTES;
                if($remaining<$need){
                    if($strict)throw new RuntimeException('UMesh selected source header truncated');
                    $out[]=$record;continue;
                }
                $off=(int)$o['file_header_offset']+(int)$stack['serialized_prefix_bytes'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('UMesh header seek failed');
                $data=fread($fp,$need);
                if(!is_string($data)||strlen($data)!==$need)
                    throw new RuntimeException('UMesh header native bytes truncated');
                $pos=0;
                $bound=CatalogUE1OriginalUnrealfileArchiveMath::bound($data,$pos);
                $refs=array_values(unpack('V8',substr($data,$pos,32)));
                $pos+=32;
                foreach($refs as $ref)if($ref>$numObjects)
                    throw new RuntimeException('UMesh original child object ref exceeds ResMap');
                $nums=array_values(unpack('V4',substr($data,$pos,16)));
                $pos+=16;
                $toSigned=static fn(int $x):int=>$x>=0x80000000?$x-0x100000000:$x;
                $frameVerts=$toSigned((int)$nums[0]);$animFrames=$toSigned((int)$nums[1]);
                if($frameVerts<0||$animFrames<0)
                    throw new RuntimeException('Original UMesh negative frame or animation count');
                $scale=array_values(unpack('g3',substr($data,$pos,12)));$pos+=12;
                $origin=array_values(unpack('g3',substr($data,$pos,12)));$pos+=12;
                $rotation=CatalogUE1OriginalUnrealfileArchiveMath::rotation($data,$pos,$may);
                $edit=array_values(unpack('V2',substr($data,$pos,8)));$pos+=8;
                if($pos!==$need)throw new RuntimeException('UMesh native source wire byte accounting mismatch');
                $extra=$remaining-$need;
                if($strict&&($extra!==0||(int)$o['file_data_size']!==0))
                    throw new RuntimeException('Original UMesh has unexpected additional header/data bytes');
                $names=['Verts','Tris','AnimSeqs','Connects','Bounds','VertLinks','Textures','Notifys'];
                $refsNamed=[];
                foreach($names as $j=>$name)$refsNamed[$name]=[
                    'raw_one_based_ref'=>(int)$refs[$j],
                    'object_index'=>$refs[$j]===0?null:$refs[$j]-1,
                ];
                $out[]=[
                    'object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'UMesh',
                    'status'=>($extra===0&&(int)$o['file_data_size']===0)
                        ?'native_mesh_header_decoded':'native_mesh_extra_serialized_bytes',
                    'local_bound'=>$bound,
                    'child_object_refs'=>$refsNamed,
                    'frame_vertices'=>$frameVerts,'animation_frames'=>$animFrames,
                    'and_flags'=>(int)$nums[2],'or_flags'=>(int)$nums[3],
                    'scale'=>$scale,'origin'=>$origin,'rotation_signed'=>$rotation,
                    'current_polygon'=>$toSigned((int)$edit[0]),
                    'current_vertex'=>$toSigned((int)$edit[1]),
                    'native_header_bytes'=>$need,
                    'source_lineage'=>$lineage,
                    'source_lineage_inferred_from_native_header_bytes'=>$sourceLineage===null,
                    'unconsumed_header_bytes'=>$extra,
                    'unconsumed_data_bytes'=>(int)$o['file_data_size'],
                    'runtime_mesh_constructed'=>false,
                    'runtime_child_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','mesh_headers'=>$out,
            'runtime_child_references_resolved'=>false];
    }
}
