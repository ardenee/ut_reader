<?php
/**
 * Original Unrealfile v22/v23 UMesh::SerializeHeader (v0.867).
 * UnPrim.h::UPrimitive writes LocalBound as FBoundingVolume:
 * FVector Min+Max (24B), INT IsValid (4B), FPlane Sphere (16B).
 * UnMesh.h::UMesh then writes eight 1-based UObject refs (32B),
 * FrameVerts/AnimFrames/AndFlags/OrFlags (16B),
 * Scale and Origin FVector (24B), RotOrigin FRotation (12B),
 * CurPoly/CurVertex (8B). Source-native member total = 136 bytes.
 * Header is preceded by FExecStackMain. UMesh owns no direct data bytes;
 * mesh vertex/triangle buffers are child UDatabase objects by reference.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileMeshHeaderReader
{
    private const NATIVE_BYTES=136;
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
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
                if(($stack['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($stack['class_member_bytes_remaining']??0)<self::NATIVE_BYTES){
                    if($strict)throw new RuntimeException('Original UMesh source member header incomplete');
                    $out[]=$record;continue;
                }
                $off=(int)$o['file_header_offset']+(int)$stack['serialized_prefix_bytes'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('UMesh header seek failed');
                $data=fread($fp,self::NATIVE_BYTES);
                if(!is_string($data)||strlen($data)!==self::NATIVE_BYTES)
                    throw new RuntimeException('UMesh header native bytes truncated');
                $bMin=array_values(unpack('g3',substr($data,0,12)));
                $bMax=array_values(unpack('g3',substr($data,12,12)));
                $isValid=(int)unpack('V',substr($data,24,4))[1];
                if($isValid!==0&&$isValid!==1)
                    throw new RuntimeException('Original UPrimitive bounding box IsValid must be 0 or 1');
                $sphere=array_values(unpack('g4',substr($data,28,16)));
                $refs=array_values(unpack('V8',substr($data,44,32)));
                foreach($refs as $ref)if($ref>$numObjects)
                    throw new RuntimeException('UMesh original child object ref exceeds ResMap');
                $nums=array_values(unpack('V4',substr($data,76,16)));
                $toSigned=static fn(int $x):int=>$x>=0x80000000?$x-0x100000000:$x;
                $frameVerts=$toSigned((int)$nums[0]);$animFrames=$toSigned((int)$nums[1]);
                if($frameVerts<0||$animFrames<0)
                    throw new RuntimeException('Original UMesh negative frame or animation count');
                $scale=array_values(unpack('g3',substr($data,92,12)));
                $origin=array_values(unpack('g3',substr($data,104,12)));
                $angles=array_values(unpack('V3',substr($data,116,12)));
                $rotation=array_map($toSigned,$angles);
                $edit=array_values(unpack('V2',substr($data,128,8)));
                $extra=(int)$stack['class_member_bytes_remaining']-self::NATIVE_BYTES;
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
                    'local_bound'=>['min'=>$bMin,'max'=>$bMax,'is_valid'=>$isValid,
                        'sphere_center'=>array_slice($sphere,0,3),
                        'sphere_radius'=>$sphere[3]],
                    'child_object_refs'=>$refsNamed,
                    'frame_vertices'=>$frameVerts,'animation_frames'=>$animFrames,
                    'and_flags'=>(int)$nums[2],'or_flags'=>(int)$nums[3],
                    'scale'=>$scale,'origin'=>$origin,'rotation_signed'=>$rotation,
                    'current_polygon'=>$toSigned((int)$edit[0]),
                    'current_vertex'=>$toSigned((int)$edit[1]),
                    'native_header_bytes'=>self::NATIVE_BYTES,
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
