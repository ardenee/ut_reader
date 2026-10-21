<?php
/**
 * Original 1996 UBspNodes (RES_BspNodes=17) Win32 aligned fields.
 * v0.84a UnRes.h: 64-byte FBspNode, FZoneProperties(24), 64 zones.
 * UDatabase(84) + NumZones/NumUniquePlanes/Unused[2](16) +
 * 4 bytes alignment + 64*24 zone bytes = 1640-byte native header.
 * ZoneMask and zone connectivity/visibility remain unsigned split words.
 * Runtime zone actor and mesh references are never manufactured.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourceBspNodesReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $hlist=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open UBspNodes');
        try {
            foreach($db as $i=>$d){
                if((int)$d['resource_type']!==17)continue;
                $h=$hlist[$i];
                $row=['export_index'=>(int)$h['export_index'],
                    'status'=>'incomplete_native_bsp_nodes_header',
                    'runtime_zone_actors_resolved'=>false];
                if(($d['status']??'')!=='native_database_num_max_decoded'
                    ||(int)$h['serialized_length']<1640){
                    if($strict)throw new RuntimeException('UBspNodes native header shorter than Win32 source 1640');
                    $out[]=$row;continue;
                }
                $num=(int)$d['database_count'];$len=(int)$h['file_data_size'];
                if($num>intdiv(PHP_INT_MAX,64)||$num*64!==$len)
                    throw new RuntimeException('UBspNodes records differ from Num*sizeof(FBspNode)');
                if(fseek($fp,(int)$h['serialized_offset']+84,SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UBspNodes zones');
                $hdr=fread($fp,1556);
                if(!is_string($hdr)||strlen($hdr)!==1556)
                    throw new RuntimeException('Truncated source UBspNodes zones header');
                $stats=array_values(unpack('V4',substr($hdr,0,16)));
                if($stats[0]>64)throw new RuntimeException('UBspNodes NumZones exceeds source MAX_ZONES');
                $zones=[];
                for($j=0;$j<64;$j++){
                    $z=substr($hdr,20+$j*24,24);
                    $zvals=unpack('Vactor/Vunused/Vclo/Vchi/Vvlo/Vvhi',$z);
                    $actor=(int)$zvals['actor'];
                    $zones[]=[
                        'zone_index'=>$j,
                        'zone_actor_raw_index'=>$actor>=0x80000000?$actor-0x100000000:$actor,
                        'connectivity_unsigned_words'=>[(int)$zvals['clo'],(int)$zvals['chi']],
                        'visibility_unsigned_words'=>[(int)$zvals['vlo'],(int)$zvals['vhi']],
                    ];
                }
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek native FBspNode records');
                $nodes=[];
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                for($j=0;$j<$num;$j++){
                    $bytes=fread($fp,64);
                    if(!is_string($bytes)||strlen($bytes)!==64)
                        throw new RuntimeException('Truncated original FBspNode');
                    $xyz=array_values(unpack('g3',substr($bytes,0,12)));
                    $zoneMask=array_values(unpack('V2',substr($bytes,16,8)));
                    $fields=array_values(unpack('V7',substr($bytes,24,28)));
                    $zonesBytes=array_values(unpack('C4',substr($bytes,52,4)));
                    $dyn=array_values(unpack('V2',substr($bytes,56,8)));
                    $nodes[]=[
                        'index'=>$j,'plane_vector'=>$xyz,
                        'zone_mask_unsigned_words'=>$zoneMask,
                        'node_flags'=>$fields[0],'vertex_pool_index'=>$signed($fields[1]),
                        'surface_index'=>$signed($fields[2]),
                        'back_index'=>$signed($fields[3]),'front_index'=>$signed($fields[4]),
                        'coplanar_index'=>$signed($fields[5]),'bound_index'=>$signed($fields[6]),
                        'zone_indices'=>[$zonesBytes[0],$zonesBytes[1]],
                        'vertex_count'=>$zonesBytes[2],'reserved_byte'=>$zonesBytes[3],
                        'dynamic_in_memory_indices'=>array_map($signed,$dyn),
                    ];
                }
                $out[]=[
                    'export_index'=>(int)$h['export_index'],
                    'status'=>'native_bsp_nodes_decoded',
                    'record_count'=>$num,'record_capacity'=>(int)$d['database_capacity'],
                    'record_size_bytes'=>64,'header_source_bytes'=>1640,
                    'additional_header_bytes'=>(int)$h['serialized_length']-1640,
                    'num_zones'=>$stats[0],'num_unique_planes'=>$stats[1],
                    'zones'=>$zones,'nodes'=>$nodes,
                    'runtime_zone_actors_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'bsp_node_records'=>$out,'runtime_references_resolved'=>false];
    }
}
