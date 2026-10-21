<?php
/**
 * Original Unreal 1996 UDatabase-derived UBspSurfs RES_BspSurfs=18.
 * Source v0.84a Src/Inc/UnRes.h: FBspSurf; Src/Engine/UnModel.cpp.
 * Source FBspSurf on Win32 MSVC = 48 bytes: UTexture* and UModel*
 * (4+4), PolyFlags DWORD, seven 32-bit INDEX fields (six before
 * PanU/PanV, one iActor after two-byte alignment), two BYTE pans,
 * two transient SWORD span bounds.
 * QueryDataReferences fixes Texture and Brush, never direct pointers.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceBspSurfsReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $db=CatalogUE1OriginalResourceDatabaseReader::read($path,$strict)['database_headers'];
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original UBspSurfs package');
        try {
            foreach($db as $i=>$item){
                if((int)$item['resource_type']!==18)continue;
                $h=$headers[$i];
                $row=['export_index'=>(int)$h['export_index'],
                    'status'=>'incomplete_native_bsp_surfaces_header',
                    'runtime_resource_references_resolved'=>false];
                if(($item['status']??'')!=='native_database_num_max_decoded'
                   ||(int)$h['serialized_length']<84){
                    if($strict)throw new RuntimeException('UBspSurfs lacks native UDatabase header');
                    $out[]=$row;continue;
                }
                $num=(int)$item['database_count'];$bytes=(int)$h['file_data_size'];
                if($num>intdiv(PHP_INT_MAX,48)||$num*48!==$bytes)
                    throw new RuntimeException('UBspSurfs QueryMinSize does not match Num * sizeof(FBspSurf)');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek original FBspSurf records');
                $surfs=[];
                $signed=static fn(int $n):int=>$n>=0x80000000?$n-0x100000000:$n;
                for($j=0;$j<$num;$j++){
                    $raw=fread($fp,48);
                    if(!is_string($raw)||strlen($raw)!==48)
                        throw new RuntimeException('Truncated FBspSurf native record');
                    $ints=array_values(unpack('V9',substr($raw,0,36)));
                    $pan=array_values(unpack('C2',substr($raw,36,2)));
                    $actor=(int)unpack('V',substr($raw,40,4))[1];
                    $last=array_values(unpack('v2',substr($raw,44,4)));
                    $short=static fn(int $n):int=>$n>=0x8000?$n-0x10000:$n;
                    $surfs[]=[
                        'index'=>$j,'texture_reference_raw_word'=>$ints[0],
                        'brush_reference_raw_word'=>$ints[1],
                        'poly_flags'=>$ints[2],
                        'p_base'=>$signed($ints[3]),'v_normal'=>$signed($ints[4]),
                        'v_texture_u'=>$signed($ints[5]),'v_texture_v'=>$signed($ints[6]),
                        'i_light_mesh'=>$signed($ints[7]),'i_brush_poly'=>$signed($ints[8]),
                        'i_actor'=>$signed($actor),
                        'pan_u'=>$pan[0],'pan_v'=>$pan[1],
                        'last_start_y'=>$short($last[0]),'last_end_y'=>$short($last[1]),
                        'alignment_bytes_hex'=>strtoupper(bin2hex(substr($raw,38,2))),
                        'runtime_resource_references_resolved'=>false,
                    ];
                }
                $out[]=['export_index'=>(int)$h['export_index'],
                    'status'=>'native_bsp_surfaces_decoded',
                    'record_count'=>$num,'record_capacity'=>(int)$item['database_capacity'],
                    'serialized_record_bytes'=>48,
                    'additional_header_bytes'=>(int)$h['serialized_length']-84,
                    'surface_records'=>$surfs,
                    'runtime_resource_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource',
            'bsp_surface_records'=>$out,'runtime_references_resolved'=>false];
    }
}
