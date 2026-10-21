<?php
/**
 * Unreal original 1996 v9/v10 UPolys source-native FPoly records.
 * Epic v0.84a UnRes.h::FPoly, UnFPoly.cpp::UPolys::Register;
 * FPoly=352 bytes under /Zp4: 20 FVector (16 bytes each), flags,
 * raw native model/texture pointers, two FName WORDs, three INDEXes
 * and four BYTEs. Size independently MSVC static_assert verified.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourcePolysReader
{
    private const RECORD_BYTES=352;
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open source UPolys');
        try{
            foreach($db as $i=>$row){
                if((int)$row['resource_type']!==20)continue;
                $h=$headers[$i];
                $brief=['export_index'=>(int)$h['export_index'],
                    'status'=>'incomplete_source_polys_header','runtime_references_resolved'=>false];
                if(($row['status']??null)!=='native_database_num_max_decoded'
                    ||(int)$h['serialized_length']<84){
                    if($strict)throw new RuntimeException('UPolys header shorter than UDatabase base');
                    $out[]=$brief;continue;
                }
                $num=(int)$row['database_count'];$max=(int)$row['database_capacity'];
                if($num>100000 || $max>intdiv(PHP_INT_MAX,self::RECORD_BYTES)
                   || $max*self::RECORD_BYTES!==(int)$h['file_data_size'])
                    throw new RuntimeException('UPolys Num/Max versus FPoly native size mismatch');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek FPoly database');
                $entries=[];
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                $vector=static function(string $bytes,int $offset):array{
                    $f=array_values(unpack('g3',substr($bytes,$offset,12)));
                    return ['xyz'=>$f,
                        'w_or_flags_raw_word'=>(int)unpack('V',substr($bytes,$offset+12,4))[1]];
                };
                for($j=0;$j<$num;$j++){
                    $bytes=fread($fp,self::RECORD_BYTES);
                    if(!is_string($bytes)||strlen($bytes)!==self::RECORD_BYTES)
                        throw new RuntimeException('Truncated source native FPoly record');
                    $verts=[];for($k=0;$k<16;$k++)$verts[]=$vector($bytes,64+$k*16);
                    $words=unpack('Vflags/Vbrush/Vtexture',substr($bytes,320,12));
                    $names=unpack('vgroup/vitem',substr($bytes,332,4));
                    $index=unpack('Vvertices/Vlink/Vpoly',substr($bytes,336,12));
                    $count=$signed((int)$index['vertices']);
                    if($count<0||$count>16)
                        throw new RuntimeException('FPoly exceeds source MAX_FPOLY_VERTICES 16');
                    $entries[]=[
                        'index'=>$j,'base'=>$vector($bytes,0),
                        'normal'=>$vector($bytes,16),
                        'texture_u'=>$vector($bytes,32),
                        'texture_v'=>$vector($bytes,48),
                        'active_vertices'=>array_slice($verts,0,$count),
                        'serialized_vertex_capacity'=>16,
                        'poly_flags'=>(int)$words['flags'],
                        'brush_reference_raw_word'=>(int)$words['brush'],
                        'texture_reference_raw_word'=>(int)$words['texture'],
                        'group_name_index_raw_word'=>(int)$names['group'],
                        'item_name_index_raw_word'=>(int)$names['item'],
                        'vertex_count'=>$count,
                        'link_index'=>$signed((int)$index['link']),
                        'brush_poly_index'=>$signed((int)$index['poly']),
                        'pan_u'=>ord($bytes[348]),'pan_v'=>ord($bytes[349]),
                        'zones'=>[ord($bytes[350]),ord($bytes[351])],
                    ];
                }
                $out[]=['export_index'=>(int)$h['export_index'],
                    'native_class'=>'UPolys','status'=>'native_polys_records_decoded',
                    'record_count'=>$num,'record_capacity'=>$max,
                    'record_stride'=>self::RECORD_BYTES,
                    'source_header_bytes'=>84,
                    'records'=>$entries,
                    'runtime_references_resolved'=>false];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','polys_resources'=>$out,
            'runtime_references_resolved'=>false];
    }
}
