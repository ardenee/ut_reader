<?php
/**
 * 1996 UPolys native FPoly records, v9/v10 (RES_Polys=20).
 * Source: Epic v0.84a UnRes.h::FPoly / UnFPoly.cpp::QueryDataReferences.
 * Win32 FPoly = FVector[4] (48) + FVector Vertex[16] (192) +
 * DWORD PolyFlags(4), two 32-bit UModel/UTexture pointer words(8), FName[2](4),
 * INDEX NumVertices/iLink/iBrushPoly(12), 4 bytes pan/zone = 272.
 * Resource/name words remain unresolved until Epic-style callbacks.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
final class CatalogUE1OriginalResourcePolysReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $hdrs=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot read original UPolys package');
        try{
            foreach($db as $i=>$row){
                if((int)$row['resource_type']!==20)continue;
                $h=$hdrs[$i];$entry=['export_index'=>(int)$h['export_index'],
                    'status'=>'incomplete_native_polygon_header',
                    'runtime_reference_fixups_evaluated'=>false];
                if(($row['status']??null)!=='native_database_num_max_decoded'
                   ||(int)$h['serialized_length']<84){
                    if($strict)throw new RuntimeException('UPolys lacks source UDatabase header');
                    $out[]=$entry;continue;
                }
                $num=(int)$row['database_count'];
                if($num>intdiv(PHP_INT_MAX,272)||
                    (int)$h['file_data_size']!==$num*272)
                    throw new RuntimeException('UPolys data size differs from Num*sizeof(FPoly)');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek FPoly data');
                $polys=[];
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                for($j=0;$j<$num;$j++){
                    $bytes=fread($fp,272);
                    if(!is_string($bytes)||strlen($bytes)!==272)
                        throw new RuntimeException('Truncated native FPoly');
                    $base=array_values(unpack('g3',substr($bytes,0,12)));
                    $normal=array_values(unpack('g3',substr($bytes,12,12)));
                    $u=array_values(unpack('g3',substr($bytes,24,12)));
                    $v=array_values(unpack('g3',substr($bytes,36,12)));
                    $vertices=[];for($k=0;$k<16;$k++)
                        $vertices[]=array_values(unpack('g3',substr($bytes,48+$k*12,12)));
                    $w=array_values(unpack('V3',substr($bytes,240,12)));
                    $names=array_values(unpack('v2',substr($bytes,252,4)));
                    $indexes=array_values(unpack('V3',substr($bytes,256,12)));
                    if($indexes[0]>16)
                        throw new RuntimeException('FPoly NumVertices exceeds MAX_FPOLY_VERTICES');
                    $tail=array_values(unpack('C4',substr($bytes,268,4)));
                    $polys[]=[
                        'index'=>$j,'base'=>$base,'normal'=>$normal,
                        'texture_u'=>$u,'texture_v'=>$v,
                        'vertices'=>array_slice($vertices,0,$indexes[0]),
                        'poly_flags'=>$w[0],
                        'brush_raw_reference'=>$w[1],
                        'texture_raw_reference'=>$w[2],
                        'group_name_raw_word'=>$names[0],
                        'item_name_raw_word'=>$names[1],
                        'num_vertices'=>$indexes[0],
                        'linked_polygon_index'=>$signed($indexes[1]),
                        'source_brush_polygon_index'=>$signed($indexes[2]),
                        'pan_u'=>$tail[0],'pan_v'=>$tail[1],
                        'zones'=>[$tail[2],$tail[3]],
                        'runtime_reference_fixups_evaluated'=>false,
                    ];
                }
                $out[]=['export_index'=>(int)$h['export_index'],
                    'status'=>'native_polygon_records_decoded',
                    'record_count'=>$num,'record_capacity'=>(int)$row['database_capacity'],
                    'record_size_bytes'=>272,
                    'additional_header_bytes'=>(int)$h['serialized_length']-84,
                    'polygon_records'=>$polys,
                    'runtime_reference_fixups_evaluated'=>false];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'polygon_resources'=>$out,'runtime_references_resolved'=>false];
    }
}
