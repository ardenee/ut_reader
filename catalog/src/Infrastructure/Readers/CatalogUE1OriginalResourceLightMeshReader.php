<?php
/**
 * Original UE1 1996 ULightMesh native header and FLightMeshIndex records.
 * Epic v0.84a UnRes.h: 76-byte UResource + two INT32 (84 header);
 * FLightMeshIndex is 8 INT32, 16 INDEX, 4 BYTE = 100 bytes /Zp4.
 * UnModel.cpp: ULightMesh::QuerySize() returns NumDataBytes.
 * Raw lighting samples belong to O07; actor references remain unresolved.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceLightMeshReader
{
    private const INDEX_BYTES=100;
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $result=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original ULightMesh');
        try{
            foreach($headers as $h){
                if((int)$h['resource_type']!==19)continue;
                $entry=['export_index'=>(int)$h['export_index'],
                    'status'=>'incomplete_source_lightmesh_header',
                    'runtime_actor_references_resolved'=>false];
                if((int)$h['serialized_length']<84){
                    if($strict)throw new RuntimeException('ULightMesh source header shorter than 84 bytes');
                    $result[]=$entry;continue;
                }
                if(fseek($fp,(int)$h['serialized_offset']+76,SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek ULightMesh fields');
                $bytes=fread($fp,8);
                if(!is_string($bytes)||strlen($bytes)!==8)
                    throw new RuntimeException('Truncated ULightMesh header');
                $counts=array_values(unpack('V2',$bytes));
                [$numIndices,$numDataBytes]=$counts;
                if($numIndices>250000||$numDataBytes!==(int)$h['file_data_size']
                    ||$numIndices>intdiv($numDataBytes,self::INDEX_BYTES))
                    throw new RuntimeException('ULightMesh NumIndices/NumDataBytes invalid');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek light mesh indexes');
                $indices=[];$signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                for($i=0;$i<$numIndices;$i++){
                    $item=fread($fp,self::INDEX_BYTES);
                    if(!is_string($item)||strlen($item)!==self::INDEX_BYTES)
                        throw new RuntimeException('Truncated FLightMeshIndex');
                    $native=array_values(unpack('V8',substr($item,0,32)));
                    $actors=array_map($signed,array_values(unpack('V16',substr($item,32,64))));
                    $nStatic=$signed($native[6]);$nDynamic=$signed($native[7]);
                    if($nStatic<0||$nDynamic<0||$nStatic+$nDynamic>16)
                        throw new RuntimeException('FLightMeshIndex exceeds MAX_POLY_LIGHTS');
                    if($native[0]>$numDataBytes)throw new RuntimeException('FLightMeshIndex DataOffset outside payload');
                    $indices[]=[
                        'index'=>$i,
                        'data_offset'=>$signed($native[0]),
                        'texture_u_start'=>$signed($native[1]),
                        'texture_v_start'=>$signed($native[2]),
                        'mesh_u_size'=>$signed($native[3]),
                        'mesh_v_size'=>$signed($native[4]),
                        'mesh_spacing'=>$signed($native[5]),
                        'num_static_lights'=>$nStatic,
                        'num_dynamic_lights'=>$nDynamic,
                        'light_actor_indices_raw'=>$actors,
                        'mesh_shift'=>ord($item[96]),
                        'mesh_u_bits'=>ord($item[97]),
                        'mesh_v_bits'=>ord($item[98]),
                        'unused_byte'=>ord($item[99]),
                        'runtime_actor_references_resolved'=>false,
                    ];
                }
                $result[]=[
                    'export_index'=>(int)$h['export_index'],
                    'status'=>'native_lightmesh_indices_decoded',
                    'native_class'=>'ULightMesh',
                    'index_count'=>$numIndices,'data_bytes'=>$numDataBytes,
                    'index_record_stride'=>self::INDEX_BYTES,
                    'index_region_bytes'=>$numIndices*self::INDEX_BYTES,
                    'remaining_light_samples_bytes'=>$numDataBytes-$numIndices*self::INDEX_BYTES,
                    'indexes'=>$indices,
                    'runtime_actor_references_resolved'=>false,
                    'lighting_samples_decoded'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','light_meshes'=>$result,
            'runtime_references_resolved'=>false,'bulk_samples_decoded'=>false];
    }
}
