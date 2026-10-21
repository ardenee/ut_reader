<?php
/**
 * Original Unreal 1996 v9/v10 UMeshMap (RES_MeshMap=32).
 * Source: v0.84a Src/Inc/UnRes.h, Src/Engine/UnMesh.cpp.
 * 32-bit UResource 76B + UMesh* 4B + MaxTextures DWORD 4B
 * + AndFlags DWORD 4B + OrFlags DWORD 4B + Scale FVector 16B = 108B.
 * QueryMinSize = MaxTextures * sizeof(UTexture*) = count*4.
 * Pointer words are source-serialized identifiers, not live pointers.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceMeshMapReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $records=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original UMeshMap resource');
        try {
            foreach($headers as $h){
                if((int)$h['resource_type']!==32)continue;
                $row=['export_index'=>(int)$h['export_index'],
                    'source_class'=>'UMeshMap',
                    'status'=>'incomplete_source_meshmap_header',
                    'runtime_mesh_or_texture_references_resolved'=>false];
                if((int)$h['serialized_length']<108){
                    if($strict)throw new RuntimeException('UMeshMap native header must be at least 108 bytes');
                    $records[]=$row;continue;
                }
                $hOff=(int)$h['serialized_offset'];
                if(fseek($fp,$hOff+76,SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UMeshMap fields');
                $fields=fread($fp,32);
                if(!is_string($fields)||strlen($fields)!==32)
                    throw new RuntimeException('Truncated UMeshMap native header fields');
                $v=unpack('Vmesh/Vmax/Vand/Vor/gx/gy/gz/Vw',$fields);
                $count=(int)$v['max'];$dataSize=(int)$h['file_data_size'];
                if($count>intdiv(PHP_INT_MAX,4)||$count*4!==$dataSize)
                    throw new RuntimeException('UMeshMap QueryMinSize mismatch for MaxTextures');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UMeshMap texture array');
                $textures=[];
                for($i=0;$i<$count;$i++){
                    $buf=fread($fp,4);
                    if(!is_string($buf)||strlen($buf)!==4)
                        throw new RuntimeException('Truncated UMeshMap texture word');
                    $textures[]=(int)unpack('V',$buf)[1];
                }
                $records[]=[
                    'export_index'=>(int)$h['export_index'],
                    'source_class'=>'UMeshMap','status'=>'native_meshmap_decoded',
                    'mesh_reference_raw_word'=>(int)$v['mesh'],
                    'texture_count'=>$count,
                    'and_flags'=>(int)$v['and'],'or_flags'=>(int)$v['or'],
                    'scale'=>['x'=>(float)$v['x'],'y'=>(float)$v['y'],'z'=>(float)$v['z'],
                        'w_or_flags_raw_word'=>(int)$v['w']],
                    'texture_reference_raw_words'=>$textures,
                    'serialized_header_bytes'=>108,
                    'additional_header_bytes'=>(int)$h['serialized_length']-108,
                    'serialized_texture_bytes'=>$dataSize,
                    'runtime_mesh_or_texture_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','meshmap_records'=>$records,
            'runtime_references_resolved'=>false];
    }
}
