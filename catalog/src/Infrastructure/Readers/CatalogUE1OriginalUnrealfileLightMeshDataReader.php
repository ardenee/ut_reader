<?php
/**
 * Epic v0.867 Src/Inc/UnObj.h FLightMeshIndex::operator<<
 * (near lines 929-944), ULightMesh::SerializeHeader.
 * Each record serializes eight signed 32-bit words, three BYTEs and
 * (NumStaticLights + NumDynamicLights) one-based actor references.
 * InternalByte/InternalInt and unused light pointers are NOT serialized.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileLightMeshDataReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $special=CatalogUE1OriginalUnrealfileSpecialHeadersReader::read($path,false);
        $by=[];foreach($special['special_headers'] as $h)
            $by[(int)$h['object_index']]=$h;
        $objectCount=(int)$meta['summary']['object_count'];
        $out=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original ULightMesh archive');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'LightMesh')!==0)
                    continue;
                $source=$by[(int)$o['index']]??[];
                $row=['object_index'=>(int)$o['index'],'source_class'=>'ULightMesh',
                    'status'=>'native_lightmesh_header_unavailable',
                    'runtime_lights_resolved'=>false];
                if(($source['status']??'')!=='native_special_header_decoded'){
                    if($strict)throw new RuntimeException('ULightMesh source header is incomplete');
                    $out[]=$row;continue;
                }
                $num=(int)$source['record_count'];
                $bytes=(int)$o['file_data_size'];
                if($num>100000||$bytes>67108864||$bytes<$num*35)
                    throw new RuntimeException('ULightMesh records cannot fit in source archive');
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek ULightMesh records');
                $raw='';while(strlen($raw)<$bytes){
                    $part=fread($fp,min(65536,$bytes-strlen($raw)));
                    if(!is_string($part)||$part==='')throw new RuntimeException('Truncated ULightMesh stream');
                    $raw.=$part;
                }
                $cursor=0;
                $read=static function(int $n)use(&$cursor,$raw):string{
                    if($n<0||$n>strlen($raw)-$cursor)
                        throw new RuntimeException('ULightMesh FLightMeshIndex truncated');
                    $s=substr($raw,$cursor,$n);$cursor+=$n;return $s;
                };
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                $records=[];
                for($i=0;$i<$num;$i++){
                    $v=array_values(unpack('V8',$read(32)));
                    $flags=array_values(unpack('C3',$read(3)));
                    $static=(int)$v[6];$dynamic=(int)$v[7];
                    if($static>16||$dynamic>16||$static+$dynamic>16)
                        throw new RuntimeException('FLightMeshIndex exceeds MAX_POLY_LIGHTS');
                    $refs=[];
                    for($j=0;$j<$static+$dynamic;$j++){
                        $ref=(int)unpack('V',$read(4))[1];
                        if($ref>$objectCount)
                            throw new RuntimeException('FLightMeshIndex actor reference exceeds ResMap');
                        $refs[]=$ref;
                    }
                    $records[]=[
                        'index'=>$i,'data_offset'=>$signed($v[0]),
                        'texture_u_start'=>$signed($v[1]),'texture_v_start'=>$signed($v[2]),
                        'mesh_u_size'=>$signed($v[3]),'mesh_v_size'=>$signed($v[4]),
                        'mesh_spacing'=>$signed($v[5]),
                        'num_static_lights'=>$static,'num_dynamic_lights'=>$dynamic,
                        'mesh_shift'=>$flags[0],'mesh_u_bits'=>$flags[1],
                        'mesh_v_bits'=>$flags[2],'actor_refs_raw_one_based'=>$refs
                    ];
                }
                if($cursor!==$bytes)
                    throw new RuntimeException('ULightMeshIndex archive has unconsumed data bytes');
                $out[]=array_merge($row,[
                    'status'=>'native_lightmesh_indices_decoded',
                    'record_count'=>$num,'record_capacity'=>(int)$source['record_capacity'],
                    'bits_ref_raw_one_based'=>$source['bits_raw_one_based_ref'],
                    'records'=>$records,'serialized_data_bytes'=>$cursor,
                    'runtime_lights_resolved'=>false,'lightmap_bits_interpreted'=>false
                ]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','lightmesh_records'=>$out,
            'runtime_lights_resolved'=>false,'lightmap_bits_interpreted'=>false];
    }
}
