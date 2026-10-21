<?php
/**
 * Original 1996 ULightMesh (RES_LightMesh=19) serialized layout.
 * Epic v0.84a UnRes.h::FLightMeshIndex, UnModel.cpp::QueryMinSize.
 * Win32 ULightMesh header = UResource 76 + two INT32 counts = 84.
 * FLightMeshIndex = eight INT32 (32), 16 actor INDEX words (64),
 * MeshShift/UBits/VBits/unused bytes (4): 100 bytes per index.
 * Remaining NumDataBytes payload is raw light samples (O07).
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceLightMeshReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original light mesh resource');
        try{
            foreach($headers as $h){
                if((int)$h['resource_type']!==19)continue;
                $item=['export_index'=>(int)$h['export_index'],
                    'status'=>'incomplete_native_lightmesh_header',
                    'sample_bytes_decoded'=>false];
                if((int)$h['serialized_length']<84){
                    if($strict)throw new RuntimeException('ULightMesh native header must be at least 84 bytes');
                    $out[]=$item;continue;
                }
                if(fseek($fp,(int)$h['serialized_offset']+76,SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek ULightMesh counters');
                $c=fread($fp,8);
                if(!is_string($c)||strlen($c)!==8)
                    throw new RuntimeException('Truncated ULightMesh header');
                $v=array_values(unpack('V2',$c));$count=$v[0];$length=$v[1];
                if($count>intdiv($length,100)
                    ||$length!==(int)$h['file_data_size'])
                    throw new RuntimeException('ULightMesh index/data size differs from QuerySize');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek light mesh index records');
                $records=[];$signed=static fn(int $n):int=>$n>=0x80000000?$n-0x100000000:$n;
                for($i=0;$i<$count;$i++){
                    $raw=fread($fp,100);
                    if(!is_string($raw)||strlen($raw)!==100)
                        throw new RuntimeException('Truncated FLightMeshIndex');
                    $head=array_values(unpack('V8',substr($raw,0,32)));
                    $actor=array_values(unpack('V16',substr($raw,32,64)));
                    $flags=array_values(unpack('C4',substr($raw,96,4)));
                    if($head[0]>$length)
                        throw new RuntimeException('FLightMeshIndex DataOffset exceeds NumDataBytes');
                    $records[]=[
                        'index'=>$i,'data_offset'=>$signed($head[0]),
                        'texture_u_start'=>$signed($head[1]),
                        'texture_v_start'=>$signed($head[2]),
                        'mesh_u_size'=>$signed($head[3]),
                        'mesh_v_size'=>$signed($head[4]),
                        'mesh_spacing'=>$signed($head[5]),
                        'num_static_lights'=>$signed($head[6]),
                        'num_dynamic_lights'=>$signed($head[7]),
                        'light_actor_raw_indices'=>array_map($signed,$actor),
                        'mesh_shift'=>$flags[0],
                        'mesh_u_bits'=>$flags[1],
                        'mesh_v_bits'=>$flags[2],
                        'reserved_byte'=>$flags[3],
                        'runtime_actor_references_resolved'=>false,
                    ];
                }
                $out[]=[
                    'export_index'=>(int)$h['export_index'],
                    'status'=>'native_lightmesh_index_records_decoded',
                    'index_count'=>$count,'data_bytes'=>$length,
                    'index_record_stride'=>100,'index_bytes'=>$count*100,
                    'uninterpreted_sample_bytes'=>$length-$count*100,
                    'index_records'=>$records,
                    'additional_header_bytes'=>(int)$h['serialized_length']-84,
                    'sample_bytes_decoded'=>false,
                    'runtime_actor_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','lightmesh_records'=>$out,
            'runtime_references_resolved'=>false];
    }
}
